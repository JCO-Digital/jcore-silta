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

Once pairing is done, the pasted string is useless, so it doesn't matter if it lingers in Slack or email.

**Requests.** Every remote call is a `POST` with a JSON body. The initiator signs
`silta-v1 \n <remote site UUID> \n <method> \n <route> \n <timestamp> \n <nonce> \n <sha256 of body>`
and sends `X-Silta-Key` (its key fingerprint), `X-Silta-Timestamp`, `X-Silta-Nonce` and `X-Silta-Signature`. The remote looks up the public key by fingerprint and checks the signature with `sodium_crypto_sign_verify_detached`. It then checks the timestamp is within ±5 minutes, the nonce hasn't been seen (cached for 10 minutes), the key is enabled, and the route's scope. Including the remote's UUID stops a signed request being replayed against another site that trusts the same key.

The remote then acts as the admin who created the pairing token. Imports run with that user's capabilities, and if that user loses `manage_options` or is deleted, the connection stops working. Remote routes are excluded from Application Password handling, because otherwise a basic auth header for a protected staging site would be read as a failed WordPress login.

HTTPS is required for remote URLs, except when the environment type is `local` or `development` (filterable). A signature proves who sent a request but doesn't hide its content.

**The private key.**

- **One key pair per site, created lazily** with `sodium_crypto_sign_seed_keypair()` the first time the site adds an outgoing connection, so no CLI or server access is needed. A site that only receives syncs (production, in the main use case) never has a private key, so copies of its database hold nothing that can sign a request. Sodium is built into PHP, and WordPress bundles a pure-PHP fallback.
- **Stored in the database, encrypted.** The option `jcore_silta_identity` holds the public key, the 32-byte seed encrypted with `sodium_crypto_secretbox`, and the home URL the key was created for. The encryption key is derived with HKDF from `SECURE_AUTH_KEY` and `SECURE_AUTH_SALT` in `wp-config.php`. A database-only leak (dump, backup, SQL injection, a refresh into an environment with other salts) therefore yields nothing usable. If the salts live in the database instead (rare), the encryption adds nothing, and the Connections screen says so.
- **Optionally in `wp-config.php`** as `define( 'JCORE_SILTA_PRIVATE_KEY', '…' );` (base64 seed). The Connections screen can show this line, and `wp silta key export` prints it. Once the constant is detected, the copy in the database is deleted. The key then survives database refreshes. It's never required.
- **Key ID:** the first 12 bytes of SHA-256 of the public key, in hex.

**Clones, refreshes and rotation.**

- A site's identity, meaning its UUID and key, is tied to its home URL. When the home URL changes (a database clone or refresh), the site gets a new UUID and discards a key it finds in the database, even one it could decrypt because the salts were copied too. A key from `wp-config.php` is kept. Outgoing connections and trusted keys that came with the copied database are marked *inherited* and turned off until an admin reviews them. A notice explains what happened.
- If the salts are rotated, the key can't be decrypted any more. The site says so, creates a new key pair, and each remote gives you a fresh pairing string.
- Revoking a site on the remote deletes its public key, and requests from it fail immediately.

### Identity and matching

- **Default: site identity.** Each site has a UUID in `jcore_silta_site` together with the home URL it was generated for. If the home URL changes (a DB clone), the site gets a new UUID. Then a clone doesn't share an identity with its origin, and the cloned connections and keys are marked as needing re-verification.
- **Decided: matching ladder, with override in the preview.** For posts:
  1. a stored mapping (remote site + remote ID → local ID);
  2. a reverse mapping: if this post originally came from the target, the bundle says so, and it maps straight back (round trips work);
  3. the same GUID (survives DB clones);
  4. the same post type, slug and parent;
  5. otherwise, create a new post.

  The preview shows how each post was matched. You can pick another target post or force "create new".
- Attachments use the same ladder, with "same `_wp_attached_file` path and file hash" instead of slug. Terms: mapping, then slug within the taxonomy (and parent). Menus: mapping, then menu slug.

### What is synced

