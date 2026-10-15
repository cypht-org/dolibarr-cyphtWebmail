# CyphtWebmail

A Dolibarr module that embeds [Cypht](https://cypht.org), a lightweight open
source webmail client, inside Dolibarr - with single sign-on, Dolibarr contacts
as an address book, and mail accounts stored in Dolibarr's own database.

![Writing to an invoice contact from the webmail, with the HTML editor and an email template](docs/screenshot.png)

- Cypht upstream: <https://github.com/cypht-org/cypht>
- Cypht docs: <https://cypht.org>
- Dolibarr: <https://www.dolibarr.org>

Cypht is LGPL-2.1, Dolibarr is GPL-3.0. This module is LGPL-2.1.

## Table of contents

- [What it does](#what-it-does)
- [Requirements](#requirements)
- [Installation](#installation)
- [Setup and building](#setup-and-building)
- [Daily use](#daily-use)
- [Where data is stored](#where-data-is-stored)
- [Configuration reference](#configuration-reference)
- [Troubleshooting](#troubleshooting)
- [Security](#security)
- [Contributing](#contributing)
- [License](#license)

## What it does

| Feature | Notes |
|---|---|
| Embedded webmail | The built Cypht application is embedded inside the Dolibarr interface, with Dolibarr's menu and header |
| Single sign-on | Dolibarr users reach their mail without a second login |
| Dolibarr contacts in Cypht | Third parties and contacts appear as a read-only address book, including compose autocomplete |
| Accounts in the database | Mail accounts and settings live in `llx_cyphtwebmail_userconfig`, not in files |
| Encrypted credentials | Mailbox passwords are encrypted at rest; the rest of the config stays readable SQL |
| Deep-linkable | The browser URL follows the current Cypht page, so reload and bookmarking work |
| Lifecycle aware | Deleting or renaming a Dolibarr user cleans up or follows their webmail data |
| Home page box | A Webmail box on the Dolibarr home page shows unread messages, unread messages from your contacts and flagged messages. The counts are updated while the webmail is open |
| Sender context | An open message shows the sender's third party and their open proposals, orders, invoices, tickets and projects |
| New message button | Third party, contact, proposal, order, invoice, supplier order and supplier invoice cards get a button that opens a new message to the contact, with the document reference as subject |
| HTML editor | Messages are written in HTML by default, and the setup page can leave the format to each user |
| Email templates | Dolibarr's email templates can be picked on the compose page |
| Language and theme | The webmail follows the user's Dolibarr language and light or dark mode |

## Requirements

To run it:

- Dolibarr 19+ (developed against **24.0**)
- PHP 8.1+ with `curl`, `openssl`, `mbstring`, `dom`, `PDO`
- MySQL/MariaDB or PostgreSQL (whatever Dolibarr already uses)

To build:
- PHP CLI binary
- Composer (or `composer.phar` in module root)
- `proc_open()` and `exec()` enabled

## Installation

### From a release ZIP

1. Download `module_cyphtwebmail-x.y.z.zip` from releases
2. Extract into Dolibarr's custom directory (set by `$dolibarr_main_document_root_alt`):
   ```bash
   unzip module_cyphtwebmail-x.y.z.zip -d <dolibarr>/htdocs/custom/
   ```
3. The ZIP contains pre-built `vendor/` and `public/`, so no build step is needed
4. Enable: **Home → Setup → Modules/Applications → Interfaces** → **CyphtWebmail** → switch on

### From git (source)

1. Clone into Dolibarr's custom directory:
   ```bash
   git clone <repository-url> <dolibarr>/htdocs/custom/cyphtwebmail
   ```
2. Build the module (see next section)
3. Enable: **Home → Setup → Modules/Applications → Interfaces** → **CyphtWebmail** → switch on



## Setup and Building

### Installing from git

Source requires compilation. Two workflows:

#### Build for testing locally

```bash
cd <module directory>
php scripts/build.php
```

**Or via web UI:** **CyphtWebmail → Module setup**, press **Generate**.

Build steps:
1. `composer install` - fetches Cypht
2. `config_gen.php` - compiles module sets
3. publish - copies to `public/` with paths rewritten for relocatability

Rebuild after: module changes, setup page changes, `cypht/modules/` edits, or `composer update`.

Options: `--dolibarr=PATH`, `--owner=USER`, `--group=GROUP`, `--skip-permissions`, `--quiet`.

#### Packaging for release

`php build/buildzip.php` packages the last commit into a ZIP for distribution.
It runs the full build, then checks the archive:

```bash
php build/buildzip.php --out=dist
```

This creates `dist/module_cyphtwebmail-x.y.z.zip`. The archive is deleted if
its `.env` holds anything but placeholders for `DB_NAME`, `DB_USER` and
`DB_PASS`, or if build-only files or `.git` are left in it.

#### Releasing

1. Set the new version in `cyphtwebmail_version.inc.php` and merge it into `master`.
2. Tag that commit with the same number and push the tag:
   ```bash
   git tag x.y.z
   git push origin x.y.z
   ```

GitHub Actions then builds the ZIP and attaches it to a release for the tag.
The job stops if the tag and the version differ.



### Configuration

**CyphtWebmail → Module setup** shows build status and the Generate button
(dev mode only). Mailboxes are added by each user inside the webmail, under
its own accounts page.

### Reactivate after descriptor changes

Menu entries, permissions and tables are written to the database when the
module is *activated*, not on every request. After changing
`core/modules/modcyphtWebmail.class.php`, switch the module **off and on again**
or the changes will not appear.

## Daily use

Open **CyphtWebmail** in the top menu. SSO logs in automatically.

First time: **Servers** → *Add an E-mail Account*

## Where data is stored

| What | Where | Notes |
|---|---|---|
| Mail accounts + settings | `llx_cyphtwebmail_userconfig` | one row per user, keyed on `fk_user`, cascades on delete |
| Home page counts | `llx_cyphtwebmail_mailstatus` | one row per user, counts only, no subjects or senders |
| Secrets | `llx_const` | `CYPHTWEBMAIL_SSO_SECRET`, `CYPHTWEBMAIL_CONFIG_SECRET` |
| Menu entries | `llx_menu` | written on module activation |
| Sessions | `documents/cyphtwebmail/sso_sessions/` | transient, garbage collected |
| Attachments in progress | `documents/cyphtwebmail/attachments/` | |
| Built app | `public/` | regenerated by Generate, safe to delete |
| Cypht itself | `vendor/jason-munro/cypht/` | Composer-managed, never edit |

The `config` column is plain JSON - queryable - with only `pass` values
encrypted and marked `enc:v1:`.

```sql
SELECT u.login, c.config
FROM llx_cyphtwebmail_userconfig c
JOIN llx_user u ON u.rowid = c.fk_user;
```

(Exact syntax may vary depending on whether your database is MySQL/MariaDB or PostgreSQL.)

**Mail itself is never stored locally.** It stays on the IMAP server.

## Configuration reference

Most of these are on the module setup page. The others are set in
**Home → Setup → Other**.

| Constant | Default | Purpose |
|---|---|---|
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
| `CYPHTWEBMAIL_DASHBOARD` | `true` | show the Webmail box on the home page |
| `CYPHTWEBMAIL_DELAY_UNREAD` | `2` | days after which unread messages from contacts count as late, `0` for never |
| `CYPHTWEBMAIL_CONTEXT_BLOCKS` | `propal,order,invoice,ticket,project` | record types shown beside the sender, `none` for none |
| `CYPHTWEBMAIL_CONTEXT_INVOICES` | `unpaid` | `unpaid`, or `open` for all validated invoices |
| `CYPHTWEBMAIL_CONTEXT_ROWS` | `3` | records listed per type beside the sender |
| `CYPHTWEBMAIL_LANG_MODE` | `follow` | `follow` the Dolibarr language, or `user` to let each user choose |
| `CYPHTWEBMAIL_THEME_MODE` | `follow` | `follow` Dolibarr's light or dark mode, or `user` to let each user choose |
| `CYPHTWEBMAIL_COMPOSE_MODE` | `html` | `html` for the HTML editor, or `user` to let each user choose |
| `CYPHTWEBMAIL_ENABLE_BUILD` | `0` | show the Generate button when Dolibarr runs in production mode |


## Troubleshooting

**Build stops partway with no error.**
Check `debug.log` and `last_build_log.ndjson`. Usually PHP execution limit is the culprit.

**"A build is already running" and none is.**
Delete `documents/cyphtwebmail/build.lock` (auto-ignored after 420s).

**Menu entries or permissions did not change.**
Deactivate and reactivate the module.

**The home page box or the New message button is missing.**
Deactivate and reactivate the module. Dolibarr registers the module's hooks on
activation.

**Changes under `cypht/modules/` have no effect.**
Build again. Those files are copied into Cypht at build time.

**Sending fails with "550 invalid header format".**
Build again. The build fixes a Cypht bug in HTML messages that strict SMTP
servers reject.

**Cypht settings vanish or password stops working.**
Check if `CYPHTWEBMAIL_CONFIG_SECRET` changed in `llx_const`. If so, passwords need re-entry.

**Sessions pile up.**
Garbage collection is probabilistic. Lower `CYPHTWEBMAIL_SESSION_GC_DIVISOR` to
sweep more often.

If none of these fit, [How it works](CONTRIBUTING.md#how-it-works) walks through
SSO, the iframe and the build pipeline, which is usually enough to place a fault.

## Security

**Encryption keys**
- `CYPHTWEBMAIL_CONFIG_SECRET` encrypts stored mailbox passwords. Never change it
  casually; doing so renders all stored passwords unreadable.
- `CYPHTWEBMAIL_SSO_SECRET` signs authentication and bridge tokens. Changing it
  invalidates active sessions.

**Production deployments**
- Never expose `CYPHTWEBMAIL_SESSION_DEBUG` or database credentials in logs.
- Release ZIPs contain only build-time defaults (no secrets); production config
  must be added during installation.

## Contributing

Everything about changing this module lives in
[CONTRIBUTING.md](CONTRIBUTING.md): the project layout, how the pieces fit
together, how to add a Cypht module set or a bridge endpoint, the coding
conventions, and the checklist to run before opening a PR.

## License

This module is free software under the GNU Lesser General Public License,
version 2.1 or, at your option, any later version, the same license as Cypht.
The full text is in [LICENSE](LICENSE).

The module descriptor, `core/modules/modcyphtWebmail.class.php`, is based on
Dolibarr's module template and stays under the GNU General Public License,
version 3 or later.
