FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libsqlite3-dev \
    && docker-php-ext-install pdo pdo_mysql pdo_sqlite \
    && rm -rf /var/lib/apt/lists/*

RUN a2dismod mpm_event mpm_worker || true \
    && a2enmod mpm_prefork rewrite

WORKDIR /var/www/html

COPY . /var/www/html

RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 775 /var/www/html/data || true

# Create /app symlink expected by some platforms (Railway/frankenphp)
RUN rm -rf /app || true \
    && ln -s /var/www/html /app

EXPOSE 80

CMD ["apache2-foreground"]
