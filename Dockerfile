FROM php:8.1-apache

WORKDIR /var/www/html

COPY . /var/www/html

# The .htaccess rewrite (every request -> index.php) is only honored when
# mod_rewrite is loaded and AllowOverride permits it. The base image ships
# with AllowOverride None, which silently ignores .htaccess.
RUN a2enmod rewrite \
    && sed -ri 's/^AllowOverride None$/AllowOverride All/' /etc/apache2/apache2.conf \
    && chmod +x /var/www/html/docker-entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/var/www/html/docker-entrypoint.sh"]
CMD ["apache2-foreground"]
