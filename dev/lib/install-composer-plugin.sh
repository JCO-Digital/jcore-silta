#!/usr/bin/env sh
# Dev stack and integration tests only: installs a commercial plugin from its
# vendor's Composer repository into dev/<directory>/plugins/. Gravity Forms and
# ACF Pro both authenticate the same way: HTTP basic auth with the license key
# as the user name and a site URL as the password.
#
# The license key never goes into a tracked file. It comes from the
# environment (GitHub Actions: a repository secret) or from dev/.env (see
# dev/.env.example), and reaches Composer through COMPOSER_AUTH for this one
# command. Without a key it prints a note and exits 0, so the dev stack and the
# integration tests simply run without the plugin.
#
#   lib/install-composer-plugin.sh <directory> <label> <key variable> <site URL variable> <repository host> <main plugin file>
#
# <directory> is relative to dev/ and holds the composer.json; <main plugin
# file> is relative to its plugins/ folder and proves the install worked.
set -eu

if [ $# -ne 6 ]; then
	echo "Usage: $0 <directory> <label> <key variable> <site URL variable> <repository host> <main plugin file>" >&2
	exit 2
fi
dir=$1
label=$2
key_var=$3
url_var=$4
host=$5
main=$6

dev=$(cd "$(dirname "$0")/.." && pwd)
cd "$dev/$dir"

if [ -f "$dev/.env" ]; then
	# Only this plugin's two variables; the environment wins over the file.
	while IFS='=' read -r name value; do
		case $name in
			"$key_var" | "$url_var")
				value=$(printf '%s' "$value" | sed -e 's/^["'\'']//' -e 's/["'\'']$//')
				eval "current=\${$name:-}"
				if [ -z "$current" ]; then
					export "$name=$value"
				fi
				;;
		esac
	done <"$dev/.env"
fi

eval "key=\${$key_var:-}"
eval "site=\${$url_var:-}"
if [ -z "$key" ]; then
	echo "$label: no $key_var (set it in dev/.env, see dev/.env.example); skipped."
	exit 0
fi

if ! command -v composer >/dev/null 2>&1 || ! command -v php >/dev/null 2>&1; then
	echo "$label: composer and php are needed to install it; skipped." >&2
	exit 1
fi

# JSON-encode the credentials with PHP, so no quoting in the key can break it.
COMPOSER_AUTH=$(SILTA_KEY=$key SILTA_SITE=${site:-http://localhost:8090} SILTA_HOST=$host php -r 'echo json_encode( array( "http-basic" => array( getenv( "SILTA_HOST" ) => array( "username" => getenv( "SILTA_KEY" ), "password" => getenv( "SILTA_SITE" ) ) ) ), JSON_UNESCAPED_SLASHES );')
export COMPOSER_AUTH

# No lock file: what the license gives access to decides the version.
if ! composer update --no-interaction --no-progress --prefer-dist --no-dev; then
	echo "$label: Composer could not install it. Check $key_var (an active license with Composer access) and $url_var." >&2
	exit 1
fi

if [ ! -f "plugins/$main" ]; then
	echo "$label: Composer finished, but plugins/$main is missing." >&2
	exit 1
fi
echo "$label: installed in dev/$dir/plugins/${main%%/*}."
