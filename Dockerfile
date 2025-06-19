FROM php:8.1-apache

WORKDIR /var/www/html

COPY . /var/www/html

RUN chmod 666 /var/www/html/users.json

EXPOSE 80
