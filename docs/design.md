# JCORE Silta: design

Silta ("bridge") syncs selected content between two WordPress sites: single posts, whole post types, or any mix of them, together with their custom fields, terms, media and the posts they reference. A diff mode shows what would change, section by section, and lets you sync only the parts you pick.

The main use case is **staging → production**. The two sites are usually database clones of each other: posts that existed before the clone have the same IDs and GUIDs on both sites, while posts created afterwards can collide on ID. Most of the defaults below follow from that.

This document records the decisions from the initial design session (2026-10-13). Each is marked **Decided** if it was agreed, or **Default** if it was not discussed and can still change.

## Terms

| Term | Meaning |
| --- | --- |
| Source / target | The site content comes from / goes to. |
| Initiator / remote | The site whose admin you are working in / the other site. A push makes the initiator the source; a pull makes it the target. |
| Connection | The initiator's saved link to a remote: URL, key, scopes and saved search-replace rules. |
| Bundle | A source's export of a set of posts: core fields, content, meta, terms, media manifest and discovered references. JSON, versioned. |
| Plan | The target's answer to a bundle: preflight errors, matches, conflicts, dependencies, and a before/after for every section. |
| Job | One sync run. It is stored, runs in batches and can be resumed and undone. |
| Mapping | The target's record that remote object X on site S is local object Y. |

## Decisions

### Topology and authentication

