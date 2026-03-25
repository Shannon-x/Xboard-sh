#!/bin/bash

rm -rf composer.phar
wget https://github.com/composer/composer/releases/latest/download/composer.phar -O composer.phar
php composer.phar install -vvv

# Build admin frontend
if command -v node &> /dev/null && command -v npm &> /dev/null; then
  echo "Building admin frontend..."
  ADMIN_TMP=$(mktemp -d)
  git clone --depth 1 https://github.com/Shannon-x/XBoard-admin.git "$ADMIN_TMP"
  cd "$ADMIN_TMP"
  bash "$(dirname "$0")/scripts/patch-admin.sh" || bash "$(cd -- "$(dirname "$0")" && pwd)/scripts/patch-admin.sh"
  npm install && npm run build
  cd -
  rm -rf public/assets/admin
  cp -r "$ADMIN_TMP/dist/" public/assets/admin/
  rm -rf "$ADMIN_TMP"
else
  echo "Warning: Node.js not found, skipping admin frontend build."
  echo "Admin panel may not be available."
fi

php artisan xboard:install

if [ -f "/etc/init.d/bt" ] || [ -f "/.dockerenv" ]; then
  chown -R www:www $(pwd);
fi

if [ -d ".docker/.data" ]; then
  chmod -R 777 .docker/.data
fi