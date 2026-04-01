FROM dunglas/frankenphp:latest-php8.2

RUN install-php-extensions \
    pdo \
    pdo_pgsql \
    pgsql \
    mbstring \
    xml \
    curl \
    zip \
    intl \
    opcache \
    tokenizer \
    ctype \
    session \
    apcu

RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs \
    && apt-get clean

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY . .

# Skip scripts qui tentent de contacter la BDD au build
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

RUN npm ci && npm run build

# Cache sans BDD
RUN APP_ENV=prod DATABASE_URL="postgresql://x:x@localhost/x" php bin/console cache:warmup --no-debug || true

RUN chown -R www-data:www-data var/ public/ || true

COPY Caddyfile /etc/caddy/Caddyfile

EXPOSE 8080

CMD ["sh", "-c", "php bin/console doctrine:migrations:migrate --no-interaction --env=prod && frankenphp run --config /etc/caddy/Caddyfile"]