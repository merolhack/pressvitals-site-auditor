FROM php:8.3-apache

# Install OS libraries
RUN apt-get update && apt-get install -y --no-install-recommends \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    libonig-dev \
    libicu-dev \
    libxml2-dev \
    default-mysql-client \
    curl \
    unzip \
    && rm -rf /var/lib/apt/lists/*

# Install full PHP extension suite
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

# Enable Apache modules
RUN a2enmod rewrite headers expires remoteip

# Download WordPress core
ENV WORDPRESS_VERSION=6.6.2
RUN curl -o /tmp/wordpress.tar.gz -fSL "https://wordpress.org/wordpress-${WORDPRESS_VERSION}.tar.gz" \
    && tar -xzf /tmp/wordpress.tar.gz -C /var/www/ \
    && rm /tmp/wordpress.tar.gz \
    && rm -rf /var/www/html \
    && mv /var/www/wordpress /var/www/html \
    && chown -R www-data:www-data /var/www/html

COPY php.ini /usr/local/etc/php/conf.d/dedicated-lamp.ini

WORKDIR /var/www/html
EXPOSE 80
CMD ["apache2-foreground"]
