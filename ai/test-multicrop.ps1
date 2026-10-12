# Compare the old single-photo CLIP ranking with the new automatic multi-crop ranking.
# Windows PowerShell 5.1 / PowerShell 7 compatible. Do not send images anywhere except localhost.
param(
    [Parameter(Mandatory = $true)]
    [string] $ImagePath,
    [string] $ItemCode = '00072',
    [string] $AiUrl = 'http://127.0.0.1:8779',
    [string] $CatalogPath = 'storage\app\drive-image-index.json'
)
$ErrorActionPreference = 'Stop'

if (-not (Test-Path -LiteralPath $ImagePath -PathType Leaf)) {
    throw "Foto tidak ditemukan: $ImagePath"
}
if (-not (Test-Path -LiteralPath $CatalogPath -PathType Leaf)) {
    throw "Indeks Google Drive tidak ditemukan: $CatalogPath (jalankan dari folder project Laravel)"
}
$imageFullPath = (Resolve-Path -LiteralPath $ImagePath).Path
$index = Get-Content -LiteralPath $CatalogPath -Raw -Encoding UTF8 | ConvertFrom-Json
$items = if ($index.PSObject.Properties.Name -contains 'items') { $index.items } else { $index }
$needle = $ItemCode + '.webp'
$targets = @($items.PSObject.Properties | Where-Object { $_.Value.name -ieq $needle })
if ($targets.Count -eq 0) {
    Write-Host "Kode $ItemCode tidak ditemukan di indeks lokal; cek nama file katalog." -ForegroundColor Yellow
    exit 2
}
$targetIds = @($targets | ForEach-Object { $_.Name })
Write-Host "Kode: $ItemCode | target: $($targets[0].Value.path)"
Write-Host "Menguji foto yang sama menggunakan model CLIP dan indeks yang sama ..."

foreach ($mode in @('single', 'multi')) {
    $json = & curl.exe --silent --show-error --fail --request POST "$($AiUrl.TrimEnd('/'))/search" --form "image=@$imageFullPath" --form 'limit=100' --form "mode=$mode"
    if ($LASTEXITCODE -ne 0 -or -not $json) {
        throw "Request mode=$mode gagal. Pastikan layanan Python v3.1 berjalan di $AiUrl."
    }
    $response = $json | ConvertFrom-Json
    $matches = @($response.results)
    $rank = -1
    for ($i = 0; $i -lt $matches.Count; $i++) {
        if ($targetIds -contains $matches[$i].id) { $rank = $i; break }
    }
    if ($rank -ge 0) {
        $score = [math]::Round([double]$matches[$rank].similarity * 100, 1)
        $crop = $matches[$rank].best_crop
        Write-Host "$($mode.PadRight(6)) : Rank $($rank+1) / $($matches.Count) | skor relatif $score | crop terbaik $crop" -ForegroundColor Green
    } else {
        Write-Host "$($mode.PadRight(6)) : $ItemCode tidak masuk $($matches.Count) hasil" -ForegroundColor Yellow
    }
}
Write-Host 'Catatan: skor hasil multi-crop adalah skor gabungan, bukan confidence identitas barang.'
