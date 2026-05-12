FROM php:8.2-apache

# Install system dependencies + required PHP extensions
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libpq-dev \
    libzip-dev \
    libonig-dev \
    libxml2-dev \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    zip \
    curl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo \
        pdo_pgsql \
        pdo_mysql \
        mbstring \
        xml \
        ctype \
        bcmath \
        zip \
        gd

# Enable Apache rewrite (Laravel routing)
RUN a2enmod rewrite

# Raise PHP limits so multi-file vendor registrations (PDFs for OEM cert,
# authorization letter, bank reference, etc.) don't silently get dropped
# or time out mid-upload. Defaults of upload_max_filesize=2M, post_max_size=8M
# and max_execution_time=30s caused the browser-side CORS-looking errors
# even though small curl payloads succeeded.
RUN { \
        echo "upload_max_filesize = 50M"; \
        echo "post_max_size = 100M"; \
        echo "memory_limit = 512M"; \
        echo "max_execution_time = 180"; \
        echo "max_input_time = 180"; \
        echo "max_file_uploads = 30"; \
        echo "output_buffering = 4096"; \
    } > /usr/local/etc/php/conf.d/zz-supply-chain-overrides.ini

# Mirror the long-request limits to Apache so it does not cut the connection
# before PHP finishes writing the response (which would surface in the
# browser as a CORS / NetworkError).
RUN { \
        echo "Timeout 300"; \
        echo "KeepAliveTimeout 30"; \
        echo "LimitRequestBody 209715200"; \
    } > /etc/apache2/conf-available/zz-supply-chain.conf \
    && a2enconf zz-supply-chain

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

# Fix permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 775 storage bootstrap/cache

# Install PHP dependencies (remove composer.lock to allow fresh install of new AWS SDK)
RUN rm -f composer.lock && \
    composer install --no-dev --optimize-autoloader --no-scripts --no-interaction

EXPOSE 80
CMD ["apache2-foreground"]
