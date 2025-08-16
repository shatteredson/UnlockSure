#!/usr/bin/env bash
set -euo pipefail

# --- Utilities & environment ---
normalize_eol () { sed -i 's/\r$//' "$1" 2>/dev/null || true; }
normalize_eol ".devcontainer/postCreate.sh"

WORKSPACE_DIR="${REMOTE_CONTAINERS_WORKSPACE_FOLDER:-/workspaces/${localWorkspaceFolderBasename:-$(ls -1 /workspaces | head -n1)}}"
WP_PATH=/var/www/html

echo "[UnlockSure] Workspace: $WORKSPACE_DIR"
echo "[UnlockSure] Waiting for MySQL..."
until mysqladmin ping -h"${WORDPRESS_DB_HOST%:*}" --silent; do sleep 2; done

cd "$WP_PATH"

# Ensure jq for JSON tasks
if ! command -v jq >/dev/null 2>&1; then
  apt-get update && apt-get install -y jq >/dev/null
fi

# --- WordPress core install (idempotent) ---
if ! wp core is-installed --allow-root; then
  echo "[UnlockSure] Installing WordPress..."
  wp core install --allow-root \
    --url="${WP_HOME:-http://localhost:8080}" \
    --title="UnlockSure Dev" \
    --admin_user="${WP_ADMIN_USER:-admin}" \
    --admin_password="${WP_ADMIN_PASS:-admin}" \
    --admin_email="${WP_ADMIN_EMAIL:-admin@example.com}"

  wp option update blogdescription "SIM unlocking made simple." --allow-root
  wp option update timezone_string "America/New_York" --allow-root
  wp rewrite structure '/%postname%/' --hard --allow-root
  wp rewrite flush --hard --allow-root

  # Core plugins
  wp plugin install elementor --activate --allow-root
  wp plugin install woocommerce --activate --allow-root
  wp plugin install ppom-for-woocommerce --activate --allow-root
  wp plugin install wp-mail-smtp --activate --allow-root

  # WP Mail SMTP → MailHog
  wp option update wp_mail_smtp \
    '{"mail":{"from_email":"no-reply@unlocksure.local","from_name":"UnlockSure","mailer":"smtp","return_path":1},"smtp":{"host":"mailhog","port":1025,"encryption":"none","auth":false}}' \
    --format=json --allow-root
fi

# --- Live dev symlinks for theme & plugin ---
mkdir -p "$WP_PATH/wp-content/themes" "$WP_PATH/wp-content/plugins"
if [ -d "$WORKSPACE_DIR/wp-content/plugins/unlocksure-imei" ] && [ ! -L "$WP_PATH/wp-content/plugins/unlocksure-imei" ]; then
  ln -s "$WORKSPACE_DIR/wp-content/plugins/unlocksure-imei" "$WP_PATH/wp-content/plugins/unlocksure-imei"
fi
if [ -d "$WORKSPACE_DIR/wp-content/themes/unlocksure" ] && [ ! -L "$WP_PATH/wp-content/themes/unlocksure" ]; then
  ln -s "$WORKSPACE_DIR/wp-content/themes/unlocksure" "$WP_PATH/wp-content/themes/unlocksure"
fi
# Activate theme & IMEI plugin if present
if wp theme is-installed unlocksure --allow-root; then
  wp theme activate unlocksure --allow-root || true
fi
wp plugin activate unlocksure-imei --allow-root || true

# --- WooCommerce base pages & settings ---
echo "[UnlockSure] Configuring WooCommerce..."
wp wc tool run install_pages --user="${WP_ADMIN_USER:-admin}" --allow-root || true

# Store defaults
wp option update woocommerce_currency "USD" --allow-root
wp option update woocommerce_default_country "US:FL" --allow-root
wp option update woocommerce_weight_unit "lbs" --allow-root
wp option update woocommerce_dimension_unit "in" --allow-root
wp option update woocommerce_price_thousand_sep "," --allow-root
wp option update woocommerce_price_decimal_sep "." --allow-root
wp option update woocommerce_price_num_decimals "2" --allow-root
# Checkout prefs
wp option update woocommerce_enable_guest_checkout "yes" --allow-root
wp option update woocommerce_enable_signup_and_login_from_checkout "no" --allow-root
# Taxes/shipping (dev-easy)
wp option update woocommerce_calc_taxes "no" --allow-root
wp option update woocommerce_ship_to_countries "disabled" --allow-root

