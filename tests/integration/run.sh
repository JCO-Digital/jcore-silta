#!/usr/bin/env bash
# Two-site integration tests: real pushes and pulls between the dev stack's
# staging (localhost:8090) and production (localhost:8091), checked on the
# other side with WP-CLI. Build the plugin first (pnpm build), then:
#
#   tests/integration/run.sh             # fresh stack (dev/reset.sh), then the tests
#   tests/integration/run.sh --no-reset  # reuse a stack set up with dev/setup.sh
#
# The scenarios build on each other and expect the seeded content; run them on
# a fresh stack. The last one simulates a database clone and leaves the
# sites unpaired.
set -uo pipefail
cd "$(dirname "$0")/../../dev"

if [ "${1:-}" != "--no-reset" ]; then
	./reset.sh >/dev/null || { echo "dev/reset.sh failed"; exit 1; }
fi
# setup.sh leaves them running; this is for a stack that was stopped since.
docker compose --progress quiet up -d cli-staging cli-production || { echo "the CLI containers did not start"; exit 1; }

PROD=localhost:8091
STAGE=localhost:8090
failures=0

# wp <staging|production> <args...>, in the site's idle CLI container.
wp() {
	site=$1
	shift
	docker compose exec -T "cli-$site" wp --user=admin "$@" 2>/dev/null
}

# check <name> <expected> <actual>
check() {
	if [ "$2" = "$3" ]; then
		echo "ok   $1"
	else
		echo "FAIL $1"
		echo "     expected: $2"
		echo "     actual:   $3"
		failures=$((failures + 1))
	fi
}

# contains <name> <needle> <haystack>
contains() {
	case "$3" in
		*"$2"*) echo "ok   $1" ;;
		*)
			echo "FAIL $1: [$2] not found in:"
			printf '%s\n' "$3" | sed 's/^/     /' | head -20
			failures=$((failures + 1))
			;;
	esac
}

# post_id <site> <slug> [post type]
post_id() {
	wp "$1" post list --name="$2" --post_type="${3:-post}" --post_status=any --field=ID --lang= | head -1
}

# active_on_both <plugin slug>: whether a plugin is active on both sites.
# Checks for optional plugins (paid WooCommerce extensions) skip without it.
active_on_both() {
	wp staging plugin is-active "$1" && wp production plugin is-active "$1"
}

last_job() {
	wp staging silta jobs --format=json | php -r 'echo json_decode( stream_get_contents( STDIN ), true )[0]["id"] ?? "";'
}

# The ACF checks below run against whichever ACF the stack has: ACF Pro with
# a license key (dev/.env locally, the ACF_PRO_LICENSE_KEY secret in CI),
# otherwise Secure Custom Fields.
if wp staging plugin is-active advanced-custom-fields-pro; then
	echo "ACF: ACF Pro $(wp staging plugin get advanced-custom-fields-pro --field=version)"
else
	echo "ACF: Secure Custom Fields $(wp staging plugin get secure-custom-fields --field=version) (set ACF_PRO_LICENSE_KEY to test ACF Pro)"
fi

echo "== Pairing"
contains "staging connects to production with read and write" "read,write" "$(wp staging silta connections list --format=csv)"
contains "production connects to staging with read" "read" "$(wp production silta connections list --format=csv)"
contains "production trusts staging" "yes" "$(wp production silta keys list --format=csv)"

echo "== Unsigned requests are refused"
code=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' -d '{"keys":["post:1"]}' "http://$PROD/wp-json/jcore-silta/v1/remote/export")
check "export without a signature returns 401" "401" "$code"

echo "== Dry run flags the conflict and writes nothing"
before_subtitle=$(wp production post meta get 33 silta_subtitle)
plan=$(wp staging silta push "$PROD" --post=33 --no-dependencies --dry-run)
contains "post 33 is a conflict that will be skipped" "skip" "$(printf '%s\n' "$plan" | grep 'post:33')"
check "production unchanged after the dry run" "$before_subtitle" "$(wp production post meta get 33 silta_subtitle)"

echo "== Unconfirmed conflicts are skipped"
out=$(wp staging silta push "$PROD" --post=33 --no-dependencies --yes)
contains "the run reports the skip" "skipped" "$out"
check "production still unchanged" "$before_subtitle" "$(wp production post meta get 33 silta_subtitle)"

