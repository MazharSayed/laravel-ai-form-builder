FROM php:8.2-cli

# System dependencies + the PHP extensions Laravel and phpoffice need
RUN apt-get update && apt-get install -y \
    git unzip libzip-dev libpng-dev libonig-dev \
    && docker-php-ext-install pdo_mysql zip gd mbstring bcmath

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY . /app

# Install PHP dependencies (production: no dev packages, optimized autoloader)
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts --ignore-platform-reqs

# Render injects $PORT at runtime. On boot: run migrations+seed against Aiven,
# cache config/routes/views, then start the server bound to Render's port.
EXPOSE 8080
CMD php artisan migrate --force --seed \
    && php artisan config:cache \
    && php artisan route:cache \
    && php artisan view:cache \
    && php artisan serve --host 0.0.0.0 --port $PORT
