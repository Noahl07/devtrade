FROM dunglas/frankenphp:latest-php8.2

# Extensions PHP nécessaires pour Symfony + PostgreSQL
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

# Node.js pour les assets
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs \
    && apt-get clean

# Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Copier les fichiers du projet
COPY . .

# Installer les dépendances PHP
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Installer les dépendances Node et builder les assets
RUN npm ci && npm run build

# Cache Symfony prod
RUN APP_ENV=prod php bin/console cache:clear --no-warmup \
    && APP_ENV=prod php bin/console cache:warmup

# Permissions
RUN chown -R www-data:www-data var/ public/

# Caddyfile
COPY Caddyfile /etc/caddy/Caddyfile

EXPOSE 8080

CMD ["sh", "-c", "php bin/console doctrine:migrations:migrate --no-interaction --env=prod && frankenphp run --config /etc/caddy/Caddyfile"]
