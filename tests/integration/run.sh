#!/bin/sh
# Banc d'intégration : WordPress + MariaDB + faux Bricks, puis scénarios.
# Lancé par docker compose (tests/integration/docker-compose.yml).
set -e
export WP_CLI_PHP_ARGS="-d memory_limit=512M"
cd /var/www/html

if [ ! -f wp-load.php ]; then
	php -d memory_limit=512M /usr/local/bin/wp core download --version="${WP_VERSION:-latest}" --quiet
fi
rm -f wp-config.php
wp config create --dbname=wp --dbuser=wp --dbpass=wp --dbhost=db --skip-check --quiet --extra-php <<'PHP'
define( 'DISABLE_WP_CRON', true );
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', '/tmp/wp-debug.log' );
define( 'WP_DEBUG_DISPLAY', false );
PHP
wp db reset --yes --quiet
wp core install --url=http://localhost:8080 --title="Lümia test" --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email --quiet

rm -rf wp-content/themes/bricks wp-content/plugins/lumia-staging
cp -r /tests/fixtures/bricks-stub wp-content/themes/bricks
mkdir -p wp-content/plugins/lumia-staging
cp -r /plugin/lumia-staging.php /plugin/uninstall.php /plugin/src /plugin/assets wp-content/plugins/lumia-staging/
[ -d /plugin/build ] && cp -r /plugin/build wp-content/plugins/lumia-staging/

wp theme activate bricks --quiet
wp rewrite structure '/%postname%/' --quiet
wp plugin activate lumia-staging --quiet

php -S 0.0.0.0:8080 -t /var/www/html /tests/integration/router.php > /tmp/server.log 2>&1 &
sleep 1

echo "=== Scénarios (WP-CLI) ==="
wp eval-file /tests/integration/scenario.php --user=admin || { echo "Scénarios en échec"; grep -v Deprecated /tmp/wp-debug.log 2>/dev/null | head -40; exit 1; }
echo "=== WP-CLI ==="
wp lmv list --user=admin
echo "=== HTTP ==="
status=0
php /tests/integration/http.php || status=$?

if [ -s /tmp/wp-debug.log ]; then
	echo "=== wp-debug.log ==="
	grep -v "Deprecated" /tmp/wp-debug.log | head -50 || true
fi
exit $status
