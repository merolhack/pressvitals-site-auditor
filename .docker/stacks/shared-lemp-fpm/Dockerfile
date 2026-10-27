FROM php:8.2-fpm-alpine

# Install build dependencies and libraries
RUN apk add --no-cache \
    freetype-dev \
    libjpeg-turbo-dev \
    libpng-dev \
    libzip-dev \
    oniguruma-dev \
    icu-dev \
    libxml2-dev \
    curl \
    mariadb-client \
    bash

# Install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        mysqli \
        pdo_mysql \
        gd \
        mbstring \
        intl \
        zip \
        opcache \
        exif \
        bcmath

# Download WordPress
ENV WORDPRESS_VERSION=6.6.2
RUN curl -o /tmp/wordpress.tar.gz -fSL "https://wordpress.org/wordpress-${WORDPRESS_VERSION}.tar.gz" \
    && tar -xzf /tmp/wordpress.tar.gz -C /var/www/ \
    && rm /tmp/wordpress.tar.gz \
    && rm -rf /var/www/html \
    && mv /var/www/wordpress /var/www/html \
    && chown -R www-data:www-data /var/www/html

COPY php.ini /usr/local/etc/php/conf.d/highperf-shared.ini

WORKDIR /var/www/html
EXPOSE 9000
CMD ["php-fpm"]
