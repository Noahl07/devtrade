FROM dunglas/frankenphp:latest-php8.2
RUN install-php-extensions pdo pdo_pgsql pgsql mbstring xml curl zip intl opcache tokenizer ctype session apcu
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs && apt-get clean
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY . .
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts
RUN npm ci && npm run build

# ✅ Sans DATABASE_URL figé - le cache sera regénéré au démarrage
RUN APP_ENV=prod APP_SECRET="tmp" php bin/console cache:clear --no-warmup || true

COPY Caddyfile /etc/caddy/Caddyfile
EXPOSE 8080

# ✅ Warmup + migration au démarrage avec les vraies variables Railway
CMD ["sh", "-c", "php bin/console cache:warmup --env=prod && php bin/console doctrine:migrations:migrate --no-interaction --env=prod && frankenphp run --config /etc/caddy/Caddyfile"]