echo "== Push with dependencies and a confirmed conflict"
wp staging silta push "$PROD" --post=33 --deep --confirm-conflicts --yes >/dev/null
check "ACF text field" "$(wp staging post meta get 33 silta_subtitle)" "$(wp production post meta get 33 silta_subtitle)"
check "ACF repeater rows" "$(wp staging post meta get 33 silta_slides)" "$(wp production post meta get 33 silta_slides)"
new_slug=$(wp staging post get 51 --field=post_name)
new_prod=$(post_id production "$new_slug")
contains "staging's new post 51 was created on production" "$(wp staging post get 51 --field=post_title)" "$(wp production post get "$new_prod" --field=post_title)"
[ "$new_prod" != "51" ] && echo "ok   it got its own ID ($new_prod), not production's 51" || { echo "FAIL the new post took ID 51"; failures=$((failures + 1)); }
check "repeater post reference remapped to the new ID" "$new_prod" "$(wp production post meta get 33 silta_slides_3_post)"
en_prod=$(post_id production "$(wp staging post get 52 --field=post_name)")
check "translation linked on production" "$new_prod" "$(wp production eval "echo pll_get_post( $en_prod, 'fi' );")"
contains "featured image of the new post" "$(wp staging eval 'echo basename( get_attached_file( get_post_thumbnail_id( 51 ) ) );')" "$(wp production eval "echo basename( get_attached_file( get_post_thumbnail_id( $new_prod ) ) );")"
check "production title of post 33 now matches staging" "$(wp staging post get 33 --field=post_title)" "$(wp production post get 33 --field=post_title)"

echo "== Undo"
job=$(last_job)
wp staging silta undo "$job" --yes >/dev/null
check "post 33 restored" "$before_subtitle" "$(wp production post meta get 33 silta_subtitle)"
check "created post removed" "trash" "$(wp production post get "$new_prod" --field=post_status)"

echo "== Blocks survive the trip unchanged"
contains "post 35 has no differences after URL replacement" "0 section(s) differ." "$(wp staging silta diff "$PROD" --post=35)"
contains "pushing it changes nothing" "unchanged" "$(wp staging silta push "$PROD" --post=35 --no-dependencies --yes)"

echo "== New media"
php -r '$i = imagecreatetruecolor( 1200, 800 ); imagefill( $i, 0, 0, imagecolorallocate( $i, 200, 80, 40 ) ); imagejpeg( $i, "/tmp/silta-it.jpg", 85 );'
docker compose exec -T cli-staging sh -c 'cat > /tmp/silta-it.jpg' </tmp/silta-it.jpg
att=$(wp staging media import /tmp/silta-it.jpg --title="Integraatiotesti" --alt="Testikuva" --porcelain | tail -1)
file=$(wp staging post meta get "$att" _wp_attached_file)
content="<!-- wp:image {\"id\":$att} --><figure class=\"wp-block-image\"><img src=\"http://$STAGE/wp-content/uploads/$file\" class=\"wp-image-$att\"/></figure><!-- /wp:image -->"
pid=$(wp staging post create --post_type=post --post_status=publish --post_title="Media test" --post_name=silta-media-test --post_content="$content" --porcelain)
wp staging post meta update "$pid" _thumbnail_id "$att" >/dev/null
wp staging silta push "$PROD" --post="$pid" --yes >/dev/null
ppid=$(post_id production silta-media-test)
patt=$(wp production post meta get "$ppid" _thumbnail_id)
check "attachment file arrived" "yes" "$(wp production eval "echo file_exists( get_attached_file( $patt ) ) ? 'yes' : 'no';")"
check "alt text" "Testikuva" "$(wp production post meta get "$patt" _wp_attachment_image_alt)"
check "sizes generated" "yes" "$(wp production eval "echo ! empty( wp_get_attachment_metadata( $patt )['sizes']['thumbnail'] ) ? 'yes' : 'no';")"
pcontent=$(wp production post get "$ppid" --field=post_content)
contains "block id remapped" "{\"id\":$patt}" "$pcontent"
contains "image class remapped" "wp-image-$patt" "$pcontent"
contains "uploads URL replaced" "http://$PROD/wp-content/uploads/" "$pcontent"

echo "== Pull"
wp staging silta pull "$PROD" --post=51 --yes >/dev/null
pulled=$(post_id staging "$(wp production post get 51 --field=post_name)")
check "production's post 51 pulled into staging" "$(wp production post get 51 --field=post_title)" "$(wp staging post get "$pulled" --field=post_title)"

echo "== Menus"
wp staging menu item add-custom 61 "Silta docs" "http://$STAGE/docs/" >/dev/null
wp staging silta push "$PROD" --menu=61 --yes >/dev/null
titles='foreach ( wp_get_nav_menu_items( 61 ) as $item ) { echo $item->title, "|"; }'
check "menu items and order match" "$(wp staging eval "$titles")" "$(wp production eval "$titles")"
contains "custom link URL replaced" "http://$PROD/docs/" "$(wp production eval 'foreach ( wp_get_nav_menu_items( 61 ) as $item ) { echo $item->url, " "; }')"

