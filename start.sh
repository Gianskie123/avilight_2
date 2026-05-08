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
# 2. Set Railway PORT (default to 80 if not set)
# ─────────────────────────────────────────────
RAILWAY_PORT=${PORT:-80}
echo "[APACHE] Configuring Apache to listen on port $RAILWAY_PORT..."

# Update Apache ports.conf to use Railway's $PORT
cat > /etc/apache2/ports.conf << PORTS
Listen $RAILWAY_PORT
PORTS

# Update VirtualHost to use Railway's $PORT
sed -i "s/<VirtualHost \*:80>/<VirtualHost *:$RAILWAY_PORT>/" \
    /etc/apache2/sites-available/000-default.conf

echo "[APACHE] Port set to $RAILWAY_PORT ✅"

# ─────────────────────────────────────────────
# 3. Write secrets from env vars
# ─────────────────────────────────────────────
if [ -n "$GEE_SA_KEY_JSON" ]; then
    echo "[GEE] Writing service account key..."
    mkdir -p /var/www/html/secrets
    echo "$GEE_SA_KEY_JSON" > /var/www/html/secrets/gee-service-account.json
    chown -R www-data:www-data /var/www/html/secrets || true
    chmod 750 /var/www/html/secrets || true
    chmod 640 /var/www/html/secrets/gee-service-account.json
    echo "[GEE] Service account key written ✅"
else
    echo "[GEE] WARNING: GEE_SA_KEY_JSON not set."
fi

if [ -n "$DB_SSL_CA_PEM" ]; then
    echo "[DB] Writing MySQL CA certificate..."
    mkdir -p /var/www/html/secrets
    echo "$DB_SSL_CA_PEM" > /var/www/html/secrets/ca.pem
    chmod 600 /var/www/html/secrets/ca.pem
    if [ -z "$DB_SSL_CA" ]; then
        export DB_SSL_CA=/var/www/html/secrets/ca.pem
    fi
    echo "[DB] MySQL CA certificate written ✅"
else
    echo "[DB] WARNING: DB_SSL_CA_PEM not set."
fi

# ─────────────────────────────────────────────
# 4. Set correct permissions on model storage
# ─────────────────────────────────────────────
mkdir -p /var/www/html/api_models
chown -R www-data:www-data /var/www/html/api_models || true
chmod -R 775 /var/www/html/api_models || true
echo "[MODELS] Model storage ready ✅"

# ─────────────────────────────────────────────
# 5. Start Python FastAPI backend (background)
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
# 6. Start Apache on Railway's $PORT (foreground)
# ─────────────────────────────────────────────
echo "[APACHE] Starting Apache on port $RAILWAY_PORT..."
source /etc/apache2/envvars
exec apache2 -D FOREGROUND