# CyphtWebmail

A Dolibarr module that embeds [Cypht](https://cypht.org), a lightweight open
source webmail client, inside Dolibarr - with single sign-on, Dolibarr contacts
as an address book, and mail accounts stored in Dolibarr's own database.

- Cypht upstream: <https://github.com/cypht-org/cypht>
- Cypht docs: <https://cypht.org>
- Dolibarr: <https://www.dolibarr.org>

Cypht is LGPL-2.1, Dolibarr is GPL-3.0. This module is GPL-3.0.

## Table of contents

- [What it does](#what-it-does)
- [Requirements](#requirements)
- [Installation](#installation)
- [Setup](#setup)
- [Daily use](#daily-use)
- [Where data is stored](#where-data-is-stored)
- [Configuration reference](#configuration-reference)
- [Troubleshooting](#troubleshooting)
- [Contributing](#contributing)

## What it does

| Feature | Notes |
|---|---|
| Embedded webmail | Cypht runs inside a Dolibarr page, with Dolibarr's menu and header |
| Single sign-on | Dolibarr users reach their mail without a second login |
| Dolibarr contacts in Cypht | Third parties and contacts appear as a read-only address book, including compose autocomplete |
| Accounts in the database | Mail accounts and settings live in `llx_cyphtwebmail_userconfig`, not in files |
| Encrypted credentials | Mailbox passwords are encrypted at rest; the rest of the config stays readable SQL |
| Deep-linkable | The browser URL follows the current Cypht page, so reload and bookmarking work |
| Lifecycle aware | Deleting or renaming a Dolibarr user cleans up or follows their webmail data |

## Requirements

To run it:

- Dolibarr 19+ (developed against **24.0**)
- PHP 8.0+ with `curl`, `openssl`, `mbstring`, `dom`, `PDO`
- MySQL/MariaDB or PostgreSQL (whatever Dolibarr already uses)

To build it, additionally:

- a **PHP CLI binary**, which the build invokes to run Cypht's `config_gen.php`
- **Composer**, or a `composer.phar` in the module root, or a `vendor/` that was
  prepared elsewhere
- `proc_open()` and `exec()` enabled

Only whichever side runs the build needs those three. Shared hosting often
disables `proc_open()` for the webserver, which is why the build can also be run
from a terminal.

## Installation

### 1. Put the module in an external modules directory

The module goes in one of Dolibarr's external module directories, set by
`$dolibarr_main_document_root_alt` in `<dolibarr>/htdocs/conf/conf.php`:

```php
$dolibarr_main_document_root_alt = '/path/to/dolibarr/htdocs/custom';
```

Clone or copy it there:

```bash
cd dolibarr/.../<your external modules directory>
git clone <repository-url> cyphtWebmail
```

If the module is not in the tree below Dolibarr, `scripts/build.php` cannot find
it on its own; pass `--dolibarr=/path/to/htdocs`. See
[Building from the command line](#building-from-the-command-line).

A release archive contains `vendor/` and a compiled `public/` already. That is
what makes it installable without a toolchain; see
[Packaging a release](#packaging-a-release).

### 2. Enable the module

**Home → Setup → Modules/Applications → Interfaces**, find **CyphtWebmail**,
switch it on.

Enabling creates the database tables, registers the triggers and adds the menu
entries. The build does none of that, so this step cannot be skipped.

That is the whole installation. Releases ship already compiled, so there is no
Composer step, no command line, and nothing to build.

### If you installed from git instead

A clone gives you source rather than a release, so it does have to be compiled
once:

```bash
cd <module directory>
php scripts/build.php
```

This needs a PHP CLI binary and either Composer or a prepared `vendor/`. It is
the same command the release process runs, and it does not need Dolibarr.

## Setup

### The setup page

**CyphtWebmail → Module setup**, or directly:

```
/custom/cyphtWebmail/admin/setup.php
```

This is where everything is configured. It has:

- **IMAP defaults** - name, server, port, TLS for the default account form
- **Cypht build status** - installed and built versions, last build date
- **Maintenance** - the Generate button and its log, shown only when Dolibarr
  is not in production mode. Releases ship compiled, so an ordinary
  installation never sees it. Set `CYPHTWEBMAIL_ENABLE_BUILD` to force it on

### Press Generate

Generate runs three steps and streams the log:

1. `composer install` - fetches/updates Cypht
2. `vendor/jason-munro/cypht/scripts/config_gen.php` - Cypht compiles its
   enabled module sets into `config/dynamic.php` plus bundled `site.css` /
   `site.js`. This is Cypht's own script, not the module's `scripts/build.php`
3. **publish** - copies the built `site/` into `public/`, which is what the
   browser actually loads

Before step 2 the module also writes Cypht's `.env` from Dolibarr's settings,
bridges the flat Composer layout, and installs its own Cypht module sets.

**Build again after:**

- installing or updating the module
- changing anything on the setup page
- editing anything under `cypht/modules/`
- running `composer update`

### Building from the command line

`php scripts/build.php` does the same three steps without a browser. When the
webserver cannot build, the setup page shows this command in place of the
button.

| Option | What it does |
|---|---|
| `--prepare` | dependencies and module sets only, no Dolibarr needed. For packaging |
| `--dolibarr=PATH` | where Dolibarr lives, if this module sits outside its tree. Takes the `htdocs` folder, the install root, or `master.inc.php` itself |
| `--owner=USER` / `--group=GROUP` | chown/chgrp the writable paths afterwards (POSIX only) |
| `--skip-permissions` | leave ownership and modes alone |
| `--quiet` | errors and the final result only |

Run it as the webserver user, or pass `--owner`. A build run as yourself leaves
files the webserver cannot write, and that fails later, far from the cause.

### Packaging a release

A compiled build is portable. `config_gen.php` bakes the build machine's own
directory into `public/index.php`, so the publish step rewrites that one
`define()` to locate itself instead, and `config/dynamic.php` contains no paths
at all. Nothing else in the build output names the machine that produced it.

Build the release from a bare clone, with no Dolibarr present:

```bash
php scripts/build.php      # then zip the tree
```

Name the archive `cyphtwebmail-x.y.z.zip`, with the module directory at the
root, so Dolibarr's *Deploy external module* screen accepts it. The version has
to be the last thing before `.zip` or the upload is rejected.

**Check what the archive contains before publishing it.** The `.env` inside a
release must hold only the build defaults, roughly a dozen keys that are the
same on every installation. It must never carry `DB_PASS`,
`SSO_SHARED_SECRET` or `USER_CONFIG_SECRET`: those belong to an installation,
not to a build, and the second of them decrypts every stored mailbox password.
An offline build writes only the defaults, but a tree that has also been built
against a live Dolibarr will have the rest sitting in the same file.

`.gitignore` keeps `vendor/` and `public/` out of git, and a zip does not read
`.gitignore`, so the check is worth doing by hand.

### Reactivate after descriptor changes

Menu entries, permissions and tables are written to the database when the
module is *activated*, not on every request. After changing
`core/modules/modcyphtWebmail.class.php`, switch the module **off and on again**
or the changes will not appear.

## Daily use

Open **CyphtWebmail** in the top menu. SSO logs you into Cypht automatically.

First time, add a mailbox: **Servers** inside Cypht → *Add an E-mail Account*.
Cypht supports IMAP, JMAP and EWS for reading, SMTP for sending.

The left column carries the Dolibarr side of the workflow - open tickets,
overdue invoices, agenda, the email collector, email templates, mass emailing
and module setup. It deliberately does not repeat Cypht's own navigation.

## Where data is stored

| What | Where | Notes |
|---|---|---|
| Mail accounts + settings | `llx_cyphtwebmail_userconfig` | one row per user, keyed on `fk_user`, cascades on delete |
| Secrets | `llx_const` | `CYPHTWEBMAIL_SSO_SECRET`, `CYPHTWEBMAIL_CONFIG_SECRET` |
| Menu entries | `llx_menu` | written on module activation |
| Sessions | `documents/cyphtWebmail/sso_sessions/` | transient, garbage collected |
| Attachments in progress | `documents/cyphtWebmail/attachments/` | |
| Built app | `public/` | regenerated by Generate, safe to delete |
| Cypht itself | `vendor/jason-munro/cypht/` | Composer-managed, never edit |

The `config` column is plain JSON - queryable - with only `pass` values
encrypted and marked `enc:v1:`.

```sql
SELECT u.login, c.config
FROM llx_cyphtwebmail_userconfig c
JOIN llx_user u ON u.rowid = c.fk_user;
```

**Mail itself is never stored locally.** It stays on the IMAP server.

## Configuration reference

Set through the setup page, or in **Home → Setup → Other** for the ones without
a form field.

| Constant | Default | Purpose |
|---|---|---|
| `CYPHTWEBMAIL_IMAP_NAME` | `Webmail` | default account label |
| `CYPHTWEBMAIL_IMAP_SERVER` | `localhost` | default IMAP host |
| `CYPHTWEBMAIL_IMAP_PORT` | `993` | default IMAP port |
| `CYPHTWEBMAIL_IMAP_TLS` | `true` | default TLS |
| `CYPHTWEBMAIL_SSO_SECRET` | generated | signs SSO and bridge tokens |
| `CYPHTWEBMAIL_CONFIG_SECRET` | generated | encrypts stored mailbox passwords |
| `CYPHTWEBMAIL_SESSION_TTL` | `604800` | session lifetime, seconds |
| `CYPHTWEBMAIL_SESSION_GC_DIVISOR` | `200` | 1-in-N logins sweep old sessions |
| `CYPHTWEBMAIL_SESSION_DEBUG` | `false` | verbose session log; leave off |
| `CYPHTWEBMAIL_CONTACTS_INCLUDE_USERS` | `true` | list Dolibarr users in the address book, so staff can mail each other |
| `CYPHTWEBMAIL_CONTACTS_TTL` | `300` | contact cache lifetime, seconds |
| `CYPHTWEBMAIL_CONTACTS_MAX` | `2000` | max contacts fetched |
| `CYPHTWEBMAIL_CONTACTS_TIMEOUT` | `5` | bridge HTTP timeout |
| `CYPHTWEBMAIL_CONTACTS_INSECURE` | `false` | skip TLS verification (self-signed certs) |
| `CYPHTWEBMAIL_BRIDGE_URL` | auto | override when Dolibarr cannot reach itself by its public URL |

**No need to edit `vendor/jason-munro/cypht/.env` by hand** - Generate rewrites it.

## Troubleshooting

**Build stops partway with no error.**
Look at `debug.log` and `last_build_log.ndjson` in the module root. The most
common cause is PHP's execution limit; the build sets `set_time_limit(0)` and
`ignore_user_abort(true)`, but a hard server limit still wins.

**"A build is already running" and none is.**
A crashed build left `documents/cyphtWebmail/build.lock` behind. It is ignored
automatically after 420 seconds, or delete it.

**Menu entries or permissions did not change.**
Deactivate and reactivate; see
[Reactivate after descriptor changes](#reactivate-after-descriptor-changes).

**Changes under `cypht/modules/` have no effect.**
Build again. Those files are copied into Cypht at build time.

**Contacts do not appear.**
Open `bridge/contacts.php` directly in a browser - it should answer
`{"error":"Missing login or token"}`. Anything else (404, 500, a login page)
means the endpoint itself is the problem. Remember the 300-second cache.

**Cypht settings vanish, or the mailbox password stops working.**
Check `CYPHTWEBMAIL_CONFIG_SECRET` still exists in `llx_const`. If it changed,
stored passwords cannot be decrypted and are blanked so Cypht re-prompts.

**Sessions pile up.**
Garbage collection is probabilistic. Lower `CYPHTWEBMAIL_SESSION_GC_DIVISOR` to
sweep more often.

If none of these fit, [How it works](CONTRIBUTING.md#how-it-works) walks through
SSO, the iframe and the build pipeline, which is usually enough to place a fault.

## Contributing

Everything about changing this module lives in
[CONTRIBUTING.md](CONTRIBUTING.md): the project layout, how the pieces fit
together, how to add a Cypht module set or a bridge endpoint, the coding
conventions, and the checklist to run before opening a PR.
