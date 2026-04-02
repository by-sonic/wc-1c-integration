#!/bin/sh
set -e

echo "Waiting for WordPress files to be ready..."
RETRIES=0
while [ ! -f /var/www/html/wp-includes/version.php ]; do
  RETRIES=$((RETRIES + 1))
  if [ $RETRIES -gt 60 ]; then
    echo "FATAL: WordPress files not ready after 60s"
    exit 1
  fi
  sleep 1
done

echo "Waiting for database..."
RETRIES=0
while ! php /tests/check-db.php >/dev/null 2>&1; do
  RETRIES=$((RETRIES + 1))
  if [ $RETRIES -gt 30 ]; then
    echo "FATAL: Database not ready after 30s"
    exit 1
  fi
  sleep 1
done
echo "Database is ready."

if ! wp core is-installed 2>/dev/null; then
  echo "Installing WordPress..."
  wp core install \
    --url="http://wordpress" \
    --title="WC1C Test" \
    --admin_user=admin \
    --admin_password=admin \
    --admin_email=admin@test.local \
    --skip-email
  echo "WordPress installed."
else
  echo "WordPress already installed."
fi

echo "Setting permalink structure..."
wp rewrite structure '/%postname%/' --hard 2>/dev/null || true

echo "Setup complete. Running tests..."
sh /tests/run-tests.sh