- **Core fields:** title, slug, content, excerpt, status, dates, author, parent, menu order, template, comment/ping status, password, post format, sticky. **Default:** authors are matched by login, then email, then fall back to the user running the sync. `post_modified` is set by the target as usual.
- **Custom fields (decided):** every meta key is copied, except an exclude list (default: `_edit_lock`, `_edit_last`, `_wp_old_slug`, `_wp_old_date`, `_encloseme`, `_pingme`, Silta's own `_jcore_silta_*`, plus anything added with the `jcore_silta_excluded_meta` filter). Meta keys removed on the source are removed on the target. Values that hold IDs are remapped by these handlers:
  - **ACF / ACF Pro:** image, gallery, file, post object, relationship, page link, taxonomy, user and link fields, including inside repeaters, flexible content, groups and clones. Also **ACF block data** inside block comments.
  - **SEO plugins:** Yoast, Rank Math and SEOPress image IDs and URLs.
  - **Core block attributes:** `core/image`, `gallery`, `cover`, `media-text`, `file`, `video`, `audio`, `block` (ref), `navigation`/`navigation-link`, `query` (include/exclude/parents/taxQuery), `latest-posts` categories, `page-list`, and `wp-image-123` / `data-id` in markup. Markup is only re-serialised for blocks that actually changed, so re-saving doesn't add noise.
  - **Featured image** (`_thumbnail_id`).
  - **Filters** `jcore_silta_meta_references` and `jcore_silta_block_references`, so themes and other plugins can declare their own ID-bearing meta and block attributes.
- **Taxonomies (decided):** match or create. Missing terms are created with their parent chain and term meta. Existing terms on the target are never modified. A synced post's term set replaces the target's set for each taxonomy you sync.
- **Media (decided):** the original file and its generated sizes are streamed over the signed channel in chunks. Only sizes the target is missing are regenerated. Attachments are deduplicated by mapping, then by file hash, and attachment fields (title, alt, caption, description) are synced too.
- **Polylang (decided):** the post's language and translation group. Translations of a selected post are offered as dependencies, and links between translations are restored on the target. WPML is not in v1.
- **v1 extras (decided):** nav menus (menus, items and optionally locations, with linked objects remapped), standalone media in the picker, and reusable blocks/patterns (`wp_block`) as a selectable post type.
- **Not in v1:** ACF options pages, WPML, comments, users, widgets, site options.

### References and dependencies

- **Decided: discover and offer.** The source's exporter runs the reference handlers to list what each post depends on: media, referenced posts, terms, translations, parent pages. The target remaps references to anything it already has. Missing dependencies appear in the preview, ticked by default, one level at a time, and you can untick them. An unresolved reference keeps the source ID, and the job report warns about it.
- The handlers are the same code on both sides. They discover on the source and remap on the target. ACF field definitions travel in the bundle so the target remaps with the source's definitions.

### Search-replace

- **Decided: automatic URL swap plus saved rules.** The source's home and uploads URLs are replaced with the target's, in plain, JSON-escaped (`\/`) and URL-encoded forms. On top of that come find → replace pairs saved on the connection, which you can edit for each run. Plain strings only, no regex.
- It applies to title, content, excerpt, meta values, term meta and attachment fields.
- It is serialization-safe: serialized values are decoded (`allowed_classes => false`), walked, replaced and re-encoded, and JSON strings inside meta are walked the same way.
- Order: ID remapping first, then search-replace. The diff compares that transformed result with the target, so URL differences never show as changes.

### Conflicts, status and deletions

- **Decided: flag conflicts and require confirmation.** The target stores a hash of what it last received. If the target post's current hash differs (it was edited after the last sync or after the clone), the post is flagged as a conflict. It isn't overwritten until you confirm it, or pick sections for it in diff mode.
- **Decided: status is copied, with an override per run.** By default a published staging post publishes on production. Per run you can choose "land as draft" or "keep the target's status for existing posts". Scheduled posts keep their date.
- **Decided: deletions are only offered for orphans.** A sync never deletes by default. When you sync a whole post type, the preview lists target posts mapped to source posts that no longer exist. Any you tick are moved to the trash, never hard-deleted.

### Diff mode

- **Decided: choose by section, then by field.** Sections: core fields (each field can be picked), content, featured image, custom fields (each field can be picked; ACF top-level fields are shown by label), one section per taxonomy, language and translations. Choices are per post. Bulk actions apply a choice to every post in the run, e.g. "content only".
- **Decided: diffs look like the revisions screen.** Content is a side-by-side text diff of the block markup with one block per line, using the same Text_Diff as core revisions. Fields and terms show as before/after tables. Unchanged sections are collapsed.
- With diff mode off, the run syncs every section of every selected post.

### Safety

- **Decided: snapshots and undo.** Before the target overwrites a post, term set, menu or attachment, it saves a full snapshot (core fields, meta, terms, language) to its own table. History lists jobs on both sides, and *Undo* restores the snapshots and trashes anything the job created. **Default:** snapshots are kept for 30 days (filterable).
- **Decided: preflight blocks mismatches.** The handshake exchanges the protocol version, post types, taxonomies, ACF field keys (matched by key, then by name) and Polylang languages. Anything the target lacks shows as a per-post or per-section error, and that part can't be synced. A different major protocol version blocks the connection.
- **Decided: permissions.** Syncing needs the `jcore_silta_sync` capability, which administrators get on activation and which can be granted to editors. Managing connections and keys needs `manage_options`. The remote also checks the key's scope.

### Interface

- **Decided: four surfaces.**
  - **Admin page** (React). Connections; a picker (post types, search, filters, mixed selection, menus, media); preview and diff; run with progress; history and undo. **Default:** under *Tools → JCORE Silta*, since it's a tool rather than a setting.
  - **List table actions:** "Push to…" / "Pull from…" row and bulk actions that open the Silta page with those posts selected.
  - **Block editor panel:** this post's status on each connected site (in sync / changed / not synced), with a quick diff and push.
  - **WP-CLI:** `wp silta connections`, `wp silta push`, `wp silta pull`, `wp silta diff`, `wp silta jobs`, `wp silta undo`.
- **Decided: jobs are stored and driven by the browser.** The job (selection, options, plan, per-item status) is saved on the initiator. The UI runs it in small REST batches with a progress bar. A closed tab can be resumed from history, and WP-CLI runs the same job without time limits.

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

**Options:** `jcore_silta_site`, `jcore_silta_identity`, `jcore_silta_connections`, `jcore_silta_keys`, `jcore_silta_settings`. All of these, and the tables, are removed in `uninstall.php`.

**Limits (defaults, filterable):** 10 posts per batch, 2 MB media chunks, 8 MB maximum request body.

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
