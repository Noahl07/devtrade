FROM dunglas/frankenphp:1-php8.4

RUN install-php-extensions pdo pdo_pgsql pgsql mbstring xml curl zip intl opcache tokenizer ctype session apcu

RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs && apt-get clean

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY . .

# php.ini de production (délais 30/60 s, expose_php et display_errors désactivés)
COPY php.ini /usr/local/etc/php/conf.d/custom.ini

# Requis : Composer tourne en root ; sans cette variable, le plugin symfony/runtime n'est pas chargé.
ENV COMPOSER_ALLOW_SUPERUSER=1
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

RUN npm ci && npm run build

RUN APP_ENV=prod APP_SECRET="tmp" php bin/console assets:install --env=prod || true

COPY Caddyfile /etc/caddy/Caddyfile
EXPOSE 8080

ENV APP_ENV=prod APP_DEBUG=0

CMD sh -c " \
    php bin/console cache:clear --env=prod && \
    php bin/console cache:warmup --env=prod && \
    php bin/console doctrine:migrations:migrate --no-interaction --env=prod && \
    frankenphp run --config /etc/caddy/Caddyfile"