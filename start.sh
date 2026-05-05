#!/bin/bash
set -e

echo "=========================================="
echo " AviLight Startup Script"
echo "=========================================="

# ─────────────────────────────────────────────
# 1. Write GEE service account JSON from env var
#    (Railway stores the whole JSON as a string)
# ─────────────────────────────────────────────
if [ -n "$GEE_SA_KEY_JSON" ]; then
    echo "[GEE] Writing service account key..."
    mkdir -p /var/www/html/secrets
    echo "$GEE_SA_KEY_JSON" > /var/www/html/secrets/gee-service-account.json
    chmod 600 /var/www/html/secrets/gee-service-account.json
    echo "[GEE] Service account key written to /var/www/html/secrets/gee-service-account.json"
else
    echo "[GEE] WARNING: GEE_SA_KEY_JSON not set. Earth Engine calls will fail."
fi

# ─────────────────────────────────────────────
# 2. Set correct permissions on model storage
#    (persistent volume may be mounted as root)
# ─────────────────────────────────────────────
mkdir -p /var/www/html/api_models
chown -R www-data:www-data /var/www/html/api_models || true
chmod -R 775 /var/www/html/api_models || true
echo "[MODELS] Model storage directory ready at /var/www/html/api_models"

# ─────────────────────────────────────────────
# 3. Start Python FastAPI backend (background)
# ─────────────────────────────────────────────
echo "[PYTHON] Starting FastAPI backend on port 8000..."
cd /var/www/html

# Try python/main.py first (your folder structure has a /python dir)
if [ -f "python/main.py" ]; then
    python3 -m uvicorn python.main:app \
        --host 127.0.0.1 \
        --port 8000 \
        --workers 2 \
        --log-level info &
elif [ -f "model.py" ]; then
    # Fallback: model.py in root
    python3 -m uvicorn model:app \
        --host 127.0.0.1 \
        --port 8000 \
        --workers 2 \
        --log-level info &
else
    echo "[PYTHON] WARNING: No FastAPI entry point found. Skipping Python backend."
fi

PYTHON_PID=$!
echo "[PYTHON] FastAPI started (PID: $PYTHON_PID)"

# Give Python time to initialize (GEE auth + model loading can take a few seconds)
echo "[PYTHON] Waiting 5s for Python backend to initialize..."
sleep 5

# ─────────────────────────────────────────────
# 4. Start Apache (foreground — keeps container alive)
# ─────────────────────────────────────────────
echo "[APACHE] Starting Apache..."
source /etc/apache2/envvars
exec apache2 -D FOREGROUND
