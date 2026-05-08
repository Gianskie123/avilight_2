FROM php:8.2-apache

# ─────────────────────────────────────────────
# 1. System packages
# ─────────────────────────────────────────────
RUN apt-get update && apt-get install -y \
    python3 \
    python3-pip \
    python3-dev \
    unzip \
    curl \
    git \
    libjpeg-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    libwebp-dev \
    libfreetype6-dev \
    libxml2-dev \
    libzip-dev \
    libonig-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install \
        pdo \
        pdo_mysql \
        mysqli \
        mbstring \
        zip \
        gd \
    && rm -rf /var/lib/apt/lists/*

# ─────────────────────────────────────────────
# 2. Apache modules — fix MPM conflict
# ─────────────────────────────────────────────
RUN a2dismod mpm_event mpm_worker 2>/dev/null || true && \
    a2enmod mpm_prefork rewrite proxy proxy_http

# ─────────────────────────────────────────────
# 3. PHP configuration (increase limits for model uploads)
# ─────────────────────────────────────────────
RUN echo "upload_max_filesize = 500M" >> /usr/local/etc/php/conf.d/avilight.ini && \
    echo "post_max_size = 512M"        >> /usr/local/etc/php/conf.d/avilight.ini && \
    echo "memory_limit = 512M"         >> /usr/local/etc/php/conf.d/avilight.ini && \
    echo "max_execution_time = 300"    >> /usr/local/etc/php/conf.d/avilight.ini && \
    echo "max_input_time = 300"        >> /usr/local/etc/php/conf.d/avilight.ini

# ─────────────────────────────────────────────
# 4. Python dependencies
# ─────────────────────────────────────────────
COPY requirements.txt /tmp/requirements.txt
RUN pip3 install --no-cache-dir -r /tmp/requirements.txt --break-system-packages

# ─────────────────────────────────────────────
# 5. Copy application files
# ─────────────────────────────────────────────
WORKDIR /var/www/html
COPY . /var/www/html/

# ─────────────────────────────────────────────
# 6. Directory setup & permissions
# ─────────────────────────────────────────────
RUN mkdir -p /var/www/html/api_models && \
    mkdir -p /var/www/html/data && \
    mkdir -p /var/www/html/secrets && \
    chown -R www-data:www-data /var/www/html && \
    chmod -R 755 /var/www/html && \
    chmod -R 775 /var/www/html/api_models && \
    chmod -R 775 /var/www/html/data

# ─────────────────────────────────────────────
# 7. Apache virtual host config
# ─────────────────────────────────────────────
COPY apache.conf /etc/apache2/sites-available/000-default.conf
RUN a2ensite 000-default

# ─────────────────────────────────────────────
# 8. Startup script
# ─────────────────────────────────────────────
COPY start.sh /start.sh
RUN chmod +x /start.sh

EXPOSE 80

CMD ["/start.sh"]