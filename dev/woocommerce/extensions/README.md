# WooCommerce extensions for the dev stack

Put the zips of paid WooCommerce extensions here, as downloaded from woocommerce.com (*My Account → Downloads*), for example `woocommerce-product-bundles.zip`. `dev/setup.sh` installs and activates every zip in this folder on both sites, after WooCommerce, and installs them again with `--force` on every run, so replacing a zip upgrades the plugin.

Everything in this folder except this README is git-ignored. The repository is public: never commit an extension.

woocommerce.com has no Composer repository that takes a license key, so these can't be installed the way Gravity Forms and ACF Pro are, and CI runs without them. The integration tests skip the checks that need them.
