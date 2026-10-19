# Silta dev stack

Two WordPress sites for developing and testing Silta, **staging** and **production**, sharing one MariaDB server. Production starts as a database clone of staging, and then both are edited so they differ, the way a real staging/production pair does.

| Site | URL | Database |
| --- | --- | --- |
| Staging | <http://localhost:8090/wp-admin/> | `staging` |
| Production | <http://localhost:8091/wp-admin/> | `production` |

Log in to either site as `admin` / `admin`, or as `editor` / `editor`.

## Commands

```sh
cd dev
docker compose up -d && ./setup.sh   # build; safe to re-run, finished steps are skipped
./reset.sh                           # delete both sites and the database, then build again
docker compose stop                  # stop; the data stays in the volumes
```

To run WP-CLI, use the `cli-staging` and `cli-production` services:

```sh
docker compose run --rm cli-staging wp post list --post_type=any
docker compose run --rm cli-production wp option get silta_dev_seed --format=json
docker compose exec staging tail -f wp-content/debug.log
```

Both sites mount the repository read-only as `wp-content/plugins/jcore-silta`, so code changes take effect immediately. Each site has its own random salts in `wp-config.php` (Silta derives its encryption key from them). `WP_ENVIRONMENT_TYPE` is `local`, so plain-HTTP remotes are allowed. `WP_DEBUG_LOG` is on and `WP_DEBUG_DISPLAY` is off.

Ports 8080 and 8081 are already taken by another stack on this machine, so the sites use 8090 and 8091.

## Gravity Forms (optional)

