FROM php:8.2-apache

# Install system dependencies + required PHP extensions
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libpq-dev \
    libzip-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    curl \
    && docker-php-ext-install \
        pdo \
        pdo_pgsql \
        pdo_mysql \
        mbstring \
        xml \
        ctype \
        bcmath \
        zip

# Enable Apache rewrite (Laravel routing)
RUN a2enmod rewrite

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy app files
COPY . .

# Set Apache DocumentRoot to /public
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/000-default.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Create minimal .env file if .env.example exists (needed for some composer scripts)
RUN if [ -f .env.example ]; then cp .env.example .env; fi

# Install PHP dependencies (DO NOT run Laravel scripts during build)
RUN composer install --no-dev --optimize-autoloader --no-scripts --no-interaction --ignore-platform-reqs || \
    composer install --no-dev --optimize-autoloader --no-scripts --no-interaction

# Fix permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 775 storage bootstrap/cache

EXPOSE 80
CMD ["apache2-foreground"]
