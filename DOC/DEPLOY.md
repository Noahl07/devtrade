# Déploiement devtrade sur VPS Ubuntu

## Prérequis VPS
- Ubuntu 22.04 LTS
- 2 Go RAM minimum (4 Go recommandé)
- PHP 8.2+, Nginx, PostgreSQL, Composer, Node.js

---

## 1. Préparer le serveur

```bash
# Mise à jour système
sudo apt update && sudo apt upgrade -y

# Dépendances essentielles
sudo apt install -y git curl unzip nginx postgresql postgresql-contrib \
    software-properties-common apt-transport-https

# PHP 8.2 + extensions Symfony
sudo add-apt-repository ppa:ondrej/php -y
sudo apt update
sudo apt install -y php8.2 php8.2-fpm php8.2-cli php8.2-pgsql php8.2-mbstring \
    php8.2-xml php8.2-curl php8.2-zip php8.2-intl php8.2-opcache php8.2-redis

# Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# Node.js 20 LTS
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt install -y nodejs
```

---

## 2. Base de données PostgreSQL

```bash
sudo -u postgres psql

# Dans psql :
CREATE DATABASE devtrade;
CREATE USER devtrade_user WITH PASSWORD 'ton_mot_de_passe_fort';
GRANT ALL PRIVILEGES ON DATABASE devtrade TO devtrade_user;
\q
```

---

## 3. Déployer le projet

```bash
# Créer le dossier
sudo mkdir -p /var/www/devtrade
sudo chown -R www-data:www-data /var/www/devtrade

# Cloner le projet (ou upload via SFTP/rsync)
cd /var/www/devtrade
sudo -u www-data git clone https://github.com/ton-repo/devtrade.git .

# Créer le .env.local (NE JAMAIS commiter ce fichier)
sudo nano .env.local
```

Contenu de `.env.local` :
```env
APP_ENV=prod
APP_SECRET=une_chaine_aleatoire_de_32_caracteres_minimum

DATABASE_URL="postgresql://devtrade_user:ton_mot_de_passe@127.0.0.1:5432/devtrade?serverVersion=15&charset=utf8"

MAILER_DSN=smtp://laurfran3@gmail.com:ton_app_password@smtp.gmail.com:587?encryption=tls
```

```bash
# Installer les dépendances
sudo -u www-data composer install --no-dev --optimize-autoloader

# Assets
sudo -u www-data npm ci
sudo -u www-data npm run build

# BDD
sudo -u www-data php bin/console doctrine:migrations:migrate --no-interaction
sudo -u www-data php bin/console doctrine:fixtures:load --no-interaction

# Cache prod
sudo -u www-data php bin/console cache:clear --env=prod
sudo -u www-data php bin/console cache:warmup --env=prod

# Permissions
sudo chown -R www-data:www-data var/
sudo chmod -R 775 var/
```

---

## 4. Configurer Nginx

```bash
sudo nano /etc/nginx/sites-available/devtrade
```

```nginx
server {
    listen 80;
    server_name devtrade.fr www.devtrade.fr;
    root /var/www/devtrade/public;

    location / {
        try_files $uri /index.php$is_args$args;
    }

    location ~ ^/index\.php(/|$) {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_split_path_info ^(.+\.php)(/.*)$;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
        internal;
    }

    location ~ \.php$ {
        return 404;
    }

    # Cache assets statiques
    location ~* \.(css|js|png|jpg|jpeg|gif|ico|svg|woff|woff2)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }

    error_log /var/log/nginx/devtrade_error.log;
    access_log /var/log/nginx/devtrade_access.log;
}
```

```bash
sudo ln -s /etc/nginx/sites-available/devtrade /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

---

## 5. SSL avec Let's Encrypt (HTTPS gratuit)

```bash
sudo apt install certbot python3-certbot-nginx -y
sudo certbot --nginx -d devtrade.fr -d www.devtrade.fr

# Renouvellement automatique (déjà configuré par certbot)
sudo crontab -e
# Ajouter :
# 0 3 * * * certbot renew --quiet
```

---

## 6. Optimisation PHP-FPM

```bash
sudo nano /etc/php/8.2/fpm/pool.d/www.conf
```

```ini
pm = dynamic
pm.max_children = 20
pm.start_servers = 5
pm.min_spare_servers = 3
pm.max_spare_servers = 10
```

```bash
# OPcache
sudo nano /etc/php/8.2/fpm/conf.d/10-opcache.ini
```
```ini
opcache.enable=1
opcache.memory_consumption=256
opcache.max_accelerated_files=20000
opcache.validate_timestamps=0
opcache.revalidate_freq=0
```

```bash
sudo systemctl restart php8.2-fpm
```

---

## 7. Makefile pour les mises à jour

```makefile
# Makefile à la racine du projet

deploy:
	git pull origin main
	composer install --no-dev --optimize-autoloader
	npm ci && npm run build
	php bin/console doctrine:migrations:migrate --no-interaction
	php bin/console cache:clear --env=prod
	php bin/console cache:warmup --env=prod
	sudo chown -R www-data:www-data var/
	@echo "✓ Déploiement terminé"

cache:
	php bin/console cache:clear --env=prod
	php bin/console cache:warmup --env=prod

logs:
	tail -f var/log/prod.log

.PHONY: deploy cache logs
```

---

## 8. Vérifications finales

```bash
# Tester la config Symfony
php bin/console about --env=prod

# Vérifier les routes
php bin/console debug:router --env=prod

# Test Nginx
curl -I https://devtrade.fr
```

---

## Hébergeurs recommandés

| Hébergeur | Prix/mois | Notes |
|-----------|-----------|-------|
| **Hetzner** | ~4€ (CX22) | Meilleur rapport qualité/prix, data center EU |
| **OVH VPS** | ~6€ | Français, bon support |
| **DigitalOcean** | ~6$ (Droplet) | Interface simple, bonne doc |
| **Scaleway** | ~~3€ | Français, RGPD natif |

**Recommandation : Hetzner CX22** — 2 vCPU, 4 Go RAM, 40 Go SSD NVMe, 4€/mois. Amplement suffisant pour démarrer.
