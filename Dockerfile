FROM php:8.3-apache
RUN apt-get update && apt-get install -y libonig-dev libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev unzip \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j$(nproc) pdo_mysql mbstring zip gd \
 && a2enmod rewrite headers \
 && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN printf "upload_max_filesize=10M\npost_max_size=12M\nmemory_limit=256M\n" > /usr/local/etc/php/conf.d/uploads.ini
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
