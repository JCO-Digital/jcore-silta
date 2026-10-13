#!/usr/bin/env sh
# Builds the two-site dev stack: installs WordPress on staging and production,
# seeds staging, clones it into production, makes the two drift apart and
# pairs them. Each step is skipped once it has run; start over with ./reset.sh.
#   docker compose up -d && ./setup.sh
set -e
cd "$(dirname "$0")"

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
	wp "$site" plugin install secure-custom-fields polylang wordpress-seo --activate
	wp "$site" rewrite structure '/%postname%/'
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
