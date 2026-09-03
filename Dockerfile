FROM php:8.2-apache

# Required PHP extensions:
# - mysqli: MySQL wallet/database connection
# - curl: Supabase Auth/API calls used by get-balance.php, sync-wallet.php,
#         admin-auth.php and other backend endpoints
RUN apt-get update \
    && apt-get install -y --no-install-recommends libcurl4-openssl-dev \
    && docker-php-ext-install mysqli curl \
    && rm -rf /var/lib/apt/lists/*

RUN a2enmod headers rewrite

COPY . /var/www/html/

EXPOSE 80

CMD ["apache2-foreground"]