echo "== Unsafe input is refused"
docs=$(wp staging eval 'foreach ( wp_get_nav_menu_items( 61 ) as $i ) { if ( "Silta docs" === $i->title ) { echo $i->ID; } }')
wp staging eval "update_post_meta( $docs, '_menu_item_classes', array( 'is-docs', 'bad\"class' ) ); wp_update_post( array( 'ID' => $docs, 'post_title' => 'Silta guide' ) );" >/dev/null
wp staging silta push "$PROD" --menu=61 --yes >/dev/null
contains "menu item classes sanitized" "is-docs,badclass" "$(wp production eval 'foreach ( wp_get_nav_menu_items( 61 ) as $i ) { echo implode( ",", $i->classes ), " "; }')"
wp staging eval 'global $wpdb; $wpdb->insert( $wpdb->postmeta, array( "post_id" => 35, "meta_key" => "silta_object", "meta_value" => "O:8:\"stdClass\":0:{}" ) ); wp_cache_delete( 35, "post_meta" ); update_post_meta( 35, "silta_plain", "kept" );' >/dev/null
wp staging silta push "$PROD" --post=35 --no-dependencies --yes >/dev/null
p35=$(post_id production "$(wp staging post get 35 --field=post_name)" "$(wp staging post get 35 --field=post_type)")
check "plain meta still syncs" "kept" "$(wp production post meta get "$p35" silta_plain)"
check "meta holding a PHP object is refused" "0" "$(wp production eval "echo count( get_post_meta( $p35, 'silta_object' ) );")"
evil=$(wp staging eval '$u = wp_get_upload_dir(); file_put_contents( $u["basedir"] . "/silta-evil.php", "<?php echo 1;" ); $id = wp_insert_post( array( "post_type" => "attachment", "post_title" => "silta-evil", "post_status" => "inherit", "post_mime_type" => "image/png" ) ); update_post_meta( $id, "_wp_attached_file", "silta-evil.php" ); echo $id;')
wp staging silta push "$PROD" --post="$evil" --yes >/dev/null
check "a .php file is not accepted as media" "no" "$(wp production eval '$u = wp_get_upload_dir(); echo glob( $u["basedir"] . "/silta-evil*" ) ? "yes" : "no";')"
escape=$(wp staging eval '$id = wp_insert_post( array( "post_type" => "attachment", "post_title" => "silta-escape", "post_status" => "inherit", "post_mime_type" => "image/png" ) ); update_post_meta( $id, "_wp_attached_file", "../../wp-config.php" ); echo $id;')
wp production silta pull "$STAGE" --post="$escape" --yes >/dev/null
check "a pull can't read files outside uploads" "" "$(wp production post list --post_type=attachment --title=silta-escape --field=ID)"

echo "== Whole post type and orphans"
wp staging silta push "$PROD" --post-type=event --yes >/dev/null
wp staging post delete 28 --force >/dev/null
wp staging silta push "$PROD" --post-type=event --yes >/dev/null
check "orphans are not trashed unless asked" "publish" "$(wp production post get 28 --field=post_status)"
wp staging silta push "$PROD" --post-type=event --trash-orphans --yes >/dev/null
check "orphan trashed with --trash-orphans" "trash" "$(wp production post get 28 --field=post_status)"

echo "== Global content (jcore-maailma)"
if [ "$(wp staging eval 'echo post_type_exists( "jcore-global-content" ) ? "yes" : "no";')" = "yes" ] && [ "$(wp production eval 'echo post_type_exists( "jcore-global-content" ) ? "yes" : "no";')" = "yes" ]; then
	gc_json=$(wp staging option get silta_dev_maailma --format=json)
	# gc <name>: staging ID of a seeded global content item (seed/maailma.php)
	gc() { printf '%s' "$gc_json" | php -r 'echo json_decode( stream_get_contents( STDIN ), true )[ $argv[1] ] ?? "";' "$1"; }

	# Maailma renames a slug after the title on every update; the second
	# "Alatunniste" must keep alatunniste-2 through Silta's insert and update.
	wp staging silta push "$PROD" --post="$(gc footer_b)" --no-dependencies --yes >/dev/null
	check "global content keeps its slug on the target" "1" "$(wp production post list --post_type=jcore-global-content --name=alatunniste-2 --format=count --lang=)"
	check "and isn't renamed after its title" "0" "$(wp production post list --post_type=jcore-global-content --name=alatunniste --format=count --lang=)"

	wp staging silta push "$PROD" --post="$(gc page_fi)" --deep --yes >/dev/null
	page=$(post_id production "$(wp staging post get "$(gc page_fi)" --field=post_name)" page)
	contact=$(post_id production ota-yhteytta jcore-global-content)
	hours=$(post_id production aukioloajat jcore-global-content)
	contact_en=$(post_id production contact-us jcore-global-content)
	[ -n "$contact" ] && [ -n "$hours" ] && echo "ok   embedded and nested global content created on production" || { echo "FAIL global content missing on production (contact: $contact, hours: $hours)"; failures=$((failures + 1)); }
	contains "block ID remapped, slug kept" "{\"selectedPostId\":$contact,\"selectedSlug\":\"ota-yhteytta\"}" "$(wp production post get "$page" --field=post_content)"
	contains "nested block by slug kept" "{\"selectedSlug\":\"aukioloajat\"}" "$(wp production post get "$contact" --field=post_content)"
	check "translation of the global content linked" "$contact_en" "$(wp production eval "echo pll_get_post( $contact, 'en' );")"
	contains "embedded global content renders on production" "Avoinna arkisin" "$(wp production eval "echo Jcore\\Maailma\\get_global_content( 'ota-yhteytta' );")"

	# A target slug that differs (taken, or renamed there) is written into the
	# block. Only checked when the post got another ID on production: a post
	# that keeps its ID keeps its slug too.
	if [ "$hours" = "$(gc hours_fi)" ]; then
		echo "skip slug rewrite: the post kept its ID on production"
	else
		wp production eval "global \$wpdb; \$wpdb->update( \$wpdb->posts, array( 'post_name' => 'aukioloajat-tuotanto' ), array( 'ID' => $hours ) ); clean_post_cache( $hours );" >/dev/null
		wp staging silta push "$PROD" --post="$(gc contact_fi)" --no-dependencies --yes >/dev/null
		contains "slug rewritten to the target's" "{\"selectedSlug\":\"aukioloajat-tuotanto\"}" "$(wp production post get "$contact" --field=post_content)"
	fi
