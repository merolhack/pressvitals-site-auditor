FROM litespeedtech/openlitespeed:1.8.2-lsphp81

# Install required tools and lsphp81 extensions
RUN apt-get update && apt-get install -y --no-install-recommends \
    curl \
    unzip \
    lsphp81-mysql \
    lsphp81-curl \
    lsphp81-imagick \
    lsphp81-intl \
    lsphp81-opcache \
    mariadb-client \
    && rm -rf /var/lib/apt/lists/*

# Download and place WordPress in /var/www/vhosts/localhost/html
ENV WORDPRESS_VERSION=6.6.2
RUN curl -o /tmp/wordpress.tar.gz -fSL "https://wordpress.org/wordpress-${WORDPRESS_VERSION}.tar.gz" \
    && tar -xzf /tmp/wordpress.tar.gz -C /var/www/vhosts/localhost/ \
    && rm /tmp/wordpress.tar.gz \
    && rm -rf /var/www/vhosts/localhost/html \
    && mv /var/www/vhosts/localhost/wordpress /var/www/vhosts/localhost/html \
    && chown -R nobody:nogroup /var/www/vhosts/localhost/html

WORKDIR /var/www/vhosts/localhost/html
EXPOSE 80 7080
