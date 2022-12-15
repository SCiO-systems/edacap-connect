FROM sciohub/php:8.1-fpm
WORKDIR /app

COPY . .

RUN apt-get update; \
    apt-get -y --no-install-recommends install \
        php8.1-mongodb \
        php8.1-mysql \
        php8.1-redis; \
    apt-get clean; \
    rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* /usr/share/doc/*

RUN composer update --ignore-platform-reqs
