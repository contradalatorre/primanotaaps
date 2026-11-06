# Apache 2.4 con .htaccess e PHP 8.4, come l'hosting Aruba Easy (ADR 0001).
FROM php:8.4-apache
RUN docker-php-ext-install pdo_mysql \
 && a2enmod rewrite
COPY apache.conf /etc/apache2/conf-available/contabilita.conf
RUN a2enconf contabilita
