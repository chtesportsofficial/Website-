FROM php:8.2-apache

RUN docker-php-ext-install mysqli

RUN a2enmod headers rewrite

COPY . /var/www/html/

EXPOSE 80

CMD ["apache2-foreground"]