Gravity Forms is commercial, so the stack only gets it with a license key that can install from [Gravity Forms' Composer repository](https://docs.gravityforms.com/install-gravity-plugins-and-add-ons-via-composer/) (an Elite or legacy Developer license):

```sh
cp .env.example .env    # set GRAVITY_FORMS_LICENSE_KEY; GRAVITY_FORMS_SITE_URL is optional
./reset.sh              # or ./setup.sh on an existing stack
```

`.env` is git-ignored. `setup.sh` runs `gravityforms/install.sh`, which reads the two variables (the environment wins over `.env`), passes them to Composer as `COMPOSER_AUTH` for that one command (HTTP basic auth on `composer.gravity.io`: the key is the user name, the site URL the password) and installs `gravity/gravityforms` from `gravityforms/composer.json` into `gravityforms/plugins/gravityforms`. Nothing is written to a tracked file. The CLI containers mount `gravityforms/plugins` at `/gravityforms`, and `setup.sh` copies the plugin into each site and activates it. Compose also passes the key to the sites, where it defines `GF_LICENSE_KEY` so Gravity Forms shows as licensed.

Without a key, `install.sh` prints a note and exits, and both sites run without Gravity Forms. If Composer fails (a wrong key, a license without Composer access), setup prints a warning and carries on without it.

In CI the key comes from the `GRAVITY_FORMS_LICENSE_KEY` repository secret (and the optional `GRAVITY_FORMS_SITE_URL` repository variable); see `.github/workflows/integration.yml`.

## What `setup.sh` does

1. Installs WordPress (Twenty Twenty-Five, `/%postname%/` permalinks) on both sites. It then installs Secure Custom Fields, Polylang and Yoast SEO from wordpress.org, [jcore-maailma](https://github.com/JCO-Digital/jcore-maailma) from its latest GitHub release, Gravity Forms when it was installed (see above), and activates `jcore-silta`. If a plugin fails to install or activate, setup prints a warning and continues. Set `SILTA_MAAILMA_ZIP` to another release zip URL to test another Maailma version, or to `none` to leave it out.
2. Runs `seed/seed.php` to seed staging. This happens once, guarded by the `silta_dev_seeded` option.
3. Clones staging into production, once (guarded by `silta_dev_cloned` on production). The clone dumps the `staging` database into a freshly recreated `production` database, then runs `wp search-replace http://localhost:8090 http://localhost:8091 --skip-columns=guid --all-tables`, then a copy of `wp-content/uploads` between the two volumes. Posts keep their IDs and their staging GUIDs.
4. Makes the sites diverge with `seed/diverge-staging.php` and `seed/diverge-production.php`, once each. Then `seed/maailma.php` adds global content to staging only (see below).
5. With Gravity Forms installed, runs `seed/gravityforms.php` on both sites, once each (guarded by `silta_dev_gf_seed`). It runs after the diverge step and the Maailma seed, so the post IDs the integration tests use are the same with and without Gravity Forms.
6. Pairs the sites. Production issues a `read,write` token labelled "Staging" with `wp silta keys create-token`, and staging adds it with `wp silta connections add`. In the other direction, staging issues a `read` token labelled "Production" for production. While the plugin has no `wp silta` command, this step prints a note and skips; once the command exists, run `./setup.sh` again.

## Seed content

`wp option get silta_dev_seed --format=json` lists the ID of everything below, on both sites.

- **Languages:** Suomi (`fi`, default) and English (`en`) in Polylang. Most content is in Finnish, and the English translations are linked to it.
- **Users:** `admin`, plus `editor` (Eeva Editori), who authors some posts and is the value of an ACF user field.
- **Media:** eight generated images (JPEG, PNG, WebP) with titles, alt text and captions. One of them, `saariston-panoraama.jpg`, is 3200 px wide, so WordPress serves a `-scaled` copy. There is also a one-page PDF, `hinnasto-2026.pdf`. A few files are attached to posts.
- **Terms:** categories (Uutiset › Yritysuutiset, Uutiset › Tuoteuutiset › Ohjelmistot, and Blogi), tags, and `event_type` terms (Konsertti, Työpaja › Verkkotyöpaja), all with English translations. Konsertti has term meta `silta_color` and an ACF image.
- **Pages:** Tietoa meistä › Tiimi and Historia, plus Yhteystiedot (template `page-no-title`), with their English translations. Tietoa meistä has a query loop over its child pages.
- **Events** (post type `event`) and a **reusable block** (`wp_block`, in fi and en) that pages and posts embed through `core/block`.
- **Posts:**
  - *Tervetuloa Siltaan* fills every field in the ACF content group: image, gallery, file, post object (single and multiple), relationship, page link, taxonomy, user and link fields, a wysiwyg field with a `wp-image-N` image, a repeater, flexible content and a group. It also has Yoast meta.
  - *Lohkojen esittely* is the block showcase: `core/image`, `gallery`, `cover`, `media-text`, `file`, `block` (ref), `query` (the 7.0 `taxQuery` include/exclude format, plus `exclude`), `latest-posts` (categories), and `acf/silta-card`. It also has links with `data-id`, and absolute URLs to the site and to uploads.
  - One sticky post, one draft, one scheduled post (30 days ahead), and one password-protected post (password `silta`).
  - Featured images on most posts.
- **Gravity Forms** (only when installed): on staging, *Yhteydenotto* (text, email, textarea and an HTML field with an absolute link, a confirmation that redirects to Yhteystiedot, a notification) embedded in the page *Ota yhteyttä* with the `gravityforms/form` block, and *Uutiskirje* embedded in the post *Tilaa uutiskirje* with the `[gravityform]` shortcode. On production, *Tuotannon palaute*, a form staging doesn't have, so staging's forms get other IDs there. `wp option get silta_dev_gf_seed --format=json` lists the IDs.
- **Menus:** *Main* (fi) holds a page with a nested child page, a category, a post, a custom link to `/events/` and an external link. *Main EN* is its English counterpart. Both are in the `silta-primary` location, one per language.

**Global content** (jcore-maailma, staging only, created after the clone; IDs in `wp option get silta_dev_maailma --format=json`): *Ota yhteyttä* (fi), which embeds an image and *Aukioloajat* by slug only, with its translation *Contact us* (en); two items titled *Alatunniste*, the second with the slug `alatunniste-2`; and the page *Maailma-esittely*, which shows *Ota yhteyttä* with the JCORE Global Content block.

After the clone, the two sites diverge as follows:

| | Staging | Production |
| --- | --- | --- |
| New posts | 3 | 2, with the same IDs as staging's first two |
| Edited | *Tervetuloa Siltaan* (content, ACF subtitle, an extra repeater row) and *Kesän kuulumiset* (title, featured image) | *Tervetuloa Siltaan* (title, excerpt, Yoast description), which conflicts with staging's edit |

## Content model: `mu-plugins/silta-fixtures.php`

This mu-plugin is loaded on both sites, so the content model and its ACF field keys are identical on each. It registers:

- the `event` post type and the hierarchical `event_type` taxonomy, both translated by Polylang;
- the `silta-primary` and `silta-footer` menu locations;
- three ACF field groups with readable keys (`group_silta_*`, `field_silta_*`):
  - `group_silta_content`, for posts, pages and events;
  - `group_silta_term`, an image on `event_type` terms;
  - `group_silta_card`, the fields of the `acf/silta-card` block.

## Container URLs: `mu-plugins/silta-dev-urls.php`

The browser reaches the sites at `localhost:8090` and `localhost:8091`, but inside a container those addresses point at the container itself. This mu-plugin filters `jcore_silta_remote_url`, which Silta applies to every URL it requests, and rewrites `http://localhost:8090` to `http://staging` and `http://localhost:8091` to `http://production`, the Compose service names. Connections and pairing strings keep the public URLs. The mu-plugin also points WP-Cron's loopback request at `http://localhost`.
