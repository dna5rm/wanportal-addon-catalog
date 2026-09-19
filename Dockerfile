# wanportal-addon-catalog: generic service catalog sidecar (SQLite).
# No secrets, no host ports. Apache proxy reaches this over netops.

FROM alpine:3.21

ENV HOME=/var/www

RUN apk add --no-cache \
      apache2 \
      php84-apache2 \
      php84-sqlite3 \
      php84-pdo \
      php84-pdo_sqlite \
      php84-curl \
      php84-session \
      php84-mbstring \
      curl

# Web root layout:
#   /var/www/localhost/htdocs/catalog        -> full addon tree (URL /catalog/)
#   /var/www/localhost/htdocs/health.php     -> GET /health probe (static; no DB)
COPY app/ /var/www/localhost/htdocs/catalog/
COPY app/health.php /var/www/localhost/htdocs/health.php

# Apache configuration:
#  - mod_rewrite + AllowOverride All (same contract as the ipc sidecar)
#  - drop auto-indexing from the default htdocs Options
#  - conf.d snippet: Authorization header passthrough (the Bearer JWT is
#    read by PHP from HTTP_AUTHORIZATION), /health probe alias, ServerName
RUN sed -i -e 's/^#LoadModule rewrite_module/LoadModule rewrite_module/' /etc/apache2/httpd.conf \
 && sed -i -e 's/AllowOverride None/AllowOverride All/' /etc/apache2/httpd.conf \
 && sed -i -e 's/Options Indexes FollowSymLinks/Options FollowSymLinks/' /etc/apache2/httpd.conf \
 && printf '%s\n' \
      'ServerName localhost' \
      'SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1' \
      'Alias /health /var/www/localhost/htdocs/health.php' \
      > /etc/apache2/conf.d/wanportal-addon-catalog.conf \
 && mkdir -p /var/lib/catalog /var/run/apache2 /var/log/apache2 \
 && chown -R apache:apache /var/www /var/lib/catalog /var/run/apache2 /var/log/apache2

COPY --chmod=755 docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh

USER apache

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
