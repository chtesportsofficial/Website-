FROM php:8.2-apache

# Required PHP extensions:
# - pdo_pgsql: Supabase Postgres wallet/database connection (migrated from
#   filess.io MySQL after repeated outages there — see db.php)
# - curl: Supabase Auth/API calls used by get-balance.php, sync-wallet.php,
#         admin-auth.php and other backend endpoints
#
# mysqli/pdo_mysql are no longer needed since the wallet DB moved to
# Postgres, but kept here (commented) in case any old script still
# references mysqli during the file-by-file migration.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libcurl4-openssl-dev libpq-dev \
    && docker-php-ext-install pdo_pgsql curl \
    && rm -rf /var/lib/apt/lists/*

RUN a2enmod headers rewrite

COPY . /var/www/html/

EXPOSE 80

CMD ["apache2-foreground"]
