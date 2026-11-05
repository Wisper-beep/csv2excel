FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --optimize-autoloader --ignore-platform-req=ext-zip

FROM php:8.3-apache
RUN apt-get update \
    && apt-get install -y --no-install-recommends libzip-dev \
    && docker-php-ext-install zip \
    && rm -rf /var/lib/apt/lists/* \
    && { \
        echo 'upload_max_filesize=50M'; \
        echo 'post_max_size=60M'; \
        echo 'memory_limit=256M'; \
        echo 'max_execution_time=300'; \
        echo 'expose_php=Off'; \
    } > /usr/local/etc/php/conf.d/csv2excel.ini

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf

WORKDIR /var/www/html
COPY --from=vendor /app/vendor ./vendor
COPY config.php ./
COPY src ./src
COPY templates ./templates
COPY public ./public

EXPOSE 80
