"""Smart Image Finder v3.2 — CPU CLIP + focused multi-crop + user feedback learning.

Backward compatible with the v3 CLIP .npz index and POST /search contract.
Use mode=single to reproduce v3 search, mode=multi (default) for multiple crops.
Only uploaded queries are cropped; the existing catalog embeddings are reused.

User corrections are stored as CLIP query prototypes in ai-feedback.npz. During later
searches, visually similar corrected examples can boost the catalog image selected by
users without rebuilding the main catalog index.
"""
import argparse
import asyncio
import io
import json
import os
import warnings
from pathlib import Path

import numpy as np
from PIL import Image, ImageOps
from fastapi import FastAPI, File, Form, HTTPException, UploadFile

MODEL = os.environ.get('IMAGE_AI_MODEL', 'ViT-B-32')
PRETRAINED = os.environ.get('IMAGE_AI_PRETRAINED', 'laion2b_s34b_b79k')
MAX_UPLOAD_BYTES = 10 * 1024 * 1024
FEEDBACK_MATCH_THRESHOLD = float(os.environ.get('IMAGE_AI_FEEDBACK_THRESHOLD', '0.78'))
FEEDBACK_MAX_BOOST = float(os.environ.get('IMAGE_AI_FEEDBACK_MAX_BOOST', '0.22'))
FEEDBACK_DUPLICATE_THRESHOLD = float(os.environ.get('IMAGE_AI_FEEDBACK_DUPLICATE_THRESHOLD', '0.985'))
FEEDBACK_MAX_EXAMPLES = max(100, int(os.environ.get('IMAGE_AI_FEEDBACK_MAX_EXAMPLES', '5000')))
Image.MAX_IMAGE_PIXELS = 20_000_000


def model_loader():
    import open_clip
    import torch

    torch.set_num_threads(max(1, int(os.environ.get('IMAGE_AI_THREADS', '4'))))
    model, _, preprocess = open_clip.create_model_and_transforms(
        MODEL, pretrained=PRETRAINED, device='cpu'
    )
    model.eval()
    return model, preprocess


def vector(image, model, preprocess):
    """Retained for backwards-compatible index building."""
    import torch

    image = ImageOps.exif_transpose(image).convert('RGB')
    with torch.inference_mode():
        features = model.encode_image(preprocess(image).unsqueeze(0))
        features = features / features.norm(dim=-1, keepdim=True).clamp(min=1e-12)
    return features.squeeze(0).float().cpu().numpy()


def build(manifest_path, output_path):
    """Unchanged index format: ids, vectors, model arrays (v3 index reusable)."""
    rows = json.loads(Path(manifest_path).read_text(encoding='utf-8'))
    model, preprocess = model_loader()
    ids, vectors = [], []
    for n, row in enumerate(rows, 1):
        try:
            with Image.open(row['path']) as im:
                vectors.append(vector(im, model, preprocess))
            ids.append(str(row['id']))
        except Exception as exc:
            print(f"SKIP {row.get('id')}: {exc}", flush=True)
        if n % 100 == 0:
            print(f"Encoded {n}/{len(rows)}", flush=True)
    if not vectors:
        raise RuntimeError('Tidak ada thumbnail valid dalam manifest')
    dest = Path(output_path)
    dest.parent.mkdir(parents=True, exist_ok=True)
    tmp = str(dest) + '.tmp.npz'
    np.savez_compressed(
        tmp, ids=np.array(ids), vectors=np.stack(vectors).astype('float32'),
        model=np.array([MODEL, PRETRAINED])
    )
    os.replace(tmp, dest)
    print(f"AI index saved: {len(ids)} images -> {dest}", flush=True)


def _box(image, coords):
    """Fractional (left, top, right, bottom) => cropped PIL image."""
    w, h = image.size
    left, top, right, bottom = coords
    rect = (round(w * left), round(h * top), round(w * right), round(h * bottom))
    return image.crop(rect)


