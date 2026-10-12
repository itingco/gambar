"""Smart Image Finder v3.1 — CPU CLIP + focused multi-crop inference.

Backward compatible with the v3 CLIP .npz index and POST /search contract.
Use mode=single to reproduce v3 search, mode=multi (default) for multiple crops.
Only uploaded queries are cropped; the 2,378 existing catalog embeddings are reused.
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


def score_query(image, model, preprocess, catalog_vectors, ids, limit, mode):
    crops = query_crops(image, mode)
    q = query_vectors(crops, model, preprocess)
    scores, cosine_scores = similarity_rank(catalog_vectors, q, mode)
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
        'results': data,
    }


def serve(index_path, host, port):
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

    app = FastAPI(docs_url=None, redoc_url=None, openapi_url=None)
    inference_gate = asyncio.Semaphore(1)  # protect local CPU/RAM from concurrent requests

    @app.get('/health')
    def health():
        return {
            'status': 'ok', 'indexed': int(len(ids)), 'model': MODEL,
            'search_mode': 'multi-crop', 'max_candidates': 100,
        }

    @app.post('/search')
    async def search(image: UploadFile = File(...), limit: int = Form(5), mode: str = Form('multi')):
        if mode not in ('single', 'multi'):
            raise HTTPException(422, 'mode must be single or multi')
        if not (1 <= limit <= 100):
            raise HTTPException(422, 'limit must be 1 to 100')
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
                    photo = im.copy()
        except (OSError, ValueError, Image.DecompressionBombError, Image.DecompressionBombWarning):
            raise HTTPException(422, 'Invalid or excessively large image')
        async with inference_gate:
            try:
                return await asyncio.to_thread(
                    score_query, photo, model, preprocess, vectors, ids, limit, mode
                )
            except Exception as exc:
                print('AI search error:', type(exc).__name__, str(exc), flush=True)
                raise HTTPException(500, 'AI inference failed')

    uvicorn.run(app, host=host, port=port, access_log=False)


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--build', action='store_true')
    parser.add_argument('--manifest', default='storage/app/ai-manifest.json')
    parser.add_argument('--index', default='storage/app/ai-clip-index.npz')
    parser.add_argument('--host', default='127.0.0.1')
    parser.add_argument('--port', type=int, default=8779)
    args = parser.parse_args()
    if args.build:
        build(args.manifest, args.index)
    else:
        serve(args.index, args.host, args.port)
