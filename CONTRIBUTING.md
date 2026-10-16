# Contributing to CyphtWebmail

How this module is put together and how to extend it. For using and installing
it, see the [wiki](https://github.com/cypht-org/dolibarr-cyphtWebmail/wiki).

Read [Project layout](#project-layout) and [How it works](#how-it-works) first,
the rest of this file assumes them.

## Table of contents

- [Project layout](#project-layout)
- [How it works](#how-it-works)
- [Development setup](#development-setup)
- [Adding a new Cypht module set](#adding-a-new-cypht-module-set)
- [Adding a new bridge endpoint](#adding-a-new-bridge-endpoint)
- [Adding something to a Dolibarr page](#adding-something-to-a-dolibarr-page)
- [Passing a Dolibarr setting to Cypht](#passing-a-dolibarr-setting-to-cypht)
- [Patching Cypht](#patching-cypht)
- [Database changes](#database-changes)
- [Translations](#translations)
- [Packaging and releasing](#packaging-and-releasing)
- [Conventions](#conventions)
- [Testing checklist](#testing-checklist)

## Project layout

```
cyphtwebmail/
├── .github/workflows/release.yml   tag push builds the release zip
├── index.php                       the Dolibarr page hosting the Cypht iframe
├── cyphtwebmail_version.inc.php    module version constant
├── admin/
│   ├── setup.php                   settings tab and build tab
│   └── build/                      endpoints the Generate button calls
├── bridge/                         HTTP endpoints Cypht calls back into Dolibarr
│   ├── contacts.php                contacts the user can see
│   ├── mail_templates.php          email templates the user can see
│   ├── context.php                 records linked to a sender address
│   └── create.php                  creates a prospect from a sender
├── build/
│   └── buildzip.php                creates module_cyphtwebmail-x.y.z.zip
├── class/
│   ├── webmail.class.php           facade over install/, auth/ and runtime/
│   ├── actions_cyphtwebmail.class.php  Dolibarr hooks: home page box, New message button
│   ├── auth/                       secrets, HMAC tokens, SSO login
│   ├── install/                    build, .env, upstream patches, database upgrade
│   ├── runtime/                    settings applied to Cypht on each request
│   └── integration/                Dolibarr data for Cypht: caches, bridges, mail counts
├── core/
│   ├── modules/                    Dolibarr module descriptor
│   └── triggers/                   USER_DELETE / USER_MODIFY cleanup
├── css/cyphtwebmail.css.php        Webmail box colour and icon
├── cypht/modules/                  our Cypht module sets, native Cypht layout
│   ├── dolibarr_contacts/          Dolibarr contacts as a Cypht address book
│   ├── dolibarr_context/           sender records under an open message
│   ├── dolibarr_dashboard/         saves the counts for the home page box
│   ├── dolibarr_mail_templates/    template picker on the compose page
│   ├── dolibarr_prefs/             language, theme and message format at sign-in
│   └── site/                       auth, session and database-backed user config
├── js/
│   ├── cypht-url-sync.js           keeps the URL in step with the iframe
│   ├── cypht-frame-size.js         makes the iframe fill the window
│   └── admin/setup.js              build tab
├── langs/                          en_US, fr_FR, de_DE, es_ES, it_IT
├── scripts/
│   ├── build.php                   command line build
│   └── bridge_probe.php            calls a bridge endpoint like Cypht does
├── sql/                            tables for a fresh install
│   └── migrations/                 NNN_*.sql, run on upgrade
├── docs/                           README images, left out of the release zip
├── composer.json, composer.lock    the jason-munro/cypht version
├── public/                         built app (generated, git-ignored)
├── vendor/                         Composer (generated)
└── dist/                           release zips (generated, git-ignored)
```

Code in `class/` is split by job: `install/` builds and installs Cypht,
`auth/` signs people in, `runtime/` runs on every request, `integration/`
moves Dolibarr data to Cypht. Module set code goes in `cypht/modules/`, never
in `vendor/`.

## How it works

```
Dolibarr page (index.php)
  │  writes the contact and template caches
  │  performSsoLogin()  ── HMAC token ──▶  Cypht cypht_login()
  │
  └─ <iframe> ──▶ public/index.php  (the built Cypht app)
                      │
                      ├─ Custom_Auth / Custom_Session / Custom_User_Config  (site)
                      ├─ dolibarr_contacts, dolibarr_mail_templates  ──▶ cache files, else bridge/
                      ├─ dolibarr_context      ──▶ bridge/context.php, bridge/create.php
                      └─ dolibarr_dashboard    ──▶ llx_cyphtwebmail_mailstatus
```

**Single sign-on.** Dolibarr mints a 60 second HMAC token, `Custom_Auth`
checks it. There is no Cypht password.

**User config in the database.** With no password to derive a key from,
`Custom_User_Config` keeps each user's settings in
`llx_cyphtwebmail_userconfig`, with mailbox passwords encrypted.

**Iframe.** Keeps Cypht's and Dolibarr's CSS and sessions apart.
`cypht-url-sync.js` keeps the page URL in step so reload and bookmarks work.

**Caches.** `index.php` writes the user's contacts and email templates to cache
files before the iframe loads, Cypht reads them from disk. The bridge
endpoints are the fallback, and serve what can't be cached (sender records,
prospect creation).

**Home page box.** `dolibarr_dashboard` saves the counts to
`llx_cyphtwebmail_mailstatus` while the webmail is open,
`CyphtMailStatus::load_board()` reads them back for Dolibarr's dashboard.

**Build.** Composer installs Cypht, our module sets are copied into it, the
upstream patches are applied, Cypht's `config_gen.php` compiles everything and
the result is published to `public/`.

## Development setup

Work in a Dolibarr install: clone into `htdocs/custom/cyphtwebmail`, build,
then switch the module on.

```bash
php scripts/build.php
```

Or press **Generate** on the **Build** tab of the setup page. The script takes
`--dolibarr=PATH`, `--owner=USER`, `--group=GROUP`, `--skip-permissions` and
`--quiet`.

| You changed | Then |
|---|---|
| PHP under `class/`, `bridge/`, `index.php` | reload the page |
| anything under `cypht/modules/`, or `composer.json` | build again |
| the module descriptor, hooks, permissions, menus | switch the module off and on |
| `sql/` | switch the module off and on, see [Database changes](#database-changes) |

To call a bridge endpoint without Cypht (`contacts`, `templates` or
`context`), signed as a Dolibarr user:

```bash
php scripts/bridge_probe.php --endpoint=context --login=admin --email=customer@example.test
```

Add `--url-only` to print the signed URL, or `--raw` for the raw response.

## Adding a new Cypht module set

A module set is Cypht's plugin format. See the
[Cypht developer docs](https://cypht.org/documentation/developer/dev-docs/)
and `vendor/jason-munro/cypht/modules/` for examples.

1. Create `cypht/modules/<your_module>/` with the native layout:

   | File | Required | Purpose |
   |---|---|---|
   | `README.md` | recommended | what the module set does |
   | `setup.php` | yes | registers handlers and outputs, returns input filters |
   | `modules.php` | yes | the handler and output classes |
   | `hm-<name>.php` | no | library classes, required from `modules.php` |
   | `site.css`, `site.js` | no | bundled into the build |

2. Add it to `CYPHT_MODULES` in `class/install/environment.class.php`. Order
   matters: a set comes after the sets it hooks into, for example
   `dolibarr_context` after `imap`.

3. Build. The log lists the installed sets, and the files land in
   `vendor/jason-munro/cypht/modules/<your_module>/`.

## Adding a new bridge endpoint

Bridge endpoints are HTTP calls from Cypht into Dolibarr. Copy
`bridge/context.php`.

1. Define the `NOLOGIN` constants and load `main.inc.php`.
2. Check the token with a purpose tag of its own, so a token for one endpoint
   can't be used on another:
   ```php
   $bridgeUser = CyphtBridgeAuth::userFromToken($db, $login, $token, 'yourpurpose', $status, $error);
   ```
3. Check the Dolibarr rights of `$bridgeUser` before reading anything.
4. Return JSON with `Cache-Control: no-store`.

On the Cypht side, add the endpoint URL to `buildEnvOverrides()` in
`class/install/environment.class.php` and read it in the module set with
`Hm_Environment::get('YOUR_KEY')`.

## Adding something to a Dolibarr page

Buttons and boxes on Dolibarr pages come from the hooks in
`class/actions_cyphtwebmail.class.php`:

- `addOpenElementsDashboardLine` and `addOpenElementsDashboardGroup` for the
  home page box (context `index`)
- `addMoreActionsButtons` for the New message button on cards

To add a page, add its hook context to `module_parts['hooks']` in
`core/modules/modcyphtWebmail.class.php` and handle it in the actions class.
Check the context with `$this->isContext()`, and reset `$this->results` at the
start of methods that fill it, Dolibarr reuses the object between calls.

Hooks are registered when the module is switched on, so switch it off and on
after changing the list.

## Passing a Dolibarr setting to Cypht

Per-user settings are applied when the user signs in to the webmail:

1. `CyphtLogin::exportPreferences()` in `class/auth/login.class.php` puts the
   value in the environment, for example `DOLIBARR_USER_COMPOSE_TYPE`.
2. A `functional_api` handler in `cypht/modules/dolibarr_prefs/` reads it and
   sets the Cypht user setting.
3. Add the value to `preferenceFingerprint()`, so changing it on the setup
   page starts a new webmail session with the new value.

## Patching Cypht

Fix bugs upstream first. Until a Cypht release has the fix, patch the vendored
copy at build time in `class/install/upstreampatches.class.php`:

- add a method that calls `patchFile()` with a callback returning the new
  content
- make it do nothing when the fix is already there, so it keeps working once
  upstream ships it
- call it from `CyphtPipeline` and from `scripts/build.php`, next to the other
  patches

Never edit `vendor/` by hand, Composer replaces it.

## Database changes

- **New install:** the `sql/*.sql` and `*.key.sql` files, run by Dolibarr when
  the module is switched on.
- **Upgrade:** add `sql/migrations/NNN_description.sql` and raise
  `SCHEMA_VERSION` in `class/install/upgrade.class.php`. Migrations run when
  the module is switched on, from the stored version up.

Keep both in step: a fresh install and an upgraded one must end with the same
tables.

## Translations

- Dolibarr side: `langs/<lang>/cyphtwebmail.lang`. Add a key to every
  language.
- Cypht side: `$this->trans('...')` in the module sets. `dolibarr_context`
  carries its own strings in `hm-dolibarr-context-lang.php`.

## Packaging and releasing

`build/buildzip.php` builds the release zip from the last commit (`git
archive`), runs the full build, removes what the runtime doesn't need, and
checks the result:

```bash
php build/buildzip.php --out=dist
```

The zip is deleted if its `.env` holds anything but placeholders for
`DB_NAME`, `DB_USER` and `DB_PASS`, or if build-only files or `.git` are left
in it. `docs/` is left out with `export-ignore` in `.gitattributes`.

To release:

1. Set the version in `cyphtwebmail_version.inc.php` and merge it into
   `master`.
2. Tag the merge commit with the same number and push the tag:
   ```bash
   git tag x.y.z
   git push origin x.y.z
   ```

The `Release` workflow then builds the zip and attaches it to a GitHub release.
It stops if the tag and the version differ.

**Known issue:** GitHub currently blocks `cypht-org/php-sieve-manager`, a
Cypht dependency, so `composer install` fails on a fresh machine and the
workflow fails. Until that is solved, build the zip locally, where `vendor/`
already has it, and create the release by hand:

```bash
php build/buildzip.php --out=dist
gh release create x.y.z --verify-tag --title "..." --notes-file notes.md dist/module_cyphtwebmail-x.y.z.zip
```

## Conventions

- Dolibarr style: tabs, `array()`, `dol_escape_htmltag()` on output,
  `GETPOST()` with an explicit filter.
- Change Cypht through `cypht/modules/` or an upstream patch, never in
  `vendor/`.
- Copy query patterns from Dolibarr core. Module keys and permission names
  differ.
- One job per class. PHP, JS and CSS in separate files.
- Short comments, only where the code doesn't say it.

## Testing checklist

- [ ] Build completes, `public/` refreshed
- [ ] SSO opens the webmail without a Cypht login screen
- [ ] Mail lists and messages load
- [ ] Compose suggests a Dolibarr contact
- [ ] A template inserts into the HTML editor, and an HTML message is delivered
- [ ] New message on an invoice fills the contact and the subject
- [ ] The Webmail box shows on the home page after opening the webmail
- [ ] The sender's records show on an open message
- [ ] Reload keeps the current webmail page, and the iframe fills the window
- [ ] Deleting a test user removes their `llx_cyphtwebmail_userconfig` row
