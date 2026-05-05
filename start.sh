#!/bin/bash
set -e

echo "=========================================="
echo " AviLight Startup Script"
echo "=========================================="

# ─────────────────────────────────────────────
# 1. Fix Apache MPM conflict at runtime
# ─────────────────────────────────────────────
echo "[APACHE] Fixing MPM modules..."
a2dismod mpm_event mpm_worker 2>/dev/null || true
a2enmod mpm_prefork 2>/dev/null || true
echo "[APACHE] MPM set to prefork ✅"

# ─────────────────────────────────────────────
# 2. Write GEE service account JSON from env var
# ─────────────────────────────────────────────
if [ -n "$GEE_SA_KEY_JSON" ]; then
    echo "[GEE] Writing service account key..."
    mkdir -p /var/www/html/secrets
    echo "$GEE_SA_KEY_JSON" > /var/www/html/secrets/gee-service-account.json
    chmod 600 /var/www/html/secrets/gee-service-account.json
    echo "[GEE] Service account key written ✅"
else
    echo "[GEE] WARNING: GEE_SA_KEY_JSON not set."
fi

# ─────────────────────────────────────────────
# 3. Set correct permissions on model storage
# ─────────────────────────────────────────────
mkdir -p /var/www/html/api_models
chown -R www-data:www-data /var/www/html/api_models || true
chmod -R 775 /var/www/html/api_models || true
echo "[MODELS] Model storage ready ✅"

# ─────────────────────────────────────────────
# 4. Start Python FastAPI backend (background)
# ─────────────────────────────────────────────
echo "[PYTHON] Starting FastAPI on port 8000..."
cd /var/www/html

if [ -f "python/main.py" ]; then
    python3 -m uvicorn python.main:app \
        --host 127.0.0.1 \
        --port 8000 \
        --workers 2 \
        --log-level info &
elif [ -f "model.py" ]; then
    python3 -m uvicorn model:app \
        --host 127.0.0.1 \
        --port 8000 \
        --workers 2 \
        --log-level info &
else
    echo "[PYTHON] WARNING: No FastAPI entry point found."
fi

echo "[PYTHON] Waiting 5s for FastAPI to initialize..."
sleep 5

# ─────────────────────────────────────────────
# 5. Start Apache (foreground)
# ─────────────────────────────────────────────
echo "[APACHE] Starting Apache..."
source /etc/apache2/envvars
exec apache2 -D FOREGROUND