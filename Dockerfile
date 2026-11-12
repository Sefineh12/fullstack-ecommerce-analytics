# One container, three programs:
#   nginx   - the only public door (listens on Render's PORT)
#   PHP     - the shop and its API, private on port 8000
#   Flask   - the analytics, private on port 8001 (run by gunicorn)
FROM php:8.4-cli-bookworm

RUN apt-get update \
 && apt-get install -y --no-install-recommends nginx python3 python3-venv gettext-base \
 && rm -rf /var/lib/apt/lists/* \
 && useradd --system --shell /usr/sbin/nologin shop

WORKDIR /app

# Python packages first, so rebuilds stay fast when only the shop code changes.
COPY analytics/requirements.txt analytics/requirements.txt
RUN python3 -m venv /opt/venv \
 && /opt/venv/bin/pip install --no-cache-dir -r analytics/requirements.txt

COPY docker/php.ini /usr/local/etc/php/conf.d/shop.ini
COPY . /app
RUN chmod +x /app/docker/start.sh

# Render sets PORT itself (default 10000). DATA_DIR is where the database and uploads live.
ENV PORT=10000 \
    DATA_DIR=/app/storage \
    PHP_CLI_SERVER_WORKERS=4

EXPOSE 10000
CMD ["/app/docker/start.sh"]