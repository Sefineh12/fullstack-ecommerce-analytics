#!/bin/bash
# Starts nginx, the PHP shop (port 8000) and the Flask analytics (port 8001) in one container.
set -eu

export PORT="${PORT:-10000}"
export DATA_DIR="${DATA_DIR:-/app/storage}"
as_shop="setpriv --reuid=shop --regid=shop --init-groups"

# Folders for the database and uploads (a Render disk starts out owned by root).
mkdir -p "$DATA_DIR/uploads/avatars" "$DATA_DIR/uploads/products" "$DATA_DIR/uploads/receipts"
chown -R shop:shop "$DATA_DIR"
chmod 755 "$DATA_DIR"
chmod 750 "$DATA_DIR/uploads/receipts"

# First start only: create the database and the admin account from ADMIN_EMAIL and ADMIN_PASSWORD.
if [ ! -f "$DATA_DIR/shop.db" ]; then
  $as_shop php /app/database/setup.php
fi

envsubst '${PORT} ${DATA_DIR}' < /app/docker/nginx.conf.template > /etc/nginx/nginx.conf

# PHP and Flask run as the "shop" user and listen only inside the container.
$as_shop php -S 127.0.0.1:8000 -t /app/public /app/router.php &
$as_shop /opt/venv/bin/gunicorn --chdir /app/analytics --bind 127.0.0.1:8001 --workers 2 app:app &
nginx -g 'daemon off;' &

# Stop everything together. If one program stops, the container stops and Render restarts it.
trap 'kill 0' TERM INT
wait -n
exit $?