def query_crops(image, mode='multi'):
    """Focus on the lower-center product while retaining a whole-photo comparison.

    For portrait package shots the label is often in the top 1/3 of the image.
    These fixed crop priors are heuristics, *not* trained object detection.
    """
    source = ImageOps.exif_transpose(image).convert('RGB')
    source.thumbnail((2048, 2048), Image.Resampling.LANCZOS)
    crops = [('full', source)]
    if mode == 'single':
        return crops

    w, h = source.size
    if h >= 1.20 * w:  # vertical phone photo / hanging packaged product
        regions = [
            ('object_mid', (0.04, 0.32, 0.96, 0.82)),
            ('object_lower', (0.05, 0.40, 0.95, 0.83)),
            ('object_tight', (0.10, 0.40, 0.94, 0.74)),
            ('medium_context', (0.01, 0.22, 0.99, 0.87)),
        ]
    else:  # square / landscape images of loose tools
        regions = [
            ('center_wide', (0.08, 0.08, 0.92, 0.92)),
            ('center', (0.18, 0.18, 0.82, 0.82)),
            ('lower_center', (0.10, 0.28, 0.90, 0.96)),
            ('upper_center', (0.10, 0.04, 0.90, 0.72)),
        ]
    for name, box in regions:
        crops.append((name, _box(source, box)))
    return crops


def query_vectors(crops, model, preprocess):
    """Encode all crops in one CPU batch instead of N independent calls."""
    import torch

    batch = torch.stack([preprocess(img) for _, img in crops])
    with torch.inference_mode():
        features = model.encode_image(batch)
        features = features / features.norm(dim=-1, keepdim=True).clamp(min=1e-12)
    return features.float().cpu().numpy()


def query_prototype(vectors):
    """One normalized embedding representing the whole multi-crop query."""
    prototype = np.mean(np.asarray(vectors, dtype=np.float32), axis=0)
    norm = float(np.linalg.norm(prototype))
    if not np.isfinite(norm) or norm <= 1e-12:
        raise ValueError('Invalid query embedding')
    return (prototype / norm).astype(np.float32)


