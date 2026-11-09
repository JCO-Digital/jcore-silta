#!/usr/bin/env sh
# Builds the two-site dev stack: installs WordPress on staging and production,
# seeds staging, clones it into production, makes the two drift apart and
# pairs them. Each step is skipped once it has run; start over with ./reset.sh.
# With a Gravity Forms license key in dev/.env (see .env.example), both sites
# also get Gravity Forms and a few forms. With an ACF Pro key they run ACF Pro
# instead of Secure Custom Fields. WooCommerce comes from wordpress.org, and
# paid WooCommerce extensions from the zips in woocommerce/extensions/.
#   docker compose up -d && ./setup.sh
#
# jcore-maailma is installed from its latest GitHub release. Point
# SILTA_MAAILMA_ZIP at another zip URL to test another version, or set it to
# "none" to leave Maailma out. SILTA_WOOCOMMERCE works the same way for
# WooCommerce (a wordpress.org slug, a version's zip URL, or "none").
set -e
cd "$(dirname "$0")"

SILTA_MAAILMA_ZIP=${SILTA_MAAILMA_ZIP:-https://github.com/JCO-Digital/jcore-maailma/releases/latest/download/jcore-maailma.zip}
SILTA_WOOCOMMERCE=${SILTA_WOOCOMMERCE:-woocommerce}
ACF_PRO=acf-pro/plugins/advanced-custom-fields-pro/acf.php

# wp <staging|production> <args...>
wp() {
	site=$1
	shift
	docker compose --progress quiet run --rm -T "cli-$site" wp "$@"
}

# The remote issues a one-time pairing string, the initiator adds it as a connection.
# pair <initiator> <remote> <label> <scopes>
pair() {
	if wp "$1" option get silta_dev_paired >/dev/null 2>&1; then
		echo "$1 is already connected to $2."
		return 0
	fi
	output=$(wp "$2" silta keys create-token --label="$3" --scopes="$4" --porcelain --user=admin) || return 1
	token=$(printf '%s\n' "$output" | sed '/^[[:space:]]*$/d' | tail -n 1)
	wp "$1" silta connections add "$token" --user=admin || return 1
	wp "$1" option update silta_dev_paired "$2" >/dev/null
}

# Gravity Forms and ACF Pro are optional: without a license key the install
# scripts skip them. The directories must exist before Compose mounts them, or
# Docker creates them as root.
mkdir -p gravityforms/plugins acf-pro/plugins woocommerce/extensions
gravityforms/install.sh || echo "WARNING: Gravity Forms could not be installed; carrying on without it."
acf-pro/install.sh || echo "WARNING: ACF Pro could not be installed; carrying on with Secure Custom Fields."

# Over TCP: the MariaDB entrypoint's temporary init server only listens on a socket.
until docker compose exec -T db mariadb -h127.0.0.1 -uwordpress -pwordpress -e 'SELECT 1' production >/dev/null 2>&1; do sleep 2; done
# The WordPress containers copy core into their volumes and write wp-config.php on first start.
for site in staging production; do
	until docker compose exec -T "$site" test -s wp-config.php 2>/dev/null; do sleep 1; done
done

for site in staging production; do
	case $site in
		staging) url=http://localhost:8090 title="Silta Staging" ;;
		production) url=http://localhost:8091 title="Silta Production" ;;
	esac
	echo "== $site: WordPress and plugins"
	wp "$site" core is-installed 2>/dev/null || wp "$site" core install --url="$url" --title="$title" \
		--admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email
	wp "$site" plugin install polylang wordpress-seo --activate
	# ACF Pro when its install script installed it, otherwise Secure Custom
	# Fields (the free fork with the same field types). Never both at once.
	if [ -f "$ACF_PRO" ]; then
		wp "$site" plugin deactivate secure-custom-fields >/dev/null 2>&1 || true
		docker compose --progress quiet run --rm -T "cli-$site" sh -c 'rm -rf wp-content/plugins/advanced-custom-fields-pro && cp -R /acf-pro/advanced-custom-fields-pro wp-content/plugins/advanced-custom-fields-pro'
		wp "$site" plugin activate advanced-custom-fields-pro || echo "WARNING: ACF Pro did not activate on $site."
	else
		wp "$site" plugin deactivate advanced-custom-fields-pro >/dev/null 2>&1 || true
		wp "$site" plugin install secure-custom-fields --activate
	fi
	if [ "$SILTA_MAAILMA_ZIP" != "none" ] && ! wp "$site" plugin is-active jcore-maailma 2>/dev/null; then
		wp "$site" plugin install "$SILTA_MAAILMA_ZIP" --force --activate || echo "WARNING: jcore-maailma could not be installed on $site; carrying on without it."
	fi
	wp "$site" rewrite structure '/%postname%/'
	if [ -f gravityforms/plugins/gravityforms/gravityforms.php ]; then
		docker compose --progress quiet run --rm -T "cli-$site" sh -c 'rm -rf wp-content/plugins/gravityforms && cp -R /gravityforms/gravityforms wp-content/plugins/gravityforms'
		wp "$site" plugin activate gravityforms || echo "WARNING: Gravity Forms did not activate on $site."
	fi
	wp "$site" plugin activate jcore-silta || echo "WARNING: jcore-silta did not activate on $site; carrying on without it."
