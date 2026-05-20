FROM php:8.2-cli

# System dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    unzip \
    && rm -rf /var/lib/apt/lists/*

# PHP extensions required by Laravel 11
RUN docker-php-ext-install \
    pdo \
    pdo_mysql \
    bcmath \
    opcache

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/backend

# Install dependencies first (layer cache)
COPY backend/composer.json backend/composer.lock* ./
RUN composer install --no-scripts --no-autoloader --prefer-dist

# Copy application
COPY backend/ .

RUN composer dump-autoload --optimize

EXPOSE 8000