# --- Pages: Home, IMEI Check, Unlock Android/iPhone, Trust & Security ---
create_page () {
  local TITLE="$1"; local SLUG="$2"; local CONTENT="${3:-}"
  local ID
  ID=$(wp post list --post_type=page --name="$SLUG" --field=ID --allow-root || true)
  if [ -z "$ID" ]; then
    ID=$(wp post create --post_type=page --post_title="$TITLE" --post_name="$SLUG" --post_status=publish --porcelain --allow-root)
    [ -n "$CONTENT" ] && wp post update "$ID" --post_content="$CONTENT" --allow-root >/dev/null
    echo "[UnlockSure] Created page: $TITLE (#$ID)"
  else
    echo "[UnlockSure] Page exists: $TITLE (#$ID)"
  fi
  echo "$ID"
}
HOME_ID=$(create_page "Home" "home" "")
IMEI_ID=$(create_page "IMEI Check" "imei-check" "[unlocksure_imei_check]")
ANDROID_PAGE_ID=$(create_page "Unlock Android" "unlock-android" "Order your Android SIM unlock with confidence.")
IPHONE_PAGE_ID=$(create_page "Unlock iPhone" "unlock-iphone" "Order your iPhone SIM unlock with confidence.")
TRUST_ID=$(create_page "Trust & Security" "trust-security" "• Secure checkout (SSL/PCI)\n• Real support via email\n• Clear policies for peace of mind.")

# Static homepage
wp option update show_on_front "page" --allow-root
wp option update page_on_front "$HOME_ID" --allow-root

# --- Menus (Primary & Footer) ---
ensure_menu () {
  local LOC="$1"; local NAME="$2"
  local MENU_ID
  MENU_ID=$(wp menu list --fields=name,term_id,locations --format=json --allow-root | jq -r --arg loc "$LOC" '
    (.[] | select((.locations|tostring)|contains($loc)) | .term_id) // empty')
  if [ -z "$MENU_ID" ]; then
    MENU_ID=$(wp menu create "$NAME" --porcelain --allow-root)
    wp menu location assign "$MENU_ID" "$LOC" --allow-root
  fi
  echo "$MENU_ID"
}
PRIMARY_ID=$(ensure_menu "primary" "Primary")
FOOTER_ID=$(ensure_menu "footer" "Footer")

add_menu_item_post () {
  local MENU_ID="$1"; local TITLE="$2"; local POST_ID="$3"
  local EXISTS
  EXISTS=$(wp menu item list "$MENU_ID" --format=json --allow-root | jq -r --arg oid "$POST_ID" '[.[]|select(.object_id==$oid)]|length')
  if [ "$EXISTS" = "0" ]; then
    wp menu item add-post "$MENU_ID" "$POST_ID" --title="$TITLE" --allow-root >/dev/null
    echo "[UnlockSure] Menu added: $TITLE"
  fi
}
add_menu_item_post "$PRIMARY_ID" "Home" "$HOME_ID"
add_menu_item_post "$PRIMARY_ID" "Unlock Android" "$ANDROID_PAGE_ID"
add_menu_item_post "$PRIMARY_ID" "Unlock iPhone" "$IPHONE_PAGE_ID"
add_menu_item_post "$PRIMARY_ID" "IMEI Check" "$IMEI_ID"
add_menu_item_post "$FOOTER_ID" "Trust & Security" "$TRUST_ID"

# --- Seed Woo products (if none) ---
if ! wp wc product list --allow-root --user="${WP_ADMIN_USER:-admin}" --format=ids | grep -q .; then
  A_ID=$(wp wc product create --allow-root --user="${WP_AD
