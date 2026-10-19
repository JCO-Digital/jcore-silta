#!/usr/bin/env sh
# Dev stack and integration tests only: installs Gravity Forms into
# dev/gravityforms/plugins/gravityforms from Gravity Forms' Composer repository
# (https://docs.gravityforms.com/install-gravity-plugins-and-add-ons-via-composer/),
# which needs an Elite (or legacy Developer) license.
#
# The license key never goes into a tracked file. It comes from the
# environment (GitHub Actions: the GRAVITY_FORMS_LICENSE_KEY secret) or from
# dev/.env (see dev/.env.example), and reaches Composer through COMPOSER_AUTH
# for this one command. The repository uses HTTP basic auth: the license key
# is the user name and a site URL the password.
#
# Without a key it prints a note and exits 0, so the dev stack and the
# integration tests simply run without Gravity Forms. setup.sh calls it; to
# run it by hand:
#   dev/gravityforms/install.sh
set -eu
cd "$(dirname "$0")"

if [ -f ../.env ]; then
	# Only the Gravity Forms variables; the environment wins over the file.
	while IFS='=' read -r name value; do
		case $name in
			GRAVITY_FORMS_LICENSE_KEY | GRAVITY_FORMS_SITE_URL)
				value=$(printf '%s' "$value" | sed -e 's/^["'\'']//' -e 's/["'\'']$//')
				eval "current=\${$name:-}"
				if [ -z "$current" ]; then
					export "$name=$value"
				fi
				;;
		esac
	done <../.env
fi

if [ -z "${GRAVITY_FORMS_LICENSE_KEY:-}" ]; then
	echo "Gravity Forms: no GRAVITY_FORMS_LICENSE_KEY (set it in dev/.env, see dev/.env.example); skipped."
	exit 0
fi

if ! command -v composer >/dev/null 2>&1 || ! command -v php >/dev/null 2>&1; then
	echo "Gravity Forms: composer and php are needed to install it; skipped." >&2
	exit 1
fi

# JSON-encode the credentials with PHP, so no quoting in the key can break it.
COMPOSER_AUTH=$(php -r 'echo json_encode( array( "http-basic" => array( "composer.gravity.io" => array( "username" => getenv( "GRAVITY_FORMS_LICENSE_KEY" ), "password" => getenv( "GRAVITY_FORMS_SITE_URL" ) ?: "http://localhost:8090" ) ) ), JSON_UNESCAPED_SLASHES );')
export COMPOSER_AUTH

# No lock file: what the license gives access to decides the version.
if ! composer update --no-interaction --no-progress --prefer-dist --no-dev; then
	echo "Gravity Forms: Composer could not install it. Check GRAVITY_FORMS_LICENSE_KEY (an active Elite or Developer license) and GRAVITY_FORMS_SITE_URL." >&2
	exit 1
fi

if [ ! -f plugins/gravityforms/gravityforms.php ]; then
	echo "Gravity Forms: Composer finished, but plugins/gravityforms/gravityforms.php is missing." >&2
	exit 1
fi
echo "Gravity Forms: installed in dev/gravityforms/plugins/gravityforms."
