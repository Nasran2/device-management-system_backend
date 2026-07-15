FROM php:8.3-fpm-alpine
RUN apk add --no-cache $PHPIZE_DEPS icu-dev libzip-dev oniguruma-dev mysql-client git unzip && docker-php-ext-install bcmath intl mbstring pdo_mysql zip opcache
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist
COPY . .
RUN composer dump-autoload --optimize && chown -R www-data:www-data storage bootstrap/cache
CMD ["php-fpm"]
