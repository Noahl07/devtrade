FROM dunglas/frankenphp:latest-php8.2

RUN install-php-extensions pdo pdo_pgsql pgsql mbstring xml curl zip intl opcache tokenizer ctype session apcu

RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs && apt-get clean

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY . .

RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

RUN npm ci && npm run build

# Pas de cache:warmup ici - pas de DB disponible au build
RUN APP_ENV=prod APP_SECRET="tmp" php bin/console assets:install --env=prod || true

COPY Caddyfile /etc/caddy/Caddyfile
EXPOSE 8080

# Tout se passe au démarrage avec les vraies variables Railway
CMD sh -c " \
    php bin/console cache:clear --env=prod && \
    php bin/console cache:warmup --env=prod && \
    php bin/console doctrine:migrations:migrate --no-interaction --env=prod && \
    frankenphp run --config /etc/caddy/Caddyfile"