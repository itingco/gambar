<!doctype html>
<html lang="id"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Galeri Barang — Smart Image Finder</title>
<style>
:root{font-family:Inter,system-ui,-apple-system,'Segoe UI',sans-serif;color:#182840;background:#f4f6fa}*{box-sizing:border-box}body{margin:0}button,input,select{font:inherit}button{cursor:pointer}header{background:#172c45;color:#fff;padding:18px max(18px,calc((100vw - 1200px)/2));display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}header strong{font-size:18px}a{color:inherit;text-decoration:none}.nav{display:flex;gap:8px;flex-wrap:wrap}.nav a{border:1px solid #ffffff30}.nav a{padding:10px 12px;border-radius:9px;color:#dce9f6}.nav a.active{background:#ffa400;color:#16243b;font-weight:700}main{max-width:1200px;margin:0 auto;padding:22px 18px 60px}h1{font-size:23px;margin:0 0 6px}p.sub{color:#58687e;margin:0 0 19px}.tools{background:white;border-radius:16px;padding:16px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;box-shadow:0 4px 18px #1a345312}.tools input{flex:1;min-width:190px}.tools input,.tools select{border:1px solid #d6dfeb;background:#fff;padding:12px;border-radius:10px;outline:none}.tools input:focus,.tools select:focus{border-color:#ffa400}.tools button{background:#ffa400;border:0;padding:12px 20px;border-radius:10px;font-weight:700}.summary{min-height:46px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;color:#5f6e7d;font-size:13px}.warn{background:#fff1e7;color:#874611;border-radius:9px;padding:10px;margin-bottom:12px}.grid{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:13px}.card{background:#fff;border-radius:13px;overflow:hidden;box-shadow:0 4px 15px #21334c0c;min-width:0}.photo{width:100%;aspect-ratio:1/1;object-fit:contain;background:#fff;display:block;padding:8px;cursor:zoom-in}.photo-wrap{position:relative;border-bottom:1px solid #eef1f6}.placeholder{font-size:12px;text-align:center;color:#8090a3;padding:30px 10px}.details{padding:12px}.details strong{font-size:13px;overflow-wrap:anywhere;display:block}.details .name{font-size:12px;line-height:1.35;min-height:34px;max-height:48px;overflow:hidden;margin:7px 0;color:#37485c}.price{font-weight:800;color:#006b40;font-size:14px;margin-top:7px}.small{font-size:11px;color:#7c8a9c}.chip{display:inline-block;font-size:10px;padding:4px 7px;border-radius:20px;background:#edf2f8;color:#37516c;margin-top:6px}.pagination{display:flex;gap:10px;align-items:center;justify-content:center;margin:25px 0}.pagination button{background:#fff;padding:10px 15px;border:1px solid #d5dfec;border-radius:10px}.pagination button:disabled{opacity:.4;cursor:not-allowed}.modal{position:fixed;inset:0;background:rgba(3,14,27,.87);z-index:1000;display:none;align-items:center;justify-content:center;padding:clamp(10px,3vw,24px)}.modal.open{display:flex}.modal-content{position:relative;background:#fff;border-radius:16px;padding:16px;box-shadow:0 18px 55px #0006;width:min(1050px,100%);max-height:95dvh;display:flex;flex-direction:column;align-items:center;gap:8px}.modal figure{margin:0;width:100%;min-height:120px;display:flex;justify-content:center;align-items:center;overflow:auto}.modal img{display:block;width:100%;height:auto;max-width:100%;max-height:calc(95dvh - 155px);object-fit:contain}.modal button.close{position:absolute;right:9px;top:9px;background:#edf1f6;border:0;border-radius:50%;width:38px;height:38px;font-size:22px;z-index:2}.modal p{overflow-wrap:anywhere;font-size:13px;margin:0;text-align:center;padding:0 35px}.modal .modal-status{font-size:12px;color:#5b687b;text-align:center;min-height:17px}.modal button.retry{background:#ffa400;border:0;border-radius:10px;padding:8px 15px;font-weight:700}.modal button.retry[hidden]{display:none}.photo:focus-visible{outline:3px solid #ffa400;outline-offset:-3px}@media(max-width:700px){.modal-content{padding:12px;width:100%}.modal img{max-height:calc(95dvh - 170px)}}.empty{padding:55px 12px;text-align:center;color:#617188;background:white;border-radius:14px}@media(max-width:1050px){.grid{grid-template-columns:repeat(4,minmax(0,1fr))}}@media(max-width:700px){header{padding:13px}header strong{font-size:15px}main{padding:18px 12px 50px}.grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:9px}.tools{padding:12px;gap:8px}.tools input{width:100%;flex-basis:100%}.tools select{flex:1}.details{padding:9px}.details .name{font-size:11px}.price{font-size:13px}.photo{padding:5px}h1{font-size:20px}}
</style></head><body>
<header><strong>🔎 Smart Image Finder</strong><nav class="nav"><a href="/">Cari Foto</a><a class="active" href="/gallery">Galeri Barang</a></nav></header>
<main><h1>Galeri Barang</h1><p class="sub">Pilih sumber katalog INGCO atau SMI (WADFOW), kemudian cari kode atau nama barang.</p>
<form class="tools" id="searchForm"><input id="q" type="search" maxlength="100" placeholder="Cari kode atau nama barang..." aria-label="Cari kode atau nama barang"><select id="brand" aria-label="Filter merek"><option value="ingco">INGCO</option><option value="smi">SMI (WADFOW)</option></select><button type="submit">Cari</button></form>
<div class="summary" id="status" aria-live="polite">Memuat katalog ringan...</div><div id="warning" class="warn" hidden></div>
<section id="gallery" class="grid" aria-label="Daftar barang"></section><div id="empty" class="empty" hidden>Tidak ada gambar yang cocok dengan pencarian.</div>
<div class="pagination"><button id="prev" type="button">← Sebelumnya</button><span id="pageInfo"></span><button id="next" type="button">Berikutnya →</button></div>
</main>
<div class="modal" id="modal" role="dialog" aria-modal="true" aria-label="Preview gambar" aria-labelledby="modalTitle" tabindex="-1"><div class="modal-content"><button class="close" id="closeModal" type="button" aria-label="Tutup gambar">×</button><figure><img id="largeImg" alt="Preview barang"></figure><p id="modalTitle"></p><div id="modalStatus" class="modal-status" aria-live="polite"></div><button class="retry" id="retryLarge" type="button" hidden>Coba gambar asli</button></div></div>
<script>
const grid=document.getElementById('gallery'), status=document.getElementById('status'), warning=document.getElementById('warning');
const prev=document.getElementById('prev'),next=document.getElementById('next'),pageInfo=document.getElementById('pageInfo');
let currentPage=1,totalPages=1,activeController=null;
// Load preview hanya saat mendekati viewport, bukan seluruh gambar halaman sekaligus.
const imageObserver=new IntersectionObserver((entries,observer)=>{
  for(const entry of entries){if(!entry.isIntersecting)continue;
    const img=entry.target;if(img.dataset.src){img.src=img.dataset.src;delete img.dataset.src;}
    observer.unobserve(img);
  }
},{rootMargin:'160px 0px',threshold:0.01});
const params=new URLSearchParams(location.search);document.getElementById('q').value=params.get('q')||'';const initialBrand=params.get('brand');if(['ingco','smi'].includes(initialBrand))document.getElementById('brand').value=initialBrand;
const fmt=n=>n==null?'-':'Rp '+new Intl.NumberFormat('id-ID',{maximumFractionDigits:0}).format(Number(n));
function el(tag,cls,txt){const node=document.createElement(tag);if(cls)node.className=cls;if(txt!==undefined)node.textContent=txt;return node;}
const modal=document.getElementById('modal'),largeImg=document.getElementById('largeImg'),modalStatus=document.getElementById('modalStatus'),retryLarge=document.getElementById('retryLarge');
let previewSeq=0,returnFocus=null,activeItem=null;
// URL hasil Laravel sudah relatif (/catalog dan /gallery-thumbs): aman pada HTTPS + reverse proxy.
function tryFullImage(item,seq){
  if(!item?.url)return;
  modalStatus.textContent='Memeriksa gambar asli dari Google Drive...';
  retryLarge.hidden=true;
  const loader=new Image();
  let finished=false;
  const timeout=setTimeout(()=>{if(finished||seq!==previewSeq)return;finished=true;loader.onload=null;loader.onerror=null;modalStatus.textContent='Gambar asli lambat/tidak tersedia; preview cache tetap ditampilkan.';retryLarge.hidden=false;},8000);
  loader.onload=()=>{if(finished||seq!==previewSeq)return;finished=true;clearTimeout(timeout);largeImg.src=item.url;modalStatus.textContent='Gambar asli';retryLarge.hidden=true;};
  loader.onerror=()=>{if(finished||seq!==previewSeq)return;finished=true;clearTimeout(timeout);modalStatus.textContent='Gambar asli tidak tersedia; preview cache tetap ditampilkan.';retryLarge.hidden=false;};
  loader.src=item.url;
}
function openPreview(item,trigger){
  previewSeq++;
  const seq=previewSeq;
  activeItem=item;
  returnFocus=trigger||document.activeElement;
  document.getElementById('modalTitle').textContent=(item.code||item.name)+' — '+(item.product?.item_name||item.name);
  modal.classList.add('open');
  document.body.style.overflow='hidden';
  document.getElementById('closeModal').focus();
  retryLarge.hidden=true;
  largeImg.alt='Gambar '+item.name;
  largeImg.removeAttribute('src');
  // Cache 960px jika dibuat, thumbnail 240px sebagai fallback.
  const candidates=[item.preview_url,item.thumbnail_url].filter(Boolean);
  let i=0;
  const loadCached=()=>{
    if(seq!==previewSeq)return;
    if(i>=candidates.length){modalStatus.textContent='Cache preview belum tersedia. Mencoba gambar asli...';tryFullImage(item,seq);return;}
    const url=candidates[i++];
    const test=new Image();
    test.onload=()=>{
      if(seq!==previewSeq)return;
      largeImg.src=url;
      const highRes=(url===item.preview_url);
      modalStatus.textContent=highRes?'Preview resolusi tinggi dari cache lokal':'Preview thumbnail lokal (resolusi rendah)';
      if(!highRes)tryFullImage(item,seq);
    };
    test.onerror=loadCached;
    test.src=url;
  };
  modalStatus.textContent='Memuat preview...';
  loadCached();
}
function closePreview(){previewSeq++;modal.classList.remove('open');largeImg.removeAttribute('src');retryLarge.hidden=true;document.body.style.overflow='';activeItem=null;if(returnFocus&&returnFocus.isConnected)returnFocus.focus();returnFocus=null;}
retryLarge.onclick=()=>{if(activeItem)tryFullImage(activeItem,previewSeq)};
document.getElementById('closeModal').onclick=closePreview;
modal.onclick=e=>{if(e.target===modal)closePreview()};
document.addEventListener('keydown',e=>{if(e.key==='Escape'&&modal.classList.contains('open'))closePreview()});
function render(item){
  const card=el('article','card'),wrap=el('div','photo-wrap'),img=el('img','photo');
  img.loading='lazy';img.decoding='async';img.alt='Perbesar '+item.name;img.tabIndex=0;img.setAttribute('role','button');
  if(item.thumbnail_url)img.dataset.src=item.thumbnail_url;
  img.onclick=()=>openPreview(item,img);
  img.onkeydown=e=>{if(e.key==='Enter'||e.key===' '){e.preventDefault();openPreview(item,img)}};
  img.onerror=()=>{img.remove();wrap.append(el('div','placeholder','Thumbnail belum tersedia'));};
  if(item.thumbnail_url){wrap.append(img);imageObserver.observe(img);}else{
    const placeholder=el('div','placeholder','Thumbnail belum tersedia — klik untuk preview');
    placeholder.tabIndex=0;placeholder.setAttribute('role','button');placeholder.onclick=()=>openPreview(item,placeholder);
    placeholder.onkeydown=e=>{if(e.key==='Enter'||e.key===' '){e.preventDefault();openPreview(item,placeholder)}};
    wrap.append(placeholder);
  }
  card.append(wrap);const d=el('div','details');d.append(el('strong','',item.code));d.append(el('span','chip',item.brand.toUpperCase()));d.append(el('div','name',item.product?.item_name||item.name));d.append(el('div','price',item.product?.price!=null?fmt(item.product.price):'Harga RETAIL: -'));d.append(el('div','small',item.name));card.append(d);grid.append(card);
}
async function load(page=1){if(activeController)activeController.abort();activeController=new AbortController();grid.querySelectorAll('img.photo').forEach(img=>imageObserver.unobserve(img));grid.replaceChildren();warning.hidden=true;status.textContent='Memuat barang...';prev.disabled=true;next.disabled=true;const query=new URLSearchParams({page:String(page),brand:document.getElementById('brand').value,q:document.getElementById('q').value.trim()});try{const resp=await fetch('/gallery/items?'+query.toString(),{headers:{Accept:'application/json'},signal:activeController.signal});const data=await resp.json();if(!resp.ok)throw Error(data.message||'HTTP '+resp.status);currentPage=data.page;totalPages=data.pages;data.items.forEach(render);status.textContent=`${data.total.toLocaleString('id-ID')} barang ditemukan • ${data.per_page} per halaman • gambar dimuat bertahap`;pageInfo.textContent=`Halaman ${data.page} / ${data.pages}`;prev.disabled=data.page<=1;next.disabled=data.page>=data.pages;document.getElementById('empty').hidden=data.items.length>0;const errors=[...(data.database_errors||[])];if(data.search_warning)errors.push(data.search_warning);if(errors.length){warning.textContent='Informasi: '+errors.join(' · ');warning.hidden=false;}history.replaceState(null,'','/gallery?'+query.toString());}catch(e){if(e.name==='AbortError')return;status.textContent='Gagal memuat katalog: '+e.message;pageInfo.textContent='';}}
document.getElementById('searchForm').onsubmit=e=>{e.preventDefault();load(1)};document.getElementById('brand').onchange=()=>load(1);prev.onclick=()=>{if(currentPage>1)load(currentPage-1)};next.onclick=()=>{if(currentPage<totalPages)load(currentPage+1)};load(1);
</script></body></html>
