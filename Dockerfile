FROM ubuntu:22.04
ENV DEBIAN_FRONTEND=noninteractive

# ─────────────────────────────────────────────
# 1. System packages
# ─────────────────────────────────────────────
RUN apt-get update && apt-get install -y \
    # Apache + PHP
    apache2 \
    php8.1 \
    php8.1-mysql \
    php8.1-mbstring \
    php8.1-xml \
    php8.1-curl \
    php8.1-zip \
    php8.1-gd \
    libapache2-mod-php8.1 \
    # Python
    python3.10 \
    python3-pip \
    python3.10-dev \
    # Utilities
    unzip \
    curl \
    git \
    && rm -rf /var/lib/apt/lists/*

# ─────────────────────────────────────────────
# 2. Apache modules
# ─────────────────────────────────────────────
RUN a2enmod rewrite php8.1 proxy proxy_http

# ─────────────────────────────────────────────
# 3. PHP configuration
# ─────────────────────────────────────────────
RUN echo "upload_max_filesize = 500M"   >> /etc/php/8.1/apache2/php.ini && \
    echo "post_max_size = 512M"          >> /etc/php/8.1/apache2/php.ini && \
    echo "memory_limit = 512M"           >> /etc/php/8.1/apache2/php.ini && \
    echo "max_execution_time = 300"      >> /etc/php/8.1/apache2/php.ini && \
    echo "max_input_time = 300"          >> /etc/php/8.1/apache2/php.ini

# ─────────────────────────────────────────────
# 4. Python dependencies
# ─────────────────────────────────────────────
COPY requirements.txt /tmp/requirements.txt
RUN pip3 install --no-cache-dir -r /tmp/requirements.txt

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
RUN a2ensite 000-default && a2dissite default || true

# ─────────────────────────────────────────────
# 8. Startup script
# ─────────────────────────────────────────────
COPY start.sh /start.sh
RUN chmod +x /start.sh

EXPOSE 80

CMD ["/start.sh"]
