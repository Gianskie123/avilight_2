FROM php:8.2-apache

RUN docker-php-ext-install pdo pdo_mysql pdo_sqlite

RUN a2enmod rewrite

WORKDIR /var/www/html

COPY . /var/www/html

RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 775 /var/www/html/data || true

# Create /app symlink expected by some platforms (Railway/frankenphp)
RUN rm -rf /app || true \
    && ln -s /var/www/html /app

EXPOSE 80

CMD ["apache2-foreground"]
