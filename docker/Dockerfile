FROM php:8.3-cli

RUN apt-get update \
 && apt-get install -y --no-install-recommends libffi-dev unzip git ffmpeg \
 && docker-php-ext-install ffi pcntl sockets \
 && echo "ffi.enable=true" > /usr/local/etc/php/conf.d/ffi.ini \
 && echo "memory_limit=1G" > /usr/local/etc/php/conf.d/memory.ini \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /pkg