done

echo "== staging: seed content"
wp staging eval-file /seed/seed.php --user=admin

# Like a real production site created as a copy of staging: same posts, IDs,
# GUIDs and files, with the URLs search-replaced.
if ! wp production option get silta_dev_cloned >/dev/null 2>&1; then
	echo "== Cloning staging into production"
	docker compose exec -T db sh -c '
		set -e
		db="mariadb -h127.0.0.1 -uwordpress -pwordpress"
		mariadb-dump -h127.0.0.1 -uwordpress -pwordpress --single-transaction --no-tablespaces staging > /tmp/staging.sql
		$db -e "DROP DATABASE production; CREATE DATABASE production CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
		$db production < /tmp/staging.sql
		rm /tmp/staging.sql'
	docker compose --progress quiet run --rm copy-uploads
	wp production search-replace 'http://localhost:8090' 'http://localhost:8091' --skip-columns=guid --all-tables --report-changed-only
	# A real production site has its own name; it makes the two easy to tell apart.
	wp production option update blogname "Silta Production" >/dev/null
	wp production option update silta_dev_cloned "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
fi

echo "== Making staging and production diverge"
wp staging eval-file /seed/diverge-staging.php --user=admin
wp production eval-file /seed/diverge-production.php --user=admin

echo "== staging: global content (jcore-maailma)"
wp staging eval-file /seed/maailma.php --user=admin

if [ -f gravityforms/plugins/gravityforms/gravityforms.php ]; then
	echo "== Gravity Forms content"
	wp staging eval-file /seed/gravityforms.php staging --user=admin
	wp production eval-file /seed/gravityforms.php production --user=admin
fi

# WooCommerce creates its shop pages when it is activated, so it comes after
# all other seeding: the post IDs the integration tests use stay the same with
# and without it. Production gets it too, but no products of staging's.
if [ "$SILTA_WOOCOMMERCE" != "none" ]; then
	echo "== WooCommerce"
	for site in staging production; do
		if ! wp "$site" plugin is-active woocommerce >/dev/null 2>&1; then
			wp "$site" plugin install "$SILTA_WOOCOMMERCE" --force --activate || echo "WARNING: WooCommerce could not be installed on $site; carrying on without it."
		fi
		if wp "$site" plugin is-active woocommerce >/dev/null 2>&1; then
			for zip in woocommerce/extensions/*.zip; do
				[ -f "$zip" ] || continue
				wp "$site" plugin install "/woocommerce-extensions/$(basename "$zip")" --force --activate || echo "WARNING: $(basename "$zip") could not be installed on $site."
			done
			wp "$site" eval-file /seed/woocommerce.php "$site" --user=admin
			# Content for an extension: seed/woocommerce/<plugin slug>.php, run
			# only where that plugin is active.
			for seed in seed/woocommerce/*.php; do
				[ -f "$seed" ] || continue
				slug=$(basename "$seed" .php)
				if wp "$site" plugin is-active "$slug" >/dev/null 2>&1; then
					wp "$site" eval-file "/seed/woocommerce/$slug.php" "$site" --user=admin
				fi
			done
		fi
	done
fi

echo "== Pairing"
if wp staging silta --help >/dev/null 2>&1 && wp production silta --help >/dev/null 2>&1; then
	pair staging production Staging read,write || echo "WARNING: pairing staging -> production failed."
	pair production staging Production read || echo "WARNING: pairing production -> staging failed."
else
	echo "Skipped: jcore-silta has no 'wp silta' command yet (or is not active). Run ./setup.sh again once it has."
fi

cat <<'EOF'

Ready.
  Staging:    http://localhost:8090/wp-admin/
  Production: http://localhost:8091/wp-admin/
  Log in as admin / admin (or editor / editor).
  WP-CLI:     docker compose run --rm cli-staging wp ...
              docker compose run --rm cli-production wp ...
EOF
