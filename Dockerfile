# Use the official PHP image
FROM php:8.2-cli

LABEL org.opencontainers.image.source="https://github.com/bjalt/byd-reader"

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    && docker-php-ext-install zip

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set environment variable for Composer
ENV COMPOSER_ALLOW_SUPERUSER=1

# Set working directory
WORKDIR /app

# Degine build arguments
ARG APP_ENV

# Set environment variables from build arguments
ENV APP_ENV=${APP_ENV}

# Copy project files
COPY . .

# Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader

# Set the entry point to run the Symfony command
ENTRYPOINT ["php", "bin/console", "app:read"]