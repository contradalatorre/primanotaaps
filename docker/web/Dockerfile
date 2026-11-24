# Apache 2.4 con .htaccess e PHP 8.4, come l'hosting Aruba Easy (ADR 0001).
FROM php:8.4-apache
# gd: serve a mPDF (PDF dell'elenco degli autorizzati, poi il PDF/A di deposito); zip: lo zip del backup (#68)
RUN apt-get update \
 && apt-get install -y --no-install-recommends libpng-dev libjpeg-dev libzip-dev \
 && docker-php-ext-configure gd --with-jpeg \
 && docker-php-ext-install pdo_mysql gd zip \
 && rm -rf /var/lib/apt/lists/* \
 && a2enmod rewrite
COPY apache.conf /etc/apache2/conf-available/contabilita.conf
RUN a2enconf contabilita
