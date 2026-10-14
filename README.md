# JCORE Silta

Silta ("bridge") syncs content between WordPress sites: single posts, whole post types, menus and media, together with their custom fields, terms, Polylang translations and the posts and media they refer to. URLs and custom rules are search-replaced on the way, a diff mode lets you pick section by section what to sync, and every sync can be undone. The main use case is staging → production.

This file covers the repository and the development workflow. What the plugin does for site owners is in [readme.txt](readme.txt); the design decisions and their reasoning are in [docs/design.md](docs/design.md).

## Requirements

- PHP 8.2+ (with sodium, or WordPress's bundled fallback)
- WordPress 6.7+
- Node 22+ and [pnpm](https://pnpm.io/)
- [Composer](https://getcomposer.org/)
- Docker with Compose, for the two-site development stack and the integration tests

## Getting started

```sh
pnpm install
composer install
pnpm build
cd dev && docker compose up -d && ./setup.sh
```

That gives you two paired sites: *staging* on <http://localhost:8090> and *production* on <http://localhost:8091> (log in as `admin` / `admin`). Production starts as a clone of staging, and the two have drifted apart since, the way real ones do. See [dev/README.md](dev/README.md) for what the seed creates and how to run WP-CLI on each site.

For UI work on a single site, `pnpm playground` serves [WordPress Playground](https://wordpress.org/playground/) on <http://localhost:8887> from `.wp/blueprint.json`.

## Scripts

| Command | What it does |
| --- | --- |
| `pnpm build` | Build the admin app and the block editor panel into `build/`. |
| `pnpm start` | Same, in watch mode. |
| `pnpm check` | Everything CI lints: ESLint, Stylelint and PHPCS. |
| `pnpm lint:js` / `lint:css` / `lint:php` | One linter at a time. |
| `pnpm format` | Format `src/` with `wp-scripts format`. |
| `composer test` | PHPUnit unit tests (no WordPress needed). |
| `composer lint:fix` | Fix what PHPCBF can fix. |
| `tests/integration/run.sh` | Two-site integration tests on a fresh dev stack (add `--no-reset` to reuse one). |
| `pnpm i18n` | Regenerate the POT, the MO files and the JS translation JSON. |
| `pnpm playground` | Serve the plugin in WordPress Playground. |

A `Makefile` wraps the same scripts (`make ci` is the entry point the shared publish workflow calls); it is a shim, not a second build system.

## How it works

Every sync has a **source** (where content comes from) and a **target**, and an **initiator** (the site whose admin or CLI runs it) and a **remote**. A push makes the initiator the source; a pull makes it the target. Only the initiator makes requests, so a local or password-protected site can push to production and pull from it.

```
Initiator                                         Remote
─────────                                         ──────
Admin app / WP-CLI / list actions / editor panel
        │ jcore-silta/v1 (cookie + nonce, capability)
        ▼
Jobs\Runner ── Endpoint\Local                     Rest\Remote_Controller
        │                                         (Ed25519 signature + scope)
        └───── Endpoint\Remote ── signed HTTP ──▶ Endpoint\Local
```

`Endpoint\Endpoint` is the list of operations a site offers (export, plan, import, media transfer, undo…). The runner talks to a source endpoint and a target endpoint; one is `Local`, the other `Remote`, so push and pull share one code path.

A job goes through these steps, each run in time-boxed slices so the admin app can drive it request by request and WP-CLI in a loop:

1. **Resolve** the selection (posts, whole post types, menus) into job items.
2. **Export** a batch on the source (`Sync\Exporter`): every object's state (`Sync\State`), the IDs it refers to (`References\Walker`), stubs for referenced objects, the users and ACF fields it mentions.
3. **Plan** the batch on the target (`Sync\Planner`): match each object to a local one (`Sync\Matcher`: stored mapping → origin → GUID → file path → slug), transform it into local terms (`Sync\Context`: remap IDs, search-replace URLs and rules), compare section by section (`Sync\Differ`), detect conflicts and missing dependencies, check the target can take it (`Sync\Preflight`).
4. Add the first level of **missing dependencies** and plan those; find **orphans** for whole post types.
5. **Review** (admin app) or straight on (CLI): include/exclude items, confirm conflicts, pick sections in diff mode, override matches.
6. **Run**: transfer media in chunks (`Sync\Media`), **import** in batches (`Sync\Importer`: terms, media, posts, menus; dependencies first), snapshot everything changed (`Sync\Snapshots`), record mappings (`Sync\Mapping`), re-import items whose references were created later in the job, trash the chosen orphans.

Undo restores the snapshots, removes what the job created and untrashes what it trashed.

## Layout

```
jcore-silta.php                Plugin header, constants, autoloader, bootstrap
uninstall.php                  Removes options, tables, cron, capability and temp files
includes/
  class-plugin.php             Wires every component to its hooks
  class-database.php           Tables and migrations
  class-site.php               Site UUID and clone detection
  class-settings.php           Settings option
  class-capabilities.php       jcore_silta_sync, granted to everyone with manage_options
  class-cron.php               Daily pruning of snapshots, job items and file transfers
  crypto/                      Identity (Ed25519 key pair), Vault (encryption at rest), Signature
  remote/                      Connections (outgoing), Trusted_Keys (incoming), Client, Verifier
  endpoint/                    Endpoint, Local, Remote
  jobs/                        Jobs (tables), Runner (drives a job)
  sync/                        Exporter, State, Planner, Importer, Context, Matcher, Differ,
                               Preflight, Replacer, Mapping, Snapshots, Media, Menus,
                               Meta_Filter, Meta_Writer, Acf, Polylang, Content
  references/                  Walker, Blocks, Acf_Values, Meta_Keys, Values
  rest/                        REST controllers
  admin/                       Tools page, list table actions, editor panel loader, notices
  cli/                         `wp silta` commands
views/admin/page.php           Mount point for the admin app
src/admin/                     Admin app (sync, history, connections, settings)
src/editor/                    Block editor panel
dev/                           Two-site Docker stack
tests/unit/                    PHPUnit tests for the pure logic
tests/integration/run.sh       Two-site integration tests
docs/design.md                 Design decisions
languages/                     .po sources; .pot, .mo and .json are generated
```

Classes autoload from the `Jcore\Silta` namespace: `Jcore\Silta\Sync\Meta_Filter` lives in `includes/sync/class-meta-filter.php`.

## REST API

Everything lives under `jcore-silta/v1`.

**Admin app.** Cookie authentication with the REST nonce. Routes marked *sync* need `jcore_silta_sync`; the rest need `manage_options`.

| Route | Methods | Cap | |
| --- | --- | --- | --- |
| `/sources` | GET | sync | Connections to pick from |
| `/source` | GET | sync | Post types, menus and languages of the source side |
| `/source/posts` | GET | sync | Search the source side's posts |
| `/jobs` | GET, POST | sync | History; create a job |
| `/jobs/{id}` | GET, DELETE | sync | Progress; drop a job that never ran |
| `/jobs/{id}/prepare`, `/expand`, `/run`, `/undo`, `/cancel` | POST | sync | Drive a job |
| `/jobs/{id}/items` | GET, PATCH | sync | Review items; bulk changes |
| `/jobs/{id}/items/{item}` | PATCH | sync | Include, confirm, choose sections, override the match |
| `/jobs/{id}/items/{item}/diff` | GET | sync | Section-by-section diff |
| `/status/{post}` | GET | sync + edit_post | A post's state on every site this one can push to |
| `/connections` | GET, POST | admin | Overview; pair with a remote |
| `/connections/{id}` | PATCH, DELETE | admin | Label, basic auth, saved rules; remove |
| `/connections/{id}/verify` | POST | admin | Fresh handshake |
| `/keys/tokens` | POST | admin | Create a pairing string |
| `/keys/tokens/{id}` | DELETE | admin | Cancel a pairing string |
| `/keys/{fingerprint}` | PATCH, DELETE | admin | Change or revoke a paired site |
| `/identity/export`, `/identity/regenerate` | POST | admin | The wp-config.php line; a new key pair |
| `/cloned` | DELETE | admin | Dismiss the clone notice |
| `/settings` | GET, POST | admin | Settings |

**Between sites.** Every route is `POST` and signed with the initiator's Ed25519 key (`X-Silta-Key`, `-Timestamp`, `-Nonce`, `-Signature` over `silta-v1 \n remote UUID \n method \n route \n timestamp \n nonce \n sha256(body)`), except `pair`, which takes a one-time pairing token. The remote acts as the admin who created the token.

| Route | Scope |
| --- | --- |
| `/remote/pair` | pairing token |
| `/remote/handshake`, `/remote/status` | any |
| `/remote/query`, `/remote/ids`, `/remote/export`, `/remote/media/read` | read |
| `/remote/plan`, `/remote/diff`, `/remote/media/prepare`, `/remote/media/write`, `/remote/import`, `/remote/orphans`, `/remote/undo` | write |

## Database tables

| Table | Holds |
| --- | --- |
| `{prefix}jcore_silta_map` | Which local object a remote one was synced to, with hashes of what was received and written (for conflict detection) |
| `{prefix}jcore_silta_jobs` | Jobs: role (initiator/target), direction, connection, status, options, state, summary |
| `{prefix}jcore_silta_job_items` | Per object: plan, payload, choices and result (pruned with old jobs) |
| `{prefix}jcore_silta_snapshots` | State before a job changed something, for undo (pruned after the configured days) |
| `{prefix}jcore_silta_nonces` | Request nonces already seen, for replay protection (pruned daily) |
| `{prefix}jcore_silta_transfers` | Open media transfers and their manifests (expire after a day) |

Options: `jcore_silta_settings`, `jcore_silta_site`, `jcore_silta_identity`, `jcore_silta_connections`, `jcore_silta_keys`, `jcore_silta_cloned`, `jcore_silta_db_version`. Schema changes go through `Database::maybe_upgrade()` with a bumped `DB_VERSION`.

## Hooks

| Hook | Type | |
| --- | --- | --- |
| `jcore_silta_meta_references` | filter | Meta keys that hold IDs: `array( '<key or prefix*>' => 'post'\|'term'\|'user' )` |
| `jcore_silta_block_references` | filter | Block attributes that hold IDs: `array( 'jco/hero' => array( 'imageId' => 'post', 'posts.*' => 'post' ) )` |
| `jcore_silta_excluded_meta` | filter | Meta keys never synced (a trailing `*` matches a prefix) |
| `jcore_silta_post_types` | filter | Post types offered in the picker |
| `jcore_silta_syncable_post_type` / `_taxonomy` / `_statuses` | filter | Whether a post type, taxonomy or post status may be exported and imported at all |
| `jcore_silta_allow_insecure` | filter | Whether a remote may use plain http (default: only on `local`/`development` environments) |
| `jcore_silta_allow_private_network` | filter | Whether a remote may be on a loopback or private address (default: the same as `jcore_silta_allow_insecure`) |
| `jcore_silta_max_request_bytes`, `_max_response_bytes`, `_max_export_keys`, `_max_request_objects` | filter | Size limits of signed requests and responses (8 MB, 32 MB, 100, 100) |
| `jcore_silta_media_max_files`, `_media_max_bytes`, `_media_max_open_transfers` | filter | Media transfer limits (256 files, 5 GB, 10,000 open per site) |
| `jcore_silta_remote_url` | filter | Rewrite the URL of a request to a remote (the dev stack maps localhost ports to container names) |
| `jcore_silta_clone_detected` | action | Fires when Silta notices it runs on a copy of another site's database |

ACF fields (image, file, gallery, post object, relationship, page link, taxonomy, user, also inside repeaters, flexible content, groups, clones and ACF blocks), core blocks, featured images, parents, terms, Polylang translations, menu items and the Yoast / Rank Math / SEOPress image and primary-term keys are handled without configuration.

## WP-CLI

```sh
wp silta keys create-token --label=Staging --scopes=read,write --user=admin   # on the remote
wp silta connections add <pairing string> --user=admin                          # on the initiator
wp silta push production --post=12,34 --dry-run
wp silta push production --post-type=event --trash-orphans --yes
wp silta pull production --post=56 --status=draft
wp silta diff production --post=12
wp silta jobs
wp silta undo <job id> --yes
wp silta key info | export | regenerate
```

`wp help silta` lists every command and option.

## Testing

- `composer test` runs the PHPUnit unit tests for the logic that needs no database: the serialization-safe replacer, ID remapping in blocks, ACF values and meta, and request signatures.
- `tests/integration/run.sh` resets the dev stack and runs real syncs between the two sites: pairing, unsigned requests, dry runs, conflicts, dependencies and remapping, translations, undo, block content, media, pull, menus, whole post types with orphans, and clone detection. CI runs it in `.github/workflows/integration.yml`.

## Releasing

Pushing to `main` runs `.github/workflows/release.yml`:

1. **check** – lint, unit tests, build, then run Plugin Check against the tree minus `.distignore`. Also runs on pull requests.
2. **release** – [foonver](https://github.com/foonly/foonver) reads the conventional commits since the last tag, bumps the version, syncs it into `jcore-silta.php`, `readme.txt` and `package.json`, writes the `== Changelog ==` section of `readme.txt` and pushes the tag.
3. **publish** – the reusable workflow in [jcore-update](https://github.com/JCO-Digital/jcore-update) builds the zip, attaches it to a GitHub release, registers the version with `update.jcore.fi`, pushes to the dist repository and posts to Slack.

Every commit that reaches `main` produces a release, so work on branches and merge through pull requests. Commit messages must follow [Conventional Commits](https://www.conventionalcommits.org/).

`.distignore` is the single list of what does not ship.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
