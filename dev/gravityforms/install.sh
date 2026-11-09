#!/usr/bin/env sh
# Dev stack and integration tests only: installs Gravity Forms into
# dev/gravityforms/plugins/gravityforms from Gravity Forms' Composer repository
# (https://docs.gravityforms.com/install-gravity-plugins-and-add-ons-via-composer/),
# which needs an Elite (or legacy Developer) license.
#
# The key comes from GRAVITY_FORMS_LICENSE_KEY (dev/.env, or the repository
# secret in CI); GRAVITY_FORMS_SITE_URL is the basic auth password. See
# dev/lib/install-composer-plugin.sh. Without a key it skips. setup.sh calls
# it; to run it by hand:
#   dev/gravityforms/install.sh
set -eu
exec "$(dirname "$0")/../lib/install-composer-plugin.sh" \
	gravityforms "Gravity Forms" GRAVITY_FORMS_LICENSE_KEY GRAVITY_FORMS_SITE_URL \
	composer.gravity.io gravityforms/gravityforms.php
