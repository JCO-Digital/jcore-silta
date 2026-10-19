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

PROD=localhost:8091
STAGE=localhost:8090
failures=0

# wp <staging|production> <args...>
wp() {
	site=$1
	shift
	docker compose --progress quiet run --rm -T "cli-$site" wp --user=admin "$@" 2>/dev/null
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

last_job() {
	wp staging silta jobs --format=json | php -r 'echo json_decode( stream_get_contents( STDIN ), true )[0]["id"] ?? "";'
}

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
docker compose cp /tmp/silta-it.jpg staging:/tmp/silta-it.jpg >/dev/null 2>&1
docker compose exec -T staging chmod 644 /tmp/silta-it.jpg
att=$(docker compose --progress quiet run --rm -T -v /tmp/silta-it.jpg:/tmp/silta-it.jpg:ro cli-staging wp --user=admin media import /tmp/silta-it.jpg --title="Integraatiotesti" --alt="Testikuva" --porcelain 2>/dev/null | tail -1)
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

echo "== Clone detection"
wp production eval 'update_option( "jcore_silta_site", array( "uuid" => wp_generate_uuid4(), "home" => sha1( "copied-from-elsewhere" ), "seen" => "" ) );' >/dev/null
wp production option get blogname >/dev/null
contains "trusted keys switched off" "no (inherited)" "$(wp production silta keys list --format=csv)"
contains "connections marked inherited" "inherited" "$(wp production silta connections list --format=csv)"
out=$(docker compose --progress quiet run --rm -T cli-staging wp --user=admin silta push "$PROD" --post=35 --yes 2>&1)
contains "staging can no longer push" "Error" "$out"

echo
if [ "$failures" -gt 0 ]; then
	echo "$failures check(s) failed."
	exit 1
fi
echo "All checks passed."