class FeedbackStore:
    """Persistent user-labeled query prototypes used as a conservative reranking signal."""

    def __init__(self, path, embedding_dim):
        self.path = Path(path)
        self.embedding_dim = int(embedding_dim)
        self.ids = []
        self.vectors = np.empty((0, self.embedding_dim), dtype=np.float32)
        self.votes = np.empty((0,), dtype=np.int32)
        self.load()

    def load(self):
        if not self.path.is_file():
            return
        try:
            with np.load(self.path, allow_pickle=False) as data:
                ids = [str(v) for v in data['ids'].tolist()]
                vectors = np.asarray(data['vectors'], dtype=np.float32)
                votes = np.asarray(
                    data['votes'] if 'votes' in data.files else np.ones(len(ids)),
                    dtype=np.int32,
                )
            if vectors.ndim != 2 or vectors.shape[1] != self.embedding_dim:
                raise ValueError('embedding dimension mismatch')
            if len(ids) != len(vectors) or len(votes) != len(ids):
                raise ValueError('feedback array length mismatch')
            if not np.isfinite(vectors).all():
                raise ValueError('feedback contains invalid vectors')
            self.ids = ids[-FEEDBACK_MAX_EXAMPLES:]
            self.vectors = vectors[-FEEDBACK_MAX_EXAMPLES:].copy()
            self.votes = np.maximum(1, votes[-FEEDBACK_MAX_EXAMPLES:]).copy()
            print(f"Loaded {len(self.ids)} AI feedback examples from {self.path}", flush=True)
        except Exception as exc:
            print(f"Ignoring invalid AI feedback file {self.path}: {exc}", flush=True)

    def save(self):
        self.path.parent.mkdir(parents=True, exist_ok=True)
        tmp = str(self.path) + '.tmp.npz'
        np.savez_compressed(
            tmp,
            ids=np.array(self.ids),
            vectors=self.vectors.astype(np.float32),
            votes=self.votes.astype(np.int32),
            model=np.array([MODEL, PRETRAINED]),
        )
        os.replace(tmp, self.path)

    def add(self, selected_id, prototype):
        selected_id = str(selected_id)
        prototype = np.asarray(prototype, dtype=np.float32)
        if prototype.shape != (self.embedding_dim,) or not np.isfinite(prototype).all():
            raise ValueError('Invalid feedback embedding')

        # A near-identical query corrected to another product means the latest user
        # selection wins. Remove conflicting prototypes before recording this one.
        if len(self.ids):
            similarities = self.vectors @ prototype
            keep = np.ones(len(self.ids), dtype=bool)
            for idx, similarity in enumerate(similarities):
                if similarity >= FEEDBACK_DUPLICATE_THRESHOLD and self.ids[idx] != selected_id:
                    keep[idx] = False
            if not keep.all():
                self.ids = [value for i, value in enumerate(self.ids) if keep[i]]
                self.vectors = self.vectors[keep]
                self.votes = self.votes[keep]

        # Repeated confirmation of almost the same visual example increases its vote
        # count instead of growing the feedback file indefinitely.
        if len(self.ids):
            similarities = self.vectors @ prototype
            best_idx = int(np.argmax(similarities))
            if self.ids[best_idx] == selected_id and similarities[best_idx] >= FEEDBACK_DUPLICATE_THRESHOLD:
                old_votes = int(self.votes[best_idx])
                merged = (self.vectors[best_idx] * old_votes + prototype) / (old_votes + 1)
                merged /= max(float(np.linalg.norm(merged)), 1e-12)
                self.vectors[best_idx] = merged.astype(np.float32)
                self.votes[best_idx] = old_votes + 1
                self.save()
                return {'examples': len(self.ids), 'votes': int(self.votes[best_idx]), 'merged': True}

        self.ids.append(selected_id)
        self.vectors = np.vstack([self.vectors, prototype.reshape(1, -1)])
        self.votes = np.append(self.votes, np.int32(1))
        if len(self.ids) > FEEDBACK_MAX_EXAMPLES:
            overflow = len(self.ids) - FEEDBACK_MAX_EXAMPLES
            self.ids = self.ids[overflow:]
            self.vectors = self.vectors[overflow:]
            self.votes = self.votes[overflow:]
        self.save()
        return {'examples': len(self.ids), 'votes': 1, 'merged': False}

    def boost(self, scores, catalog_ids, prototype):
        info = {
            'used': False,
            'examples': len(self.ids),
            'matched_examples': 0,
            'best_similarity': None,
            'boosted_ids': [],
        }
        if not len(self.ids):
            return scores, info

        prototype = np.asarray(prototype, dtype=np.float32)
        similarities = self.vectors @ prototype
        best_similarity = float(np.max(similarities))
        info['best_similarity'] = round(best_similarity, 4)
        matching = np.where(similarities >= FEEDBACK_MATCH_THRESHOLD)[0]
        info['matched_examples'] = int(len(matching))
        if not len(matching):
            return scores, info

        boosted = np.asarray(scores, dtype=np.float32).copy()
        catalog_map = {str(value): idx for idx, value in enumerate(catalog_ids)}
        boost_by_catalog = {}

        for feedback_idx in matching:
            catalog_idx = catalog_map.get(self.ids[int(feedback_idx)])
            if catalog_idx is None:
                continue
            similarity = float(similarities[int(feedback_idx)])
            strength = (similarity - FEEDBACK_MATCH_THRESHOLD) / max(1e-6, 1.0 - FEEDBACK_MATCH_THRESHOLD)
            vote_factor = min(1.20, 1.0 + 0.04 * np.log1p(int(self.votes[int(feedback_idx)])))
            amount = min(FEEDBACK_MAX_BOOST, FEEDBACK_MAX_BOOST * max(0.0, strength) * vote_factor)
            boost_by_catalog[catalog_idx] = max(boost_by_catalog.get(catalog_idx, 0.0), amount)

        for catalog_idx, amount in boost_by_catalog.items():
            boosted[catalog_idx] = min(1.0, float(boosted[catalog_idx]) + amount)

        if boost_by_catalog:
            info['used'] = True
            info['boosted_ids'] = [str(catalog_ids[idx]) for idx in boost_by_catalog.keys()]
        return boosted, info


def similarity_rank(catalog_vectors, query_embedding, mode):
    """Return fusion rank scores and raw per-crop cosine similarities.

    Best focused crop carries most weight, with mean and full-photo score
    stabilizing results. Numeric scores are model-relative, NOT confidence.
    """
    cosines = catalog_vectors @ query_embedding.T
    if mode == 'single' or cosines.shape[1] == 1:
        return cosines[:, 0], cosines
    full = cosines[:, 0]
    focused = cosines[:, 1:]
    best = np.max(focused, axis=1)
    average = np.mean(focused, axis=1)
    blended = (0.12 * full + 0.73 * best + 0.15 * average)
    return blended, cosines


def score_query(image, model, preprocess, catalog_vectors, ids, limit, mode, feedback_store):
    crops = query_crops(image, mode)
    q = query_vectors(crops, model, preprocess)
    scores, cosine_scores = similarity_rank(catalog_vectors, q, mode)
    prototype = query_prototype(q)
    scores, feedback_info = feedback_store.boost(scores, ids, prototype)

    k = max(1, min(int(limit), 100, len(ids)))
    if k == len(ids):
        picks = np.argsort(-scores)[:k]
    else:
        candidates = np.argpartition(-scores, k - 1)[:k]
        picks = candidates[np.argsort(-scores[candidates])]
    data = []
    for idx in picks:
        best_crop_idx = int(np.argmax(cosine_scores[idx]))
        data.append({
            'id': str(ids[idx]),
            'similarity': float(scores[idx]),
            'best_crop': crops[best_crop_idx][0],
        })
    return {
        'total': int(len(ids)),
        'mode': mode,
        'crops_used': [name for name, _ in crops],
        'feedback': feedback_info,
        'results': data,
    }