- **Decided: push and pull.** The plugin is installed on both sites. From either site's admin you can push to, or pull from, a connected site.
- **Decided: only the initiator makes requests.** All requests go from the initiator to the remote, never back. A local site, or staging behind basic auth, can therefore still push to production and pull from it. Media bytes travel over the same channel.
- **Decided: Ed25519 request signatures, so a remote stores only public keys.** This replaced the shared HMAC secret from the first draft. With a shared secret, production would hold a credential that can write to production, and every production → staging/local database copy would carry it along. See [Authentication](#authentication) below.
- **Default:** connections can store optional HTTP basic auth credentials for remotes behind it, encrypted like the private key.
- **Decided: multisite subsites are ordinary connections over HTTP.** There is no `switch_to_blog` fast path. The plugin must also work when network-activated.

### Authentication

**Pairing.** Each pairing lets one initiator call one remote. Initiating in both directions takes two pairings.

1. On the remote: *Connections → Allow a site to connect*, with a label and scopes (`read` lets the initiator pull, `write` lets it push). The remote creates a one-time pairing token: 32 random bytes, stored only as a SHA-256 hash, valid for 30 minutes and usable once. It is shown as `silta1.<base64url(json)>`, holding the remote's REST URL, its site UUID and the token.
2. On the initiator: *Connections → Add connection* and paste the string. If the initiator has no key pair yet, it creates one now. It calls the remote's `POST pair` with the token, its public key and its site details, signed with the new private key to prove it holds that key.
3. The remote checks the token and burns it. It stores the initiator's public key, label, scopes, site UUID and home URL, along with the admin who created the token. It answers with its own site details and the granted scopes.

The site UUID in step 2 is the initiator's own claim. So if the remote already trusts a different, enabled key for that UUID, pairing is refused until an admin revokes the old key: a pairing token can't take over another site's identity and mappings. The REST URL in the remote's answer must keep the host from the pairing string. On the initiator, a pairing string for a site it is already connected to must point at the same host and port, or it is refused until an admin removes the old connection: whoever writes a pairing string also answers the pair request, so otherwise one could move a connection, its mappings and its saved basic auth to another server. Re-pairing with the same host reuses the saved basic auth.

Once pairing is done, the pasted string is useless, so it doesn't matter if it lingers in Slack or email.

**Requests.** Every remote call is a `POST` with a JSON body. The initiator signs
`silta-v1 \n <remote site UUID> \n <method> \n <route> \n <timestamp> \n <nonce> \n <sha256 of body>`
and sends `X-Silta-Key` (its key fingerprint), `X-Silta-Timestamp`, `X-Silta-Nonce` and `X-Silta-Signature`. The remote looks up the public key by fingerprint and checks the signature with `sodium_crypto_sign_verify_detached`. It then checks the timestamp is within ±5 minutes, the nonce hasn't been seen, the key is enabled, and the route's scope. Nonces are reserved with an insert into the `jcore_silta_nonces` table for 10 minutes, so two concurrent copies of a request can't both pass. Oversized bodies and batches are refused before any of this. Including the remote's UUID stops a signed request being replayed against another site that trusts the same key.

The remote then acts as the admin who created the pairing token. Imports run with that user's capabilities, and if that user loses `manage_options` or is deleted, the connection stops working. Remote routes are excluded from Application Password handling, because otherwise a basic auth header for a protected staging site would be read as a failed WordPress login.

HTTPS is required for remote URLs, except when the environment type is `local` or `development` (filterable). A signature proves who sent a request but doesn't hide its content. Requests use WordPress's safe-URL check, so a connection can't point at loopback or private-network addresses (or ports other than 80, 443 and 8080) unless insecure connections are allowed or the `jcore_silta_allow_private_network` filter says so.

Responses are not signed. The initiator treats what a remote sends back as untrusted: links and thumbnails must be http(s), and the HTML diff table a remote renders for a push is reduced to the table's own tags with `wp_kses()`.

**The private key.**

- **One key pair per site, created lazily** with `sodium_crypto_sign_seed_keypair()` the first time the site adds an outgoing connection, so no CLI or server access is needed. A site that only receives syncs (production, in the main use case) never has a private key, so copies of its database hold nothing that can sign a request. Sodium is built into PHP, and WordPress bundles a pure-PHP fallback.
- **Stored in the database, encrypted.** The option `jcore_silta_identity` holds the public key, the 32-byte seed encrypted with `sodium_crypto_secretbox`, and the home URL the key was created for. The encryption key is derived with HKDF from `SECURE_AUTH_KEY` and `SECURE_AUTH_SALT` in `wp-config.php`. A database-only leak (dump, backup, SQL injection, a refresh into an environment with other salts) therefore yields nothing usable. If the salts live in the database instead (rare), the encryption adds nothing, and the Connections screen says so.
- **Optionally in `wp-config.php`** as `define( 'JCORE_SILTA_PRIVATE_KEY', '…' );` (base64 seed). The Connections screen can show this line, and `wp silta key export` prints it. Once the constant is detected, the copy in the database is deleted. The key then survives database refreshes. It's never required.
- **Key ID:** the first 12 bytes of SHA-256 of the public key, in hex.

**Clones, refreshes and rotation.**

- A site's identity, meaning its UUID and key, is tied to its home URL. When the home URL changes (a database clone or refresh), the site gets a new UUID and discards a key it finds in the database, even one it could decrypt because the salts were copied too. A key from `wp-config.php` is kept. Outgoing connections and trusted keys that came with the copied database are marked *inherited* and turned off until an admin reviews them. A notice explains what happened.
- If the salts are rotated, the key can't be decrypted any more. The site says so, creates a new key pair, and each remote gives you a fresh pairing string.
- Revoking a site on the remote deletes its public key, and requests from it fail immediately. Re-pairing a site with a new key (after salts were rotated, say) needs its old key revoked first.

### Identity and matching

- **Default: site identity.** Each site has a UUID in `jcore_silta_site` together with the home URL it was generated for. If the home URL changes (a DB clone), the site gets a new UUID. Then a clone doesn't share an identity with its origin, and the cloned connections and keys are marked as needing re-verification.
- **Decided: matching ladder, with override in the preview.** For posts:
  1. a stored mapping (remote site + remote ID → local ID);
  2. a reverse mapping: if this post originally came from the target, the bundle says so, and it maps straight back (round trips work);
  3. the same GUID (survives DB clones);
  4. the same post type, slug and parent;
  5. otherwise, create a new post.

  The preview shows how each post was matched. You can pick another target post or force "create new".
- Attachments use the same ladder, with "same `_wp_attached_file` path and file hash" instead of slug. Terms: mapping, then slug within the taxonomy (and parent). Menus: mapping, then menu slug. Gravity Forms forms: mapping, origin, the same ID with the same title, then the one form with that title (see [Gravity Forms](#gravity-forms)).

### What is synced

- **Core fields:** title, slug, content, excerpt, status, dates, author, parent, menu order, template, comment/ping status, password, post format, sticky. **Default:** authors are matched by login, then email, then fall back to the user running the sync. `post_modified` is set by the target as usual.
- **Custom fields (decided):** every meta key is copied, except an exclude list (default: `_edit_lock`, `_edit_last`, `_wp_old_slug`, `_wp_old_date`, `_encloseme`, `_pingme`, the oEmbed cache `_oembed_*` (WordPress fetches it again, and prints the cached HTML unfiltered), Silta's own `_jcore_silta_*`, plus anything added with the `jcore_silta_excluded_meta` filter). Meta keys removed on the source are removed on the target. The target applies the same exclude list to incoming meta, and never takes keys it writes itself (`_thumbnail_id`, `_wp_page_template`, `_wp_attached_file`, `_wp_attachment_metadata`, `_wp_attachment_backup_sizes`, `_menu_item_*`) from the meta map. A serialized value holding a PHP object (`O:`, `C:` or an enum) or a reference is refused: the key keeps its local value and the job reports a warning. When the acting user lacks `unfiltered_html`, incoming meta goes through `wp_kses_post` like post content: the value itself, every string inside serialized data (walked token by token, never unserialized) and every string inside JSON. Only strings containing a `<` are filtered, since kses would otherwise only re-encode text such as `a & b`; a value with nothing to filter keeps its exact bytes. Values that hold IDs are remapped by these handlers:
  - **ACF / ACF Pro:** image, gallery, file, post object, relationship, page link, taxonomy, user and link fields, including inside repeaters, flexible content, groups and clones. Also **ACF block data** inside block comments.
  - **SEO plugins:** Yoast, Rank Math and SEOPress image IDs and URLs.
  - **Core block attributes:** `core/image`, `gallery`, `cover`, `media-text`, `file`, `video`, `audio`, `block` (ref), `navigation`/`navigation-link`, `query` (include/exclude/parents/taxQuery), `latest-posts` categories, `page-list`, and `wp-image-123` / `data-id` in markup. Markup is only re-serialised for blocks that actually changed, so re-saving doesn't add noise.
  - **Featured image** (`_thumbnail_id`).
  - **Filters** `jcore_silta_meta_references` and `jcore_silta_block_references`, so themes and other plugins can declare their own ID-bearing meta and block attributes. A block attribute can also hold a post's slug (`'slug:<post type>'`, top-level attributes only): the source looks the slug up to find the dependency, the target finds the post among the bundle's objects and stubs, maps it, and writes the slug that post has on the target when it maps to another post. A slug that names nothing, or whose post doesn't resolve (yet), is left as it is.
  - **WooCommerce:** product galleries, upsells, cross-sells, grouped products' children, coupons' product and category lists, and category and brand images. See [WooCommerce](#woocommerce).
  - **jcore-maailma** (decided, 2026-10-19): global content (`jcore-global-content`) is an ordinary post type for Silta, and its translations go through the Polylang handling. The `jco/global-content` block stores the post's slug (looked up first when rendering) and its ID (the fallback); both are declared through the block filter, so the post comes along as a dependency and both attributes are remapped. Global content embedding other global content or media is walked like any post, so those are the next dependency level. Maailma renames a global content post's slug after its title on every update, which would break slug lookups (two items with the same title, or translations sharing a title, end up with `-2` slugs that the title can't reproduce); while Silta writes a post (`Importer::writing()`), Maailma's `save_post` callback is suspended so the slug from the source stays. Maailma has no shortcodes; templates call `get_global_content()` / `jcore_global_content()` by slug, which keeps working because slugs are kept. Translations of embedded global content are not pulled in with it but offered one level further, like translations of any dependency; until they're synced, the block shows the language version it points at.
- **Taxonomies (decided):** match or create. Missing terms are created with their parent chain and term meta. Existing terms on the target are never modified. A synced post's term set replaces the target's set for each taxonomy you sync.
- **Media (decided):** the original file and its generated sizes are streamed over the signed channel in chunks. Only sizes the target is missing are regenerated. Attachments are deduplicated by mapping, then by file hash, and attachment fields (title, alt, caption, description) are synced too.
  - The target only accepts file types WordPress allows for the acting user. It checks the names when the transfer opens, and the bytes (`wp_check_filetype_and_ext()`) before anything moves into uploads. Executable, HTML and SVG files are refused. The original and generated sizes must be images, and the main file an image or a PDF (WordPress generates image previews for PDFs). The attachment's MIME type comes from the checked file, not the bundle.
  - A transfer is bound to the site and attachment that opened it, and can be finalized once. Its manifest is a row in the `jcore_silta_transfers` table, out of reach of the uploaded files. Every path must resolve inside uploads and outside the staging folder. If a transfer expired (a job resumed the next day) or its staged files went missing, the target says so and the files are sent again, at most twice.
  - A new attachment always gets its own copies of the files (`photo-1.jpg` when `photo.jpg` already exists), so undo can delete them without touching another attachment's files.
  - The source only serves files that resolve inside uploads, in chunks no larger than the configured chunk size.
- **Polylang (decided):** the post's language and translation group. Translations of a selected post are offered as dependencies, and links between translations are restored on the target. WPML is not in v1.
- **v1 extras (decided):** nav menus (menus, items and optionally locations, with linked objects remapped), standalone media in the picker, and reusable blocks/patterns (`wp_block`) as a selectable post type.
- **Gravity Forms (default, added 2026-10-19):** form definitions as a sync kind of their own. See [Gravity Forms](#gravity-forms).
- **WooCommerce (added 2026-11-09):** products of free WooCommerce core (simple, variable, grouped, external) with their variations, and coupons. Orders never. See [WooCommerce](#woocommerce).
- **Not in v1:** ACF options pages, WPML, comments, users, widgets, site options, Gravity Forms entries and add-on feeds.

### References and dependencies

- **Decided: discover and offer.** The source's exporter runs the reference handlers to list what each post depends on: media, referenced posts, terms, translations, parent pages. The target remaps references to anything it already has. Missing dependencies appear in the preview, ticked by default, one level at a time, and you can untick them. An unresolved reference keeps the source ID, and the job report warns about it.
- The handlers are the same code on both sides. They discover on the source and remap on the target. ACF field definitions travel in the bundle so the target remaps with the source's definitions.

### Search-replace

- **Decided: automatic URL swap plus saved rules.** The source's home and uploads URLs are replaced with the target's, in plain, JSON-escaped (`\/`) and URL-encoded forms. On top of that come find → replace pairs saved on the connection, which you can edit for each run. Plain strings only, no regex.
- It applies to title, content, excerpt, meta values, term meta and attachment fields.
- It is serialization-safe: serialized values are decoded (`allowed_classes => false`), walked, replaced and re-encoded, and JSON strings inside meta are walked the same way. Values holding objects or references are refused rather than rewritten, and nesting depth is capped, also across serialized strings inside serialized values.
- Order: ID remapping first, then search-replace. The diff compares that transformed result with the target, so URL differences never show as changes.

### Conflicts, status and deletions

- **Decided: flag conflicts and require confirmation.** The target stores a hash of what it last received. If the target post's current hash differs (it was edited after the last sync or after the clone), the post is flagged as a conflict. It isn't overwritten until you confirm it, or pick sections for it in diff mode.
- **Decided: status is copied, with an override per run.** By default a published staging post publishes on production. Per run you can choose "land as draft" or "keep the target's status for existing posts". Scheduled posts keep their date.
- **Decided: deletions are only offered for orphans.** A sync never deletes by default. When you sync a whole post type, the preview lists target posts mapped to source posts that no longer exist. Any you tick are moved to the trash, never hard-deleted.

### Gravity Forms

Added after the initial design as a sync kind next to menus. Nothing here was discussed in the design session, so all of it is **Default**.

- **Only with Gravity Forms on both sides.** Forms live in Gravity Forms' own tables (`gf_form`, `gf_form_meta`…), not in posts, and are read and written only through `GFAPI` (`get_form`, `get_forms`, `add_form`, `update_form`, `update_form_property`, `form_id_exists`). The handshake says whether a site has Gravity Forms and lists its forms. The picker offers forms only when the source has Gravity Forms, and disables them with a note when the target doesn't. A form record (picked, or pulled in as a dependency) that reaches a target without Gravity Forms is blocked in the plan by preflight with "Gravity Forms is not active on this site", and a source without it reports the same for requested forms. Preflight also blocks a form that uses field types the target doesn't register (an add-on missing there).
- **What is synced:** the title, the active state, the form settings (everything in the form meta except its ID, dates, trash flag and the copies Gravity Forms derives on load: `nextFieldId` and the legacy `notification` / `autoResponder`), the fields (without the `formId` Gravity Forms copies into every field), confirmations and notifications. Diff sections: `fields.title`, `fields.active`, `settings`, `form_fields`, `confirmations`, `notifications`. Maps are key-sorted before hashing and comparing, so two copies of a form compare equal whatever order the keys were saved in.
- **Not synced: entries.** They are what visitors submitted, personal data that belongs to the site that received it.
- **Not synced: add-on feeds** (`gf_addon_feed`). Feeds are add-on configuration that is usually environment-specific: payment add-ons in test or live mode, CRM lists, webhook URLs that point at staging. Their meta is defined by each add-on, so there is no generic way to tell which values are IDs to remap or settings to keep, and a feed is useless without the same add-on on the target. Sending a staging Stripe or Zapier feed to production unasked is riskier than recreating it there. A target's feeds are never touched, so a synced form keeps the feeds production gave it.
- **Matching:** the same ladder as menus, with Silta's mapping table (object type `form`): a stored mapping; the origin (a form that came from the target maps straight back); then the same ID **and** the same title, which is what a database clone leaves behind (form IDs are auto-increment, so the ID alone would match unrelated forms on sites that were never clones); then the single form with the same title (Gravity Forms keeps titles unique). Otherwise a new form is created, and Gravity Forms numbers its title if it is taken. No identifier is stored in the form meta: Gravity Forms copies form meta when it duplicates or exports a form, so it wouldn't stay unique.
- **References to forms** (`Integrations\Gravity_Forms`): the `formId` attribute of the `gravityforms/form` block, declared through `jcore_silta_block_references` like any other block attribute, the `id` of the `[gravityform]` and `[gravityforms]` shortcodes in post content and in plain-text meta (WYSIWYG custom fields; serialized values are left alone), and ACF fields of type `forms` (the "ACF: Gravity Forms" add-on), also in ACF blocks. The `form` kind also works in the `jcore_silta_meta_references` and `jcore_silta_block_references` filters. Referenced forms are dependencies, offered like referenced posts and media. Unlike post references, an unresolved form ID keeps its source value (an embed without an ID can't render anything; `Context::transform()` does this for the `form` kind) and is reported as unresolved.
- **References in forms:** a confirmation that redirects to a page (`pageId`) is remapped, and the page is a dependency of the form. Forms import before posts, so posts that embed them resolve; a form whose confirmation page is created later in the job is fixed up in the second pass, like posts.
- **Search-replace** applies to every text in a form: settings, field labels, descriptions, defaults, choices and HTML content, confirmation messages and redirect URLs, notification messages. For a user without `unfiltered_html`, an incoming form first goes through Gravity Forms' own `GFFormsModel::sanitize_settings()`, as its form editor and import do (GFAPI doesn't), keeping the source's `version`. Then kses runs on every HTML property wherever it is: settings messages, page names, labels, descriptions, defaults and HTML content of fields and repeater sub-fields, choice texts, input labels, and confirmation and notification messages. Values that aren't HTML (choice values, conditional-logic operators, notification subjects and addresses) are left alone.
- **Undo** restores a snapshot of the whole form with `update_form`. A form the job created is deactivated and moved to Gravity Forms' trash rather than deleted, because deleting a form deletes its entries, and visitors may have submitted some since.
- **Permissions:** like menus, a user with `jcore_silta_sync` but without `manage_options` is checked on the initiating site: `gravityforms_edit_forms` to export a form on a push, and to overwrite one on a pull (`gravityforms_create_form` for a new one), checked with `GFCommon::current_user_can_any()`, which also accepts `gform_full_access`.
- **Dev stack and tests:** Gravity Forms is installed from its Composer repository only when a license key is available (`dev/.env` locally, a repository secret in CI), into a Composer project under `dev/` that the plugin's own `composer.json` and the release build never see. Without a key, the stack runs without it and the integration tests skip the Gravity Forms checks.

### WooCommerce

Added 2026-11-09 for the products of free WooCommerce core; of the paid extensions, Product Bundles is covered (see [WooCommerce Product Bundles](#woocommerce-product-bundles)), others (Subscriptions, Composite Products…) are not. Products are ordinary posts for Silta: the `product` post type, its core fields, featured image, terms (`product_cat`, `product_tag`, `product_type`, `product_visibility`, `product_shipping_class`, `product_brand`) and meta already travel through the generic path. `Integrations\WooCommerce` adds what that path can't know, and hooks in only when WooCommerce is active.

- **Default: orders are never synced.** `shop_order`, `shop_order_refund` and `shop_order_placehold` (HPOS placeholders) are in `Content::EXCLUDED_TYPES`, so a store on legacy post storage doesn't offer orders (personal data) in the picker, in whole-post-type syncs or as dependencies. The same goes for WooCommerce's push-notification tokens (`wc_push_token`) and, though it belongs to a paid extension, `shop_subscription`, since excluding personal data costs nothing.
- **Default: ID lists separated by commas.** WooCommerce stores some ID lists as `"12,34"` rather than serialized arrays: `_product_image_gallery`, and coupons' `product_ids` and `exclude_product_ids`. `References\Values::map_raw()` treats a value that is only numbers and commas as a list: each ID is a dependency and is remapped, a dropped one is removed from the list, and when nothing is dropped the separators stay byte for byte. Any key declared through `jcore_silta_meta_references` gets the same handling, so themes can declare their own comma lists.
- **Default: ID meta.** The integration declares, through `jcore_silta_meta_references`: `_product_image_gallery`, `_upsell_ids`, `_crosssell_ids` and `_children` (grouped products' children) as posts, and the term meta `thumbnail_id` (category and brand images) as a post. Coupons' `product_ids`, `exclude_product_ids` are posts and `product_categories`, `exclude_product_categories` terms. Variation meta `attribute_pa_*` holds term slugs, which survive a sync without remapping. The keys are declared only while WooCommerce is active, and then apply to any post or term that has them.
- **Default: derived data is the target's own.** Silta writes meta rows directly (`Sync\Meta_Writer`), so no meta hooks fire and WooCommerce's derived data would go stale. Instead of copying it, the target derives it:
  - These are never synced (not exported, compared, hashed or written): `_price` (WooCommerce computes it from the regular and sale price and the sale schedule, and for variable and grouped products from their children), `_product_version`, and the `product_visibility` terms `outofstock` and `rated-1`…`rated-5` (they follow stock and reviews). `featured`, `exclude-from-search` and `exclude-from-catalog` are authored and travel as before.
  - After an import or undo writes products (`jcore_silta_post_written`, collected until `jcore_silta_writes_done`), the integration runs each through WooCommerce: variable and grouped products are synced with their children (`WC_Product_Variable::sync()`, `WC_Product_Grouped::sync()`, which set `_price` and a variable product's stock status), `_price` of any other product or variation is set from its regular and sale price and schedule, the product is saved through its data store (so `woocommerce_update_product` fires), the visibility terms are rebuilt from the product's featured flag, catalog visibility, stock status and rating, the `wc_product_meta_lookup` row is refreshed (`refresh_product_lookup_table()`, or the data store's own method before WooCommerce 10.8), the product attributes lookup is regenerated (`LookupDataStore::on_product_changed()`), and product transients are cleared. Variations go first, then every product written or whose variations were written, trashed or restored, once per batch. A grouped product isn't refreshed when only one of its children was synced; its price follows the next time it is synced or saved.
  - Product types of extensions can take over the price step (`jcore_silta_woocommerce_sync_price`), add to the visibility terms (`jcore_silta_woocommerce_visibility_terms`) and use the lookup refresh (`WooCommerce::refresh_meta_lookup()`). The `wc_product_meta_lookup` row is refreshed through the product type's own data store, since an extension's (a bundle's) reads prices from meta of its own. WooCommerce caches a product's type; it is invalidated when Silta writes a product, since the `product_type` terms were written behind its back.
  - Because derived data is left out of the state, a product's hash doesn't change when the store recalculates it, and sales on the target never turn into conflicts.
- **Decided: variations are companions of their product** (`product_variation` isn't offered in the picker: it has `show_ui` false). Exporting a product lists its variations under `companions`, and the job adds them at the product's level, ticked like it (ticking or unticking the product does the same to them, through the item's `owner`). They import right after it, as its children, so their parent resolves; their own references (a variation image) are the next dependency level, like the product's. Companions are a core mechanism: integrations add them through `jcore_silta_export_object`.
  - Matching: mapping, origin, GUID, then within the product's copy on the target the one variation with the same attributes (`attribute_*` meta), then the one with the same non-empty SKU; otherwise a new variation. Variations never match by slug: WooCommerce generates their slugs from the product title, so they repeat across products (`jcore_silta_match_by_slug`). The extra steps come through `jcore_silta_match_post`.
  - Removed variations: when a product matches, the target lists its variations that came from this source in an earlier sync but that the source no longer has. They are offered as orphans of the product, **ticked by default** (a stale variation stays purchasable), moved to the trash and restored by undo. They are only trashed when the product itself was written; a product skipped as a conflict keeps them. Variations created on the target, or left from before the first sync, are never touched.
- **Decided: live store data stays the target's.** `_stock`, `_stock_status`, `total_sales`, `_wc_average_rating`, `_wc_review_count`, `_wc_rating_count` (products; variations have the first three), and coupons' `usage_count` and `_used_by` change on the live store through orders and reviews. They are left out of the state like derived data (`jcore_silta_post_state`), so a staging → production push never overwrites them, and sales on production never show up as conflicts. A product or variation the sync creates gets the source's `_stock` and `_stock_status` (carried in the bundle's `extra`, outside the hash); sales, ratings and coupon usage start empty. Stock settings (`_manage_stock`, `_backorders`, `_low_stock_amount`) are authored and sync as usual.
- **Decided: missing global attributes are created** (`pa_*` taxonomies exist only when the attribute is defined in `wc_attribute_taxonomies`). The bundle carries the definition (label, type, sort order, archives) of every global attribute a product or term uses. When the target lacks one, preflight lets the section through and the plan notes "Creates the product attribute “Color” (pa_color) on this site." At the start of the import batch the target creates it with `wc_create_attribute()` and registers the taxonomy for the rest of the request, as WooCommerce does on `init`, before any term is written. Creating needs `manage_product_terms` (on a push, the remote's pairing admin has it); without it preflight blocks the section with a message saying so. An attribute the product's term set doesn't use (an empty `pa_*` set) is not a problem and not created. Undo leaves a created attribute in place: it's configuration, and other products may use it by then.
- **Decided: a product type the target lacks blocks the product.** The source sends each product's type (`extra.woocommerce.type`). When the target's `wc_get_product_types()` doesn't have it, because the extension that adds it isn't active there, preflight blocks the product: "This site has no “bundle” products: WooCommerce Product Bundles is not active here. Activate it on this site, then sync again." Writing it anyway would leave an empty product of an unknown type (its `product_type` term would even be created as a dependency).
- **Decided: coupons stay syncable** (`shop_coupon`, offered in the picker when coupons are enabled), with their product and category lists remapped as above and their usage never synced.
- **Dev stack and tests:** unit tests cover the ID lists, the state filter, variation matching, attribute creation checks, the refresh order and the excluded order types. Integration checks (variable products with their variations, removed variations, coupons, a missing attribute, stock kept on the target) are added to `tests/integration/run.sh` once the dev stack installs WooCommerce and its seed fixtures.
- **Not synced:** WooCommerce settings and the page options (shop, cart, checkout, my account, terms), reviews (comments), shipping zones and classes' rates, tax rates, payment gateway settings, customers, orders and refunds, downloadable files' permissions. A downloadable product's file URLs go through search-replace, but files outside the media library are not transferred.

### WooCommerce Product Bundles

Added 2026-11-09. A bundle is an ordinary product for Silta, but its items and their settings live in the extension's own tables (`woocommerce_bundled_items`: item ID, product, bundle, order; `woocommerce_bundled_itemmeta`: quantities, optional, priced individually, discount, allowed variations, default attributes, overrides and visibility). `Integrations\WooCommerce_Product_Bundles` adds them, and hooks in only when WooCommerce and `WC_Product_Bundle` exist. It works through the core's integration hooks; the ones it needed (`jcore_silta_walk_post`, `jcore_silta_post_sections`, the `$wants` argument of `jcore_silta_post_written`, the `$incoming` and `$local` arguments of `jcore_silta_plan_notes`, and the WooCommerce refresh hooks above) were added for it and are generic.

- **Decided: items are part of the bundle's state.** `jcore_silta_post_state` adds `bundled_items`, read through the extension (`WC_Product_Bundle::get_bundled_data_items()`, `WC_Bundled_Item_Data::get_data()`): per item its product, order and settings, normalized (settings sorted, the variation list sorted, malformed values dropped) so both sites compare equal. Item IDs are site-specific and left out. The list is hashed, so items edited on the target make a conflict, snapshotted, and offered as one diff section, *Bundled items*, with one line per item and per setting.
- **Decided: references.** Each item's product and the variations in its `allowed_variations` are references (`jcore_silta_walk_post`): the source makes them dependencies, so missing products sync first, and the target remaps them. An item whose product isn't on the target, and isn't in the job, is left out and the plan says so; a variation that doesn't resolve is dropped from the list. `default_variation_attributes` holds attribute slugs, which need no remapping.
- **Decided: written through the extension's CRUD.** When the section is to be written (it changed and is selected, the bundle is new, or undo restores it) the target pairs the incoming items with its own: each takes the first unused existing item with the same product, in bundle order, so a re-push or a reordering updates items in place and keeps their IDs (orders refer to them), and a product bundled twice pairs in order. It then calls `set_bundled_data_items()` and `save_items()`, never SQL on the extension's tables. Settings the source lacks are deleted from a kept item, since the extension merges them. An item's custom title and description go through kses unless the user may post unfiltered HTML. Item meta the extension derives from stock (`stock_status`, `max_stock`, `max_variation_stock`) is never synced.
- **Decided: items removed on the source are removed on the target**, like menu items: the bundle mirrors the source. There is no separate orphan step, since items aren't posts; the section's before and after show it, and the plan notes "Removes bundled items the source no longer has: Silta-muki (#61). Undo puts them back." Deselecting the section keeps the target's items. A bundle left with no items is set to draft by the extension, as when an admin empties it.
- **Decided: undo restores the items** from the snapshot through the same code. A removed item comes back as a new item with a new ID; past orders that referred to the old ID keep their own copies of the item's data. Undoing a created bundle trashes it, with its items.
- **Default: derived prices are the target's.** Besides `_price`, a bundle's `_regular_price` and `_sale_price` (the lowest price with its items), `_wc_pb_base_price`, `_wc_sw_max_price`, `_wc_sw_max_regular_price` and the stock meta `_wc_pb_bundled_items_stock_status`, `_wc_pb_bundled_items_stock_sync_status` and `_wc_pb_bundle_stock_quantity` stay out of the state. The authored base prices (`_wc_pb_base_regular_price`, `_wc_pb_base_sale_price`) and the sale dates sync. In the WooCommerce refresh the integration sets `_wc_pb_base_price` from them as the extension's data store does (`jcore_silta_woocommerce_sync_price`; core's price step would write the base price into `_price`). After the whole batch is refreshed (`jcore_silta_writes_done`, priority 20, so the bundled products' prices are already right), each written bundle is loaded fresh and synced with `WC_Product_Bundle::sync( true )`, its raw prices are saved (`save_raw_price_props()`), its lookup row is refreshed and its transients are cleared. A bundle whose items are out of stock keeps the `outofstock` visibility term, as the extension sets it.
- **Decided: a target without the extension refuses bundles** in preflight (see the product type decision under [WooCommerce](#woocommerce)), so a bundle never arrives as an empty product.
- **Dev stack and tests:** `dev/seed/woocommerce/woocommerce-product-bundles.php` creates "Silta-paketti" on staging: the mug (one) and the shirt (optional, one or two, priced individually with 10 % off, only its red variation allowed and the default). Unit tests cover normalizing, remapping, pairing, the diff section, the plan notes, the HTML filter and the product type check. The integration checks refuse the bundle while production lacks the extension, then push it and check the type, the items' products and variation, their settings, the price and lookup row, that a second push changes nothing and a changed setting updates the items in place, the removal of an item and its undo.

### Diff mode

- **Decided: choose by section, then by field.** Sections: core fields (each field can be picked), content, featured image, custom fields (each field can be picked; ACF top-level fields are shown by label), one section per taxonomy, language and translations. Choices are per post. Bulk actions apply a choice to every post in the run, e.g. "content only".
- **Decided: diffs look like the revisions screen.** Content is a side-by-side text diff of the block markup with one block per line, using the same Text_Diff as core revisions. Fields and terms show as before/after tables. Unchanged sections are collapsed.
- With diff mode off, the run syncs every section of every selected post.

### Safety

- **Decided: snapshots and undo.** Before the target overwrites a post, term set, menu, form or attachment, it saves a full snapshot (core fields, meta, terms, language) to its own table. History lists jobs on both sides, and *Undo* restores the snapshots and trashes anything the job created (attachments are deleted with their files). A remote can only undo jobs it ran on this site. **Default:** snapshots are kept for 30 days (filterable).
- **Decided: preflight blocks mismatches.** The handshake exchanges the protocol version, post types, taxonomies, ACF field keys (matched by key, then by name) and Polylang languages. Anything the target lacks shows as a per-post or per-section error, and that part can't be synced. A different major protocol version blocks the connection.
- **Decided: permissions.** Syncing needs the `jcore_silta_sync` capability, which administrators get on activation and which can be granted to editors. Managing connections and keys needs `manage_options`. The remote also checks the key's scope.
  - A user with `jcore_silta_sync` but without `manage_options` sees and runs only their own jobs. Each object is checked against their own capabilities, since the remote acts as its pairing admin:
    - on a push, `read_post` for what they send;
    - on a pull, `edit_post` for posts it overwrites, the post type's `create_posts` for new ones, and `delete_post` for orphans. Like the post editor, publishing, scheduling or making a post private needs `publish_posts`, and giving it another author needs `edit_others_posts`;
    - the taxonomy's term capabilities for terms, and `edit_theme_options` for menus.
  - Only administrators can save replacement rules on a connection or change them for a run; everyone else syncs with the connection's saved rules. On a push the remote writes the result as its pairing admin, usually without kses, so per-run rules would let a user send HTML they couldn't write themselves.
  - On a push, only administrators can pick which post on the remote an item overwrites, since the diff shows that post's current content. Others get the automatic match or a new copy.
  - WP-CLI without `--user` counts as an administrator.
  - Imported and restored HTML goes through kses unless the acting user has `unfiltered_html`. That's the user running the sync, or on the remote the pairing admin. This respects `DISALLOW_UNFILTERED_HTML` and multisite. Undo restores meta and forms from the snapshot unfiltered, since they are the site's own earlier values.
- **Decided: only content types offered in the picker sync.** Templates, global styles, custom CSS, navigation posts and ACF's own field group and post type definitions are never exported or imported, nor trashed, auto-draft or revision posts. Terms must be in a taxonomy registered for the post's type. Filters: `jcore_silta_syncable_post_type`, `jcore_silta_syncable_taxonomy`, `jcore_silta_syncable_statuses`.

### Interface

- **Decided: four surfaces.**
  - **Admin page** (React). Connections; a picker (post types, search, filters, mixed selection, menus, media); preview and diff; run with progress; history and undo. **Default:** under *Tools → JCORE Silta*, since it's a tool rather than a setting.
  - **List table actions:** "Push to…" / "Pull from…" row and bulk actions that open the Silta page with those posts selected.
  - **Block editor panel:** this post's status on each connected site (in sync / changed / not synced), with a quick diff and push.
  - **WP-CLI:** `wp silta connections`, `wp silta push`, `wp silta pull`, `wp silta diff`, `wp silta jobs`, `wp silta undo`.
- **Decided: jobs are stored and driven by the browser.** The job (selection, options, plan, per-item status) is saved on the initiator. The UI runs it in small REST batches with a progress bar. A closed tab can be resumed from history, and WP-CLI runs the same job without time limits. A job can be stopped while it is preparing or running; what it wrote stays and can be undone. Only a job that never ran can be deleted, so a sync that wrote something stays in the history.

## Architecture sketch

```
Initiator                                         Remote
─────────                                         ──────
React app / CLI / list actions / editor panel
        │ jcore-silta/v1 (cookie + nonce, capability)
        ▼
Job runner ── Exporter (push) / Planner+Importer (pull)
        │
Signed client ── Ed25519-signed HTTP ──────────▶ jcore-silta/v1/remote/*
                                                  (key scope check)
                                                  Exporter (pull) / Planner+Importer (push)
```

The exporter always runs on the source, and the planner and importer always run on the target. Push and pull differ only in which calls are local and which go over HTTP.

**Remote routes** (all `POST`, signed, scope in brackets): `pair` [pairing token instead of a signature], `handshake` [any], `query` (list/search content for the picker) [read], `ids` (every ID of a post type) [read], `export` [read], `media/read` (one chunk of an attachment's file) [read], `status` (mapping state for the editor panel) [any], `plan` [write], `diff` [write], `media/prepare` and `media/write` (chunked upload) [write], `import` [write], `orphans` [write], `undo` [write].

**Planned components:** `Site` (UUID, clone detection), `Crypto\Identity` (key pair) and `Crypto\Vault` (encryption at rest), `Connections` (outgoing) and `Trusted_Keys` (incoming keys and pairing tokens), `Transport\Client` / `Transport\Verifier`, `Export\Exporter`, `References\*` (core, blocks, ACF, SEO, Polylang, menus), `Import\Matcher`, `Import\Planner`, `Import\Importer`, `Replace\Replacer`, `Media\Transfer`, `Jobs\Runner`, `Snapshots`, `Database`, `Rest\*`, `Admin\*`, `Cli\*`.

**Tables** (via `Database::maybe_upgrade()`):

| Table | Holds |
| --- | --- |
| `jcore_silta_map` | remote site UUID, object type, remote ID → local ID, hash last received, last synced at |
| `jcore_silta_jobs` | job UUID, role (initiator/target), connection, direction, user, options, status, progress, timestamps |
| `jcore_silta_job_items` | job, object type, remote/local ID, action (create/update/skip/trash), chosen sections, status, error, snapshot |
| `jcore_silta_snapshots` | job, object type, local ID, serialized prior state, created at |
| `jcore_silta_nonces` | hash of key and request nonce, expiry (replay protection) |
| `jcore_silta_transfers` | open media transfers: UUID, peer, created at, manifest (files, sizes, hashes, targets) |

**Options:** `jcore_silta_site`, `jcore_silta_identity`, `jcore_silta_connections`, `jcore_silta_keys`, `jcore_silta_settings`. All of these, and the tables, are removed in `uninstall.php`.

**Limits (defaults, filterable):** 10 posts per batch, 2 MB media chunks (capped to fit the request body), 8 MB maximum request body (`jcore_silta_max_request_bytes`), 100 export keys or bundle objects per request (`jcore_silta_max_export_keys`, `jcore_silta_max_request_objects`), 32 MB maximum response (`jcore_silta_max_response_bytes`). A media transfer holds at most 256 files and 5 GB, and a site may have 10,000 open transfers (`jcore_silta_media_max_files`, `jcore_silta_media_max_bytes`, `jcore_silta_media_max_open_transfers`). Unfinished transfers expire after a day.

## Development environment

- **Decided:** `dev/` holds a Docker Compose stack modelled on `video-optimizer/dev`: *staging* on <http://localhost:8090> and *production* on <http://localhost:8091> (8080 is taken by the Pakkaus stack), MariaDB, wp-cli and the plugin mounted read-only into both. `dev/setup.sh` installs WordPress on both sites, plus Secure Custom Fields (the free ACF fork with repeater and flexible content), Polylang and Yoast. It seeds field groups, content and images, and pairs the sites. A mu-plugin maps `localhost:809x` to the container hostnames for server-to-server calls.
- `pnpm playground` remains for quick single-site UI work.
- **Decided: tests.** PHPUnit unit tests for the pure logic: matching, remapping, serialization-safe replace, diffing and signatures. Integration tests drive real pushes and pulls between the two Docker sites. Both run in CI.

## Open questions

1. ACF options pages were deferred from v1. Is that fine for the first staging → production use?

## Build order

1. Dev stack and seed data; PHPUnit and CI skeleton.
2. Site identity, key pair, pairing, connections, signed transport, handshake and preflight.
3. Export, plan and import for core fields, content, generic meta and terms; matching ladder, mappings, search-replace, jobs and batches; a minimal picker and run UI. Push and pull both work end to end.
4. Media transfer, reference handlers (blocks, ACF including ACF blocks, SEO, featured image), dependency discovery.
5. Diff mode and conflicts.
6. Snapshots, undo, history, orphans.
7. Polylang, menus, standalone media, `wp_block`.
8. List table actions, block editor panel, WP-CLI.
9. readme.txt, translations (fi, sv_SE), release setup.
