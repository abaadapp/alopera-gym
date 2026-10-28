#!/usr/bin/env bash
# One-time provisioning of an Ubuntu 24.04 droplet for a dedicated instance.
# Review before running (as root). Not executed against a real droplet yet.
set -euo pipefail

apt-get update
apt-get install -y software-properties-common curl git unzip nginx supervisor certbot python3-certbot-nginx postgresql-client s3cmd
add-apt-repository -y ppa:ondrej/php
apt-get update
apt-get install -y php8.4-fpm php8.4-cli php8.4-pgsql php8.4-mbstring php8.4-xml php8.4-curl php8.4-zip php8.4-gd php8.4-intl php8.4-bcmath php8.4-redis
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
curl -fsSL https://deb.nodesource.com/setup_22.x | bash - && apt-get install -y nodejs

id deploy >/dev/null 2>&1 || adduser --disabled-password --gecos "" deploy
mkdir -p /var/www/alopera-gym/{releases,shared/storage/{app/private,app/public,framework/{cache,sessions,views},logs},shared/backups} /var/www/letsencrypt
chown -R deploy:www-data /var/www/alopera-gym
chmod -R 775 /var/www/alopera-gym/shared/storage

echo 'deploy ALL=(root) NOPASSWD: /bin/systemctl reload php8.4-fpm' > /etc/sudoers.d/deploy-fpm

ufw allow OpenSSH && ufw allow 'Nginx Full' && ufw --force enable
echo "✓ provisioned. Next: copy .env to /var/www/alopera-gym/shared/.env, nginx + supervisor configs, crontab, then scripts/deploy.sh"
