FROM ubuntu:22.04

LABEL maintainer="merolhack"
LABEL description="IONOS Managed WordPress on WAAS emulation environment with decoupled Web (PHP 8.3 FPM) and CLI/Cron (PHP 8.0) runtimes"

ENV DEBIAN_FRONTEND=noninteractive
ENV TZ=UTC

# Install prerequisites and PPA for multi-version PHP
RUN apt-get update && apt-get install -y --no-install-recommends \
    software-properties-common \
    ca-certificates \
    curl \
    gnupg2 \
    tar \
    unzip \
    less \
    mariadb-client \
    apache2 \
    tzdata \
    && add-apt-repository -y ppa:ondrej/php \
    && apt-get update

# Install PHP 8.3 FPM & modules (for Web runtime)
RUN apt-get install -y --no-install-recommends \
    php8.3-fpm \
    php8.3-cli \
    php8.3-mysql \
    php8.3-curl \
    php8.3-mbstring \
    php8.3-xml \
    php8.3-zip \
    php8.3-gd \
    php8.3-intl \
    php8.3-opcache

# Install PHP 8.0 CLI & modules (emulating IONOS default system CLI runtime)
RUN apt-get install -y --no-install-recommends \
    php8.0-cli \
    php8.0-mysql \
    php8.0-curl \
    php8.0-mbstring \
    php8.0-xml \
    php8.0-zip \
    && rm -rf /var/lib/apt/lists/*

# Set default CLI 'php' command to PHP 8.0 (reproducing IONOS CLI vs Web decoupling)
RUN update-alternatives --set php /usr/bin/php8.0

# Install WP-CLI
RUN curl -sSfL https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o /usr/local/bin/wp \
    && chmod +x /usr/local/bin/wp

# Configure Apache with mod_proxy_fcgi and mod_rewrite pointing to PHP 8.3 FPM
RUN a2enmod proxy proxy_fcgi rewrite headers setenvif

RUN cat << 'EOF' > /etc/apache2/sites-available/000-default.conf
<VirtualHost *:80>
    ServerAdmin webmaster@localhost
    DocumentRoot /var/www/html

    <Directory /var/www/html>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # Forward PHP requests to PHP 8.3 FPM socket
    <FilesMatch \.php$>
        SetHandler "proxy:unix:/run/php/php8.3-fpm.sock|fcgi://localhost/"
    </FilesMatch>

    # Header signature typical of IONOS Web
    Header always set X-Powered-By "PHP/8.3.36"

    ErrorLog ${APACHE_LOG_DIR}/error.log
    CustomLog ${APACHE_LOG_DIR}/access.log combined
</VirtualHost>
EOF

# Tune PHP 8.3 FPM settings to match IONOS Managed WordPress tier
RUN sed -i 's/^memory_limit = .*/memory_limit = 512M/' /etc/php/8.3/fpm/php.ini \
    && sed -i 's/^upload_max_filesize = .*/upload_max_filesize = 64M/' /etc/php/8.3/fpm/php.ini \
    && sed -i 's/^post_max_size = .*/post_max_size = 64M/' /etc/php/8.3/fpm/php.ini \
    && sed -i 's/^max_execution_time = .*/max_execution_time = 300/' /etc/php/8.3/fpm/php.ini \
    && sed -i 's/^;max_input_vars = .*/max_input_vars = 2000/' /etc/php/8.3/fpm/php.ini \
    && echo "opcache.enable=1" >> /etc/php/8.3/fpm/conf.d/10-opcache.ini \
    && echo "opcache.memory_consumption=64" >> /etc/php/8.3/fpm/conf.d/10-opcache.ini \
    && echo "opcache.interned_strings_buffer=8" >> /etc/php/8.3/fpm/conf.d/10-opcache.ini \
    && echo "opcache.max_accelerated_files=5000" >> /etc/php/8.3/fpm/conf.d/10-opcache.ini \
    && echo "opcache.revalidate_freq=180" >> /etc/php/8.3/fpm/conf.d/10-opcache.ini

COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

WORKDIR /var/www/html

EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]
