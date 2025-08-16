#!/usr/bin/env bash
set -e

WP_PATH=/var/www/html
cd $WP_PATH

echo "Waiting for MySQL..."
until mysqladmin ping -h"${WORDPRESS_DB_HOST%:*}" --silent; do sleep 2; done

# Install core if needed
if ! wp core is-installed --allow-root; then
  echo "Installing WordPress..."
  wp core install --allow-root \
    --url="${WP_HOME:-http://localhost:8080}" \
    --title="UnlockSure Dev" \
    --admin_user="${WP_ADMIN_USER:-admin}" \
    --admin_password="${WP_ADMIN_PASS:-admin}" \
    --admin_email="${WP_ADMIN_EMAIL:-admin@example.com}"

  wp rewrite structure '/%postname%/' --hard --allow-root
  wp rewrite flush --hard --allow-root

  # Plugins
  wp plugin install elementor --activate --allow-root
  wp plugin install woocommerce --activate --allow-root
  wp plugin install ppom-for-woocommerce --activate --allow-root
  wp plugin install wp-mail-smtp --activate --allow-root

  # MailHog config
  wp option update wp_mail_smtp \
    '{"mail":{"from_email":"no-reply@unlocksure.local","from_name":"UnlockSure","mailer":"smtp","return_path":1},"smtp":{"host":"mailhog","port":1025,"encryption":"none","auth":false}}' \
    --format=json --allow-root

  # Activate UnlockSure theme if present
  if [ -d "$WP_PATH/wp-content/themes/unlocksure" ]; then
    wp theme activate unlocksure --allow-root || true
  fi
fi

# Symlink repo theme/plugin into container for live dev
if [ -d "/workspace/wp-content/plugins/unlocksure-imei" ] && [ ! -L "$WP_PATH/wp-content/plugins/unlocksure-imei" ]; then
  ln -s /workspace/wp-content/plugins/unlocksure-imei $WP_PATH/wp-content/plugins/unlocksure-imei
fi
if [ -d "/workspace/wp-content/themes/unlocksure" ] && [ ! -L "$WP_PATH/wp-content/themes/unlocksure" ]; then
  ln -s /workspace/wp-content/themes/unlocksure $WP_PATH/wp-content/themes/unlocksure
fi

# Ensure IMEI plugin active
wp plugin activate unlocksure-imei --allow-root || true

# Woo basic pages
wp wc tool run install_pages --user="${WP_ADMIN_USER:-admin}" --allow-root || true

# Seed simple products if none
if ! wp wc product list --allow-root --user="${WP_ADMIN_USER:-admin}" --format=ids | grep -q .; then
  wp wc product create --allow-root --user="${WP_ADMIN_USER:-admin}" --porcelain \
    --name="Unlock Android" --type=simple --status=publish --regular_price=29.00 >/dev/null 2>&1 || true
  wp wc product create --allow-root --user="${WP_ADMIN_USER:-admin}" --porcelain \
    --name="Unlock iPhone" --type=simple --status=publish --regular_price=39.00 >/dev/null 2>&1 || true
fi

echo "postCreate complete."
