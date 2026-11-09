#!/usr/bin/env sh
# Dev stack and integration tests only: installs ACF Pro into
# dev/acf-pro/plugins/advanced-custom-fields-pro from ACF's Composer repository
# (https://www.advancedcustomfields.com/resources/installing-acf-pro-with-composer/).
#
# The key comes from ACF_PRO_LICENSE_KEY (dev/.env, or the repository secret
# in CI); ACF_PRO_SITE_URL is the basic auth password, a site URL the license
# is active for. See dev/lib/install-composer-plugin.sh. Without a key it
# skips, and the sites keep Secure Custom Fields. setup.sh calls it; to run it
# by hand:
#   dev/acf-pro/install.sh
set -eu
exec "$(dirname "$0")/../lib/install-composer-plugin.sh" \
	acf-pro "ACF Pro" ACF_PRO_LICENSE_KEY ACF_PRO_SITE_URL \
	connect.advancedcustomfields.com advanced-custom-fields-pro/acf.php