else
	echo "skip jcore-maailma is not active on both sites"
fi

echo "== Gravity Forms"
# Only with Gravity Forms on both sites, which needs a license key (dev/.env
# locally, the GRAVITY_FORMS_LICENSE_KEY secret in CI). Without it, skip.
if [ "$(wp staging eval 'echo class_exists( "GFAPI" ) ? "yes" : "no";')" = "yes" ] && [ "$(wp production eval 'echo class_exists( "GFAPI" ) ? "yes" : "no";')" = "yes" ]; then
	# form_id <site> <title>
	form_id() {
		wp "$1" eval "foreach ( GFAPI::get_forms( null ) as \$f ) { if ( '$2' === \$f['title'] ) { echo \$f['id']; break; } }"
	}
	gf_seed='$s = get_option( "silta_dev_gf_seed" );'
	s_contact=$(wp staging eval "$gf_seed echo \$s['forms']['contact'];")
	s_newsletter=$(wp staging eval "$gf_seed echo \$s['forms']['newsletter'];")
	s_page=$(wp staging eval "$gf_seed echo \$s['posts']['form_page'];")
	s_post=$(wp staging eval "$gf_seed echo \$s['posts']['form_post'];")
	s_contact_page=$(wp staging eval '$s = get_option( "silta_dev_seed" ); echo $s["posts"]["contact_fi"];')

	contains "forms are offered in the handshake" "Yhteydenotto" "$(wp staging eval 'echo wp_json_encode( ( new Jcore\Silta\Endpoint\Local( "x" ) )->handshake()["forms"] );')"

	# The page embeds the contact form with the block; the form comes along as a dependency.
	wp staging silta push "$PROD" --post="$s_page" --yes >/dev/null
	p_contact=$(form_id production Yhteydenotto)
	[ -n "$p_contact" ] && echo "ok   the contact form was created on production (#$p_contact)" || { echo "FAIL the contact form is not on production"; failures=$((failures + 1)); }
	[ "$p_contact" != "$s_contact" ] && echo "ok   it got its own ID, not staging's $s_contact" || { echo "FAIL the form kept staging's ID $s_contact, which production's own form has"; failures=$((failures + 1)); }
	check "production's own form is untouched" "Tuotannon palaute" "$(wp production eval "echo GFAPI::get_form( $s_contact )['title'];")"
	p_page=$(post_id production ota-yhteytta page)
	contains "block formId remapped" "\"formId\":\"$p_contact\"" "$(wp production post get "$p_page" --field=post_content)"
	check "fields arrived" "Nimi|Sähköposti|Viesti|Tietosuoja|" "$(wp production eval "foreach ( GFAPI::get_form( $p_contact )['fields'] as \$f ) { echo \$f->label, '|'; }")"
	contains "URL in an HTML field replaced" "http://$PROD/tietosuoja/" "$(wp production eval "echo GFAPI::get_form( $p_contact )['fields'][3]->content;")"
	contains "URL in a notification replaced" "http://$PROD" "$(wp production eval "echo wp_json_encode( GFAPI::get_form( $p_contact )['notifications'], JSON_UNESCAPED_SLASHES );")"
	check "confirmation page remapped" "$(post_id production "$(wp staging post get "$s_contact_page" --field=post_name)" page)" "$(wp production eval "echo current( GFAPI::get_form( $p_contact )['confirmations'] )['pageId'];")"

	# The post embeds the newsletter form with a shortcode.
	wp staging silta push "$PROD" --post="$s_post" --yes >/dev/null
	p_newsletter=$(form_id production Uutiskirje)
	p_post=$(post_id production tilaa-uutiskirje)
	[ "$p_newsletter" != "$s_newsletter" ] && echo "ok   the newsletter form got its own ID (#$p_newsletter)" || { echo "FAIL the newsletter form kept staging's ID"; failures=$((failures + 1)); }
	contains "shortcode id remapped" "[gravityform id=\"$p_newsletter\"" "$(wp production post get "$p_post" --field=post_content)"

	# A form on its own: unchanged, then updated, then undone.
	contains "pushing the form again changes nothing" "unchanged" "$(wp staging silta push "$PROD" --form="$s_contact" --dry-run)"
	wp staging eval "\$f = GFAPI::get_form( $s_contact ); \$f['button']['text'] = 'Lähetä viesti'; GFAPI::update_form( \$f );" >/dev/null
	wp staging silta push "$PROD" --form="$s_contact" --yes >/dev/null
	check "form settings updated" "Lähetä viesti" "$(wp production eval "echo GFAPI::get_form( $p_contact )['button']['text'];")"
	wp staging silta undo "$(last_job)" --yes >/dev/null
	check "undo restores the form" "Lähetä" "$(wp production eval "echo GFAPI::get_form( $p_contact )['button']['text'];")"
	check "entries are never synced" "0" "$(wp production eval "echo GFAPI::count_entries( $p_contact );")"
