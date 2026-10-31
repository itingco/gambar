#!/usr/bin/env bash
set -u
cd /var/www/gambar || exit 1
printf '\n=== Python environment ===\n'
if [ -x .venv-ai/bin/python ]; then
  .venv-ai/bin/python -c 'import torch,open_clip,fastapi,uvicorn; print("Dependencies OK; torch",torch.__version__)' || true
else
  echo 'MISSING .venv-ai/bin/python'
fi
printf '\n=== AI index ===\n'
ls -lh storage/app/ai-clip-index.npz storage/app/ai-manifest.json 2>&1 || true
printf '\n=== Service ===\n'
systemctl --no-pager status smartimages-ai 2>&1 | head -n 30 || true
printf '\n=== Port and health ===\n'
ss -ltnp 2>/dev/null | grep ':8779' || true
curl -sS --max-time 5 -w '\nHTTP %{http_code}\n' http://127.0.0.1:8779/health || true
printf '\n=== Recent service logs ===\n'
journalctl -u smartimages-ai -n 35 --no-pager 2>&1 || true
