FROM php:8.2-apache

# MySQL extensions install (mysqli, pdo)
RUN docker-php-ext-install mysqli pdo pdo_mysql

# Apache rewrite enable
RUN a2enmod rewrite

# PHP project copy to Apache root
COPY . /var/www/html/

# Permissions (uploads / sessions ke liye important)
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