async def decode_upload_image(image):
    raw = await image.read(MAX_UPLOAD_BYTES + 1)
    if len(raw) > MAX_UPLOAD_BYTES:
        raise HTTPException(413, 'Image too large (max 10 MB)')
    try:
        with warnings.catch_warnings():
            warnings.simplefilter('error', Image.DecompressionBombWarning)
            with Image.open(io.BytesIO(raw)) as im:
                im.load()
                if im.width < 24 or im.height < 24:
                    raise ValueError('Too small')
                return im.copy()
    except (OSError, ValueError, Image.DecompressionBombError, Image.DecompressionBombWarning):
        raise HTTPException(422, 'Invalid or excessively large image')


def serve(index_path, feedback_path, host, port):
    import uvicorn

    model, preprocess = model_loader()
    with np.load(index_path, allow_pickle=False) as data:
        ids = data['ids'].copy()
        vectors = np.asarray(data['vectors'], dtype=np.float32).copy()
        saved_model = data['model'].tolist()
    if saved_model != [MODEL, PRETRAINED]:
        raise RuntimeError('Model AI tidak sesuai indeks; rebuild index dahulu')
    if vectors.ndim != 2 or len(ids) != len(vectors) or not len(ids):
        raise RuntimeError('Indeks AI tidak valid atau kosong')
    if not np.isfinite(vectors).all():
        raise RuntimeError('Indeks AI mengandung nilai tidak valid')

    feedback_store = FeedbackStore(feedback_path, vectors.shape[1])
    catalog_ids = {str(value) for value in ids.tolist()}

    app = FastAPI(docs_url=None, redoc_url=None, openapi_url=None)
    inference_gate = asyncio.Semaphore(1)  # protect local CPU/RAM and feedback file

    @app.get('/health')
    def health():
        return {
            'status': 'ok', 'indexed': int(len(ids)), 'model': MODEL,
            'search_mode': 'multi-crop', 'max_candidates': 100,
            'feedback_examples': len(feedback_store.ids),
        }

    @app.post('/search')
    async def search(image: UploadFile = File(...), limit: int = Form(5), mode: str = Form('multi')):
        if mode not in ('single', 'multi'):
            raise HTTPException(422, 'mode must be single or multi')
        if not (1 <= limit <= 100):
            raise HTTPException(422, 'limit must be 1 to 100')
        photo = await decode_upload_image(image)
        async with inference_gate:
            try:
                return await asyncio.to_thread(
                    score_query, photo, model, preprocess, vectors, ids, limit, mode, feedback_store
                )
            except Exception as exc:
                print('AI search error:', type(exc).__name__, str(exc), flush=True)
                raise HTTPException(500, 'AI inference failed')

    @app.post('/feedback')
    async def feedback(image: UploadFile = File(...), selected_id: str = Form(...)):
        selected_id = str(selected_id).strip()
        if selected_id not in catalog_ids:
            raise HTTPException(422, 'selected_id is not present in catalog index')
        photo = await decode_upload_image(image)
        async with inference_gate:
            try:
                crops = query_crops(photo, 'multi')
                q = await asyncio.to_thread(query_vectors, crops, model, preprocess)
                prototype = query_prototype(q)
                result = feedback_store.add(selected_id, prototype)
                return {
                    'status': 'ok',
                    'selected_id': selected_id,
                    'feedback_examples': int(result['examples']),
                    'votes': int(result['votes']),
                    'merged': bool(result['merged']),
                }
            except Exception as exc:
                print('AI feedback error:', type(exc).__name__, str(exc), flush=True)
                raise HTTPException(500, 'AI feedback save failed')

    uvicorn.run(app, host=host, port=port, access_log=False)


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--build', action='store_true')
    parser.add_argument('--manifest', default='storage/app/ai-manifest.json')
    parser.add_argument('--index', default='storage/app/ai-clip-index.npz')
    parser.add_argument('--feedback', default='storage/app/ai-feedback.npz')
    parser.add_argument('--host', default='127.0.0.1')
    parser.add_argument('--port', type=int, default=8779)
    args = parser.parse_args()
    if args.build:
        build(args.manifest, args.index)
    else:
        serve(args.index, args.feedback, args.host, args.port)
