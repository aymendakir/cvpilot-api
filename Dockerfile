FROM php:8.3-apache
# poppler-utils: pdftotext/pdfinfo/pdfimages read CV PDFs for the ATS checker (SPEC-ats.md §4.1, docs/DEPLOYMENT.md).
# libicu-dev + intl: Unicode normalization for keyword matching.
RUN apt-get update && apt-get install -y libonig-dev libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev libicu-dev unzip \
    poppler-utils \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j$(nproc) pdo_mysql mbstring zip gd intl \
 && a2enmod rewrite headers \
 && rm -rf /var/lib/apt/lists/*
RUN printf 'upload_max_filesize=16M\npost_max_size=20M\nmax_file_uploads=5\n' > /usr/local/etc/php/conf.d/uploads.ini
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY . .
RUN mkdir -p bootstrap/cache storage/framework/cache storage/framework/sessions storage/framework/views storage/logs && composer install --no-dev --optimize-autoloader --no-interaction && chown -R www-data:www-data storage bootstrap/cache
RUN sed -i 's|/var/www/html|/var/www/html/public|g' /etc/apache2/sites-available/000-default.conf \
 && sed -ri 's!Listen 80!Listen 8080!' /etc/apache2/ports.conf \
 && sed -ri 's!<VirtualHost \*:80>!<VirtualHost *:8080>!' /etc/apache2/sites-available/000-default.conf \
 && echo 'ServerName localhost' >> /etc/apache2/apache2.conf
COPY docker-entrypoint.sh /usr/local/bin/cvpilot-entrypoint
RUN chmod +x /usr/local/bin/cvpilot-entrypoint
EXPOSE 8080
ENTRYPOINT ["cvpilot-entrypoint"]
CMD ["apache2-foreground"]