else
	echo "skip Gravity Forms is not active on both sites (set GRAVITY_FORMS_LICENSE_KEY, see dev/.env.example)"
fi

echo "== WooCommerce"
if [ "$(wp staging eval 'echo class_exists( "WooCommerce" ) ? "yes" : "no";')" = "yes" ] && [ "$(wp production eval 'echo class_exists( "WooCommerce" ) ? "yes" : "no";')" = "yes" ]; then
	woo_json=$(wp staging option get silta_dev_woo_seed --format=json)
	# woo <name>: staging ID of a seeded product (seed/woocommerce.php)
	woo() { printf '%s' "$woo_json" | php -r 'echo json_decode( stream_get_contents( STDIN ), true )["products"][ $argv[1] ] ?? "";' "$1"; }
	# woo_ext <plugin slug> <name>: staging ID seeded by seed/woocommerce/<plugin slug>.php
	woo_ext() { printf '%s' "$woo_json" | php -r 'echo json_decode( stream_get_contents( STDIN ), true )["extensions"][ $argv[1] ][ $argv[2] ] ?? "";' "$1" "$2"; }

	# woo_seed <group> <name>: staging ID of other seeded content (variations, coupons, terms, attributes)
	woo_seed() { printf '%s' "$woo_json" | php -r 'echo json_decode( stream_get_contents( STDIN ), true )[ $argv[1] ][ $argv[2] ] ?? "";' "$1" "$2"; }
	# files <site> <comma-separated attachment IDs>: their file names, in order
	files() { wp "$1" eval "foreach ( array_filter( explode( ',', '$2' ) ) as \$i ) { echo basename( get_attached_file( (int) \$i ) ), '|'; }"; }
	# lookup <site> <product ID> <column>: the product's row in wc_product_meta_lookup
	lookup() { wp "$1" eval "global \$wpdb; echo \$wpdb->get_var( \$wpdb->prepare( 'SELECT $3 FROM ' . \$wpdb->wc_product_meta_lookup . ' WHERE product_id = %d', $2 ) );"; }

	# A simple product with its images, terms and the category's image.
	wp staging silta push "$PROD" --post="$(woo mug)" --deep --yes >/dev/null
	mug=$(post_id production silta-muki product)
	[ -n "$mug" ] && echo "ok   the simple product was created on production (#$mug)" || { echo "FAIL the simple product is not on production"; failures=$((failures + 1)); }
	check "SKU" "SILTA-MUKI" "$(wp production post meta get "$mug" _sku)"
	check "price" "14.90" "$(wp production eval "echo wc_get_product( $mug )->get_price();")"
	check "product type" "simple" "$(wp production eval "echo wc_get_product( $mug )->get_type();")"
	check "product category created" "Tarvikkeet" "$(wp production eval "echo implode( ',', wp_get_post_terms( $mug, 'product_cat', array( 'fields' => 'names' ) ) );")"
	check "gallery (a comma-separated ID list) remapped" "$(files staging "$(wp staging post meta get "$(woo mug)" _product_image_gallery)")" "$(files production "$(wp production post meta get "$mug" _product_image_gallery)")"
	accessories=$(wp production eval "echo get_term_by( 'slug', 'tarvikkeet', 'product_cat' )->term_id;")
	check "category image (term meta thumbnail_id) remapped" "$(files staging "$(wp staging term meta get "$(woo_seed terms accessories)" thumbnail_id)")" "$(files production "$(wp production term meta get "$accessories" thumbnail_id)")"
	check "a new product gets the source's stock" "25" "$(wp production eval "echo wc_get_product( $mug )->get_stock_quantity();")"
	check "price lookup table filled" "14.9000" "$(lookup production "$mug" max_price)"
	# pa_koko exists only on staging: its empty term set is no difference.
	contains "pushing it again changes nothing" "unchanged" "$(wp staging silta push "$PROD" --post="$(woo mug)" --no-dependencies --dry-run | grep "post:$(woo mug)")"

	# Stock, sales and ratings are the live store's own.
	wp production eval "\$p = wc_get_product( $mug ); \$p->set_stock_quantity( 7 ); \$p->set_total_sales( 3 ); \$p->save();" >/dev/null
	wp staging eval "\$p = wc_get_product( $(woo mug) ); \$p->set_regular_price( '21.90' ); \$p->set_sale_price( '' ); \$p->set_stock_quantity( 30 ); \$p->save();" >/dev/null
	contains "a sale on production is not a conflict" "update" "$(wp staging silta push "$PROD" --post="$(woo mug)" --no-dependencies --dry-run | grep "post:$(woo mug)")"
	wp staging silta push "$PROD" --post="$(woo mug)" --no-dependencies --yes >/dev/null
	check "regular price updated" "21.90" "$(wp production eval "echo wc_get_product( $mug )->get_regular_price();")"
	check "active price recalculated on production" "21.90" "$(wp production eval "echo wc_get_product( $mug )->get_price();")"
	check "price lookup refreshed" "21.9000" "$(lookup production "$mug" max_price)"
	check "production keeps its stock" "7" "$(wp production eval "echo wc_get_product( $mug )->get_stock_quantity();")"
	check "production keeps its sales count" "3" "$(wp production eval "echo wc_get_product( $mug )->get_total_sales();")"

	# Upsells and cross-sells (serialized ID lists).
	wp staging silta push "$PROD" --post="$(woo poster)" --yes >/dev/null
	poster=$(post_id production silta-juliste product)
	check "upsell remapped" "$mug" "$(wp production eval "echo implode( ',', wc_get_product( $poster )->get_upsell_ids() );")"
	check "cross-sell remapped" "$mug" "$(wp production eval "echo implode( ',', wc_get_product( $poster )->get_cross_sell_ids() );")"

	# A grouped product's children (a serialized ID list) and its derived price.
	wp staging silta push "$PROD" --post="$(woo gift_set)" --yes >/dev/null
	gift_set=$(post_id production silta-lahjasetti product)
	check "grouped children remapped" "$mug,$poster" "$(wp production eval "echo implode( ',', wc_get_product( $gift_set )->get_children() );")"
	check "grouped price synced from its children" "21.90" "$(wp production eval "echo wc_get_product( $gift_set )->get_price();")"

	# A variable product brings its variations; matching keeps them on a re-push.
	wp staging silta push "$PROD" --post="$(woo shirt)" --yes >/dev/null
	shirt=$(post_id production silta-paita product)
	variations="foreach ( wc_get_product( $shirt )->get_children() as \$v ) { \$v = wc_get_product( \$v ); echo \$v->get_sku(), '=', \$v->get_attribute( 'pa_vari' ), '=', \$v->get_regular_price(), '=', \$v->get_stock_quantity(), '|'; }"
	check "variations created with their attributes, prices and stock" "SILTA-PAITA-PUN=Punainen=24.90=10|SILTA-PAITA-SIN=Sininen=26.90=4|" "$(wp production eval "$variations")"
	check "variable price synced from the variations" "24.90-26.90" "$(wp production eval "\$p = wc_get_product( $shirt ); echo \$p->get_variation_price( 'min' ), '-', \$p->get_variation_price( 'max' );")"
	check "variable price lookup" "24.9000-26.9000" "$(lookup production "$shirt" min_price)-$(lookup production "$shirt" max_price)"
	blue=$(wp production eval "echo wc_get_product_id_by_sku( 'SILTA-PAITA-SIN' );")
	check "variation image arrived" "$(files staging "$(wp staging post meta get "$(woo_seed variations blue)" _thumbnail_id)")" "$(files production "$(wp production post meta get "$blue" _thumbnail_id)")"
	check "custom attribute kept" "Luomupuuvilla" "$(wp production eval "echo wc_get_product( $shirt )->get_attribute( 'Materiaali' );")"
	wp staging silta push "$PROD" --post="$(woo shirt)" --yes >/dev/null
	check "a re-push matches the variations instead of adding more" "2" "$(wp production eval "echo count( wc_get_product( $shirt )->get_children() );")"

	# A variation removed on staging is trashed with its product, and undo brings it back.
	wp staging post delete "$(woo_seed variations blue)" --force >/dev/null
	wp staging eval "WC_Product_Variable::sync( $(woo shirt) );" >/dev/null
	contains "the removed variation is offered for the trash" "orphan:$blue" "$(wp staging silta push "$PROD" --post="$(woo shirt)" --dry-run)"
	wp staging silta push "$PROD" --post="$(woo shirt)" --yes >/dev/null
	check "the removed variation is trashed" "trash" "$(wp production post get "$blue" --field=post_status)"
	check "the product's price follows" "24.90" "$(wp production eval "echo wc_get_product( $shirt )->get_variation_price( 'max' );")"
	wp staging silta undo "$(last_job)" --yes >/dev/null
	check "undo restores the variation" "publish" "$(wp production post get "$blue" --field=post_status)"
	check "and the product's price" "26.90" "$(wp production eval "echo wc_get_product( $shirt )->get_variation_price( 'max' );")"

	# A global attribute production doesn't have is created there.
	check "production has no Koko attribute yet" "0" "$(wp production eval "echo wc_attribute_taxonomy_id_by_name( 'pa_koko' );")"
	contains "the plan says the attribute will be created" "pa_koko" "$(wp staging silta push "$PROD" --post="$(woo beanie)" --dry-run)"
	wp staging silta push "$PROD" --post="$(woo beanie)" --yes >/dev/null
	beanie=$(post_id production silta-pipo product)
	check "attribute created" "Koko" "$(wp production eval "echo wc_get_attribute( wc_attribute_taxonomy_id_by_name( 'pa_koko' ) )->name;")"
	check "with the product's terms" "$(wp staging eval "echo wc_get_product( $(woo beanie) )->get_attribute( 'pa_koko' );")" "$(wp production eval "echo wc_get_product( $beanie )->get_attribute( 'pa_koko' );")"
	# The new taxonomy gives earlier products an empty term set on production,
	# which must not read as a change made there.
	mug_plan=$(wp staging silta push "$PROD" --post="$(woo mug)" --no-dependencies --dry-run | grep "post:$(woo mug)")
	contains "a product synced before the attribute existed is still unchanged" "unchanged" "$mug_plan"
	check "and is not a conflict" "" "$(echo "$mug_plan" | grep -o modified)"

	# A coupon's product list (comma-separated) is remapped; usage isn't synced.
	coupon=$(woo_seed coupons silta10)
	wp staging post meta update "$coupon" usage_count 5 >/dev/null
	wp staging silta push "$PROD" --post="$coupon" --yes >/dev/null
	pcoupon=$(wp production eval "echo wc_get_coupon_id_by_code( 'silta10' );")
	check "coupon product IDs remapped" "$mug,$shirt" "$(wp production post meta get "$pcoupon" product_ids)"
	check "coupon usage is not synced" "0" "$(wp production eval "echo ( new WC_Coupon( $pcoupon ) )->get_usage_count();")"

	# Paid extensions: one block each, skipped unless the plugin is active on
	# both sites (its zip is in dev/woocommerce/extensions/).
	echo "== WooCommerce Product Bundles"
	if active_on_both woocommerce-product-bundles; then
		bundle=$(woo_ext woocommerce-product-bundles bundle)
		check "the bundle is seeded on staging" "bundle" "$(wp staging eval "echo wc_get_product( $bundle )->get_type();")"
		check "with its items and their settings" "1-1-no|1-2-yes|" "$(wp staging eval "foreach ( wc_get_product( $bundle )->get_bundled_items() as \$i ) { echo \$i->get_quantity( 'min' ), '-', \$i->get_quantity( 'max' ), '-', \$i->is_optional() ? 'yes' : 'no', '|'; }")"

		# A target without the extension refuses a bundle instead of creating an empty product.
		wp production plugin deactivate woocommerce-product-bundles >/dev/null
		contains "a target without Product Bundles refuses the bundle" "WooCommerce Product Bundles is not active here" "$(wp staging silta push "$PROD" --post="$bundle" --dry-run)"
		wp production plugin activate woocommerce-product-bundles >/dev/null
		check "and nothing was created" "" "$(post_id production silta-paketti product)"

		# The bundle's items point at production's products and variations.
		wp staging silta push "$PROD" --post="$bundle" --yes >/dev/null
		pbundle=$(post_id production silta-paketti product)
		red=$(wp production eval "echo wc_get_product_id_by_sku( 'SILTA-PAITA-PUN' );")
		# bundle_items <site> <bundle ID>: product=allowed variations, per item
		bundle_items() { wp "$1" eval "foreach ( wc_get_product( $2 )->get_bundled_data_items( 'edit' ) as \$i ) { echo \$i->get_product_id(), '=', implode( ',', (array) \$i->get_meta( 'allowed_variations' ) ), '|'; }"; }
		bundle_settings() { wp "$1" eval "foreach ( wc_get_product( $2 )->get_bundled_items() as \$i ) { echo \$i->get_quantity( 'min' ), '-', \$i->get_quantity( 'max' ), '-', \$i->is_optional() ? 'yes' : 'no', '|'; }"; }
		bundle_ids() { wp production eval "foreach ( wc_get_product( $1 )->get_bundled_data_items( 'edit' ) as \$i ) { echo \$i->get_id(), '|'; }"; }
		bundle_price() { wp "$1" eval "echo wc_get_product( $2 )->get_price(), '-', get_post_meta( $2, '_wc_sw_max_price', true );"; }
		check "the bundle was created as a bundle" "bundle" "$(wp production eval "echo wc_get_product( $pbundle )->get_type();")"
		check "its items point at production's mug and shirt, and the shirt's allowed variation" "$mug=|$shirt=$red|" "$(bundle_items production "$pbundle")"
		check "the items' settings" "1-1-no|1-2-yes|" "$(bundle_settings production "$pbundle")"
		check "priced individually, discount and default variation" "no-yes-10-punainen" "$(wp production eval "\$d = wc_get_product( $pbundle )->get_bundled_data_items( 'edit' ); echo \$d[0]->get_meta( 'priced_individually' ), '-', \$d[1]->get_meta( 'priced_individually' ), '-', \$d[1]->get_meta( 'discount' ), '-', \$d[1]->get_meta( 'default_variation_attributes' )['pa_vari'];")"
		check "the price from its items, as on staging" "39-83.82" "$(bundle_price production "$pbundle")"
		check "staging has the same price" "$(bundle_price staging "$bundle")" "$(bundle_price production "$pbundle")"
		check "price lookup table" "39.0000-83.8200" "$(lookup production "$pbundle" min_price)-$(lookup production "$pbundle" max_price)"

		# A second push matches the items instead of adding more.
		ids=$(bundle_ids "$pbundle")
		contains "pushing the bundle again changes nothing" "unchanged" "$(wp staging silta push "$PROD" --post="$bundle" --dry-run | grep "post:$bundle")"
		wp staging eval "\$b = wc_get_product( $bundle ); \$d = array(); foreach ( \$b->get_bundled_data_items( 'edit' ) as \$i ) { \$x = \$i->get_data(); if ( $(woo shirt) === \$x['product_id'] ) { \$x['meta_data']['quantity_max'] = 3; } \$d[] = \$x; } \$b->set_bundled_data_items( \$d ); \$b->save();" >/dev/null
		contains "a changed item setting is an update of the items" "bundled_items" "$(wp staging silta push "$PROD" --post="$bundle" --dry-run | grep "post:$bundle")"
		wp staging silta push "$PROD" --post="$bundle" --yes >/dev/null
		check "a re-push updates the items in place" "$ids" "$(bundle_ids "$pbundle")"
		check "with the changed setting" "1-1-no|1-3-yes|" "$(bundle_settings production "$pbundle")"
		check "and the price follows" "39-106.23" "$(bundle_price production "$pbundle")"

		# An item removed on staging is removed on production too, and undo puts it back.
		wp staging eval "\$b = wc_get_product( $bundle ); \$d = array(); foreach ( \$b->get_bundled_data_items( 'edit' ) as \$i ) { if ( $(woo mug) !== \$i->get_product_id() ) { \$d[] = \$i->get_data(); } } \$b->set_bundled_data_items( \$d ); \$b->save();" >/dev/null
		contains "the plan says the item will be removed" "Removes bundled items the source no longer has: Silta-muki" "$(wp staging silta push "$PROD" --post="$bundle" --dry-run)"
		wp staging silta push "$PROD" --post="$bundle" --yes >/dev/null
		check "the removed item is gone on production" "$shirt=$red|" "$(bundle_items production "$pbundle")"
		check "the remaining item kept its ID" "$(printf '%s' "$ids" | cut -d'|' -f2)|" "$(bundle_ids "$pbundle")"
		wp staging silta undo "$(last_job)" --yes >/dev/null
		check "undo puts the removed item back" "$mug=|$shirt=$red|" "$(bundle_items production "$pbundle")"
		check "with its settings" "1-1-no|1-3-yes|" "$(bundle_settings production "$pbundle")"
	else
		echo "skip WooCommerce Product Bundles is not active on both sites (put its zip in dev/woocommerce/extensions/)"
	fi
else
	echo "skip WooCommerce is not active on both sites"
fi

echo "== Clone detection"
wp production eval 'update_option( "jcore_silta_site", array( "uuid" => wp_generate_uuid4(), "home" => sha1( "copied-from-elsewhere" ), "seen" => "" ) );' >/dev/null
wp production option get blogname >/dev/null
contains "trusted keys switched off" "no (inherited)" "$(wp production silta keys list --format=csv)"
contains "connections marked inherited" "inherited" "$(wp production silta connections list --format=csv)"
out=$(docker compose exec -T cli-staging wp --user=admin silta push "$PROD" --post=35 --yes 2>&1)
contains "staging can no longer push" "Error" "$out"

echo
if [ "$failures" -gt 0 ]; then
	echo "$failures check(s) failed."
	exit 1
fi
echo "All checks passed."
