# Composer + Node per dipendenze, stili e test; stesso PHP 8.4 di Aruba.
FROM php:8.4-cli
RUN apt-get update \
 && apt-get install -y --no-install-recommends git unzip libzip-dev \
 && docker-php-ext-install pdo_mysql zip pcntl \
 && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY --from=node:22-bookworm-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:22-bookworm-slim /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm
WORKDIR /app
