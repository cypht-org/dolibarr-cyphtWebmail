# Distribution and upgrades

How this module is built, shipped, installed and upgraded, and the work still
outstanding to make that whole path reliable.

Written as a plan: each step says what to change, why, and how to tell it
worked.

## Table of contents

- [Background](#background)
- [Constraints that shape everything](#constraints-that-shape-everything)
- [Step 1. Reconcile the two working copies](#step-1-reconcile-the-two-working-copies)
- [Step 2. Bring the lowercase casing fix onto this branch](#step-2-bring-the-lowercase-casing-fix-onto-this-branch)
- [Step 3. Gate the build button behind dev mode](#step-3-gate-the-build-button-behind-dev-mode)
- [Step 4. Reframe Generate as maintenance](#step-4-reframe-generate-as-maintenance)
- [Step 5. Schema version and the upgrade check](#step-5-schema-version-and-the-upgrade-check)
- [Step 6. Migration runner](#step-6-migration-runner)
- [Step 7. Self-healing upgrades](#step-7-self-healing-upgrades)
- [Step 8. build.json](#step-8-buildjson)
- [Step 9. Surface and verify versions](#step-9-surface-and-verify-versions)
- [Step 10. Record the Cypht version per user config](#step-10-record-the-cypht-version-per-user-config)
- [Step 11. Guard USER_CONFIG_SECRET](#step-11-guard-user_config_secret)
- [Step 12. Make publishSite atomic](#step-12-make-publishsite-atomic)
- [Step 13. Test install and upgrade](#step-13-test-install-and-upgrade)
- [Dependencies](#dependencies)

## Background

The module embeds Cypht, which ships as source and has to be compiled. That
compile used to happen on the target machine, which meant every user needed
Composer, a PHP CLI, `proc_open()` and write access inside the web root.
Issue #4 made the case that this is not a reasonable thing to ask, and it is
not: a Dolibarr module should unzip and work.

The module is therefore moving to prebuilt releases. The compile happens once,
by the maintainer, and the archive carries a finished `public/` and `vendor/`.

That change has a consequence which drives most of what follows. If nobody
builds on the target machine, then nothing on the target machine writes
configuration either, and every value that used to be produced by a build has
to come from somewhere else.

## Constraints that shape everything

**Dolibarr deletes the module directory on upgrade.** `admin/modules.php`
calls `dol_delete_dir_recursive()` on the target before unpacking. Anything
that matters and lives inside the module folder is destroyed by an upgrade.
The rule that follows: no installation state inside the module directory,
ever.

**There is no upgrade hook.** `DolibarrModules` exposes `init()` and
`remove()` and nothing else. Replacing files leaves the module enabled and
runs the new code against the old schema, with no callback of any kind. Any
upgrade logic has to trigger itself.

**Deployed files are read-only.** The deploy copies at mode `0444`, so an
installed module cannot write to its own directory even if it wanted to.

**llx_const encrypts some values silently.** `dolibarr_set_const()` encrypts
the value of any constant whose name ends in `_KEY`, `_PASS`, `_SECRET` and a
few others, and decrypts it again when building `$conf->global`. Dolibarr's
own code never notices. Code reading the table directly gets ciphertext. This
already cost us a full debugging session: the bridges failed with
`Bad signature` because the runtime read the raw column while the bridge read
the decrypted one. The module's own secrets now live in
`llx_cyphtwebmail_config` for this reason.

**Where installation state lives now**, all of it outside the module folder
and therefore surviving upgrades:

| State | Location |
| --- | --- |
| Generated secrets, site id | `llx_cyphtwebmail_config` |
| Mail accounts and per-user settings | `llx_cyphtwebmail_userconfig` |
| Admin settings from the setup page | `llx_const` |
| Sessions, attachments | `DOL_DATA_ROOT/cyphtwebmail/` |

## Step 1. Reconcile the two working copies

**Why.** There are two checkouts of the same branch. `custom/cyphtwebmail`
has the config table, the runtime bootstrap and the bridge changes.
`dolibarr-cyphtWebmail` has the offline build fixes. Neither is complete, and
continuing to edit both means fixing everything twice, which has already
happened once.

**Do.** Pick one as canonical. `dolibarr-cyphtWebmail` is the better choice:
it is outside `htdocs/custom`, so a zip deploy cannot delete it. Copy the
missing work across, commit from there, and keep `custom/cyphtwebmail` as a
deploy target only.

**Verify.** `git status` clean in the canonical copy, and a diff of the two
trees shows only build output.

## Step 2. Bring the lowercase casing fix onto this branch

**Why.** `fix/module-name-error` carries 21 commits that never reached
`fix/build-absolute-path`. On this branch `init()` still calls
`_load_tables('/cyphtWebmail/sql/')` and `paths.class.php` still uses
`DOL_DATA_ROOT.'/cyphtWebmail'`. Neither resolves on a case sensitive
filesystem. Activation now creates tables and mints secrets, so a silent
failure there is far more damaging than it was when activation did almost
nothing.

**Do.** Merge or rebase `fix/module-name-error` in. Re-run the exact-case
audit afterwards: every `dol_buildpath()` literal and every `@module`
reference resolving against a git-derived file inventory.

**Verify.** The audit reports zero problems with a non-zero check count. A
count of zero means the audit found nothing to check and proves nothing.

## Step 3. Gate the build button behind dev mode

**Why.** With prebuilt releases, an ordinary installation should never
compile. The button needs Composer, a PHP binary, `proc_open()` and write
access, which is exactly the set shared hosting denies, and pressing it there
produces a confusing failure on a working install.

**Do.** Two independent gates. Should we offer it: `$dolibarr_main_prod` empty,
or a `CYPHTWEBMAIL_ENABLE_BUILD` constant for someone debugging on a
production box. Can it work here: the existing `$canBuildHere` from
`checkBuildRequirements()`, which already tests writability and hides the
button after a read-only deploy.

**Verify.** With `$dolibarr_main_prod='1'` the controls are absent. With `'0'`
and a writable tree they appear.

## Step 4. Reframe Generate as maintenance

**Why.** Presenting Generate as step 3 of installation tells users on shared
hosting that they must do something they cannot do.

**Do.** Move the controls under a Maintenance heading on the setup page.
Remove the build step from the README install sequence and describe it as a
developer action, alongside the note that editing `cypht/modules/` requires a
rebuild.

**Verify.** Read the README as a first-time user. The install path should be
unzip, enable, done.

## Step 5. Schema version and the upgrade check

**Why.** This is the keystone. Without it none of steps 6 and 7 ever run,
because nothing calls them: file replacement triggers no hook.

**Do.** Store `SCHEMA_VERSION` in `llx_cyphtwebmail_config`. Compare it
against a constant in the code on two cheap paths: the setup page, and the
webmail entry point. When they differ, run the pending work and write the new
value back. It is a single indexed read per request, and it is self-healing
regardless of how the files arrived, whether by zip, git pull or rsync.

**Verify.** Bump the code constant by hand, load the webmail, confirm the row
updates and the work ran exactly once.

## Step 6. Migration runner

**Why.** Fresh installs get their schema from `sql/*.sql` via `_load_tables()`.
Existing installs need the deltas, and Dolibarr offers nothing for this.

**Do.** `sql/migrations/NNN_description.sql`, applied in numeric order by the
step 5 check, each one idempotent and forward-only. `sql/*.sql` remains the
fresh-install path and must stay in step with the end state of the
migrations.

**Verify.** Install an old version, upgrade, and compare the resulting schema
against a fresh install of the new version. They should be identical.

## Step 7. Self-healing upgrades

**Why.** "Disable and re-enable after upgrading" is not a plan. Nobody reads
release notes, and the failure is silent.

**Do.** The step 5 check also mints any missing secrets and creates missing
data directories, using the same get-or-create helpers activation uses. An
upgrade that only replaced files then recovers on the first request.

**Verify.** Delete a data directory, load the webmail, confirm it is recreated
and nothing else changed. Confirm secrets are **not** regenerated when already
present.

## Step 8. build.json

**Why.** `CYPHTWEBMAIL_BUILT_VERSION` is written only by a Dolibarr build, so
it is empty for every prebuilt zip. Nothing currently records which Cypht
version a shipped build contains.

**Do.** The offline build writes `build.json` at the module root: module
version, the Cypht version read from `vendor/composer/installed.json`, and a
build timestamp. It belongs inside the module directory precisely because it
describes the build rather than the installation, so losing it on upgrade is
correct.

**Verify.** Run the offline build, confirm `build.json` matches the installed
Cypht version.

## Step 9. Surface and verify versions

**Why.** Someone will run `composer update` underneath a shipped build and
end up with a compiled `public/` that does not match `vendor/`. The symptoms
of that are obscure.

**Do.** Read `build.json` on the setup page and show "module x.y.z, built
against Cypht a.b.c". Compare against the actual installed version and warn on
mismatch.

**Verify.** Change the version in `build.json` by hand and confirm the warning
appears.

## Step 10. Record the Cypht version per user config

**Why.** A Cypht major version that changes the shape of the stored user
config leaves no way to tell which rows are in the old shape. That turns a
writable migration into guesswork.

**Do.** Add a `cypht_version` column to `llx_cyphtwebmail_userconfig`, written
on every save. Cheap now, and the only thing that makes a future conversion
possible.

**Verify.** Save a setting in the webmail and confirm the column is populated.

## Step 11. Guard USER_CONFIG_SECRET

**Why.** Every stored mailbox password is encrypted with it. Regenerating it
silently empties every account in the installation, and the failure looks like
data loss rather than a configuration change.

**Do.** `getOrCreateSecret()` already returns the existing value rather than
rolling a new one, which is the important half. Add an explicit comment at the
call site saying why, and keep any regenerate action out of the UI. If one is
ever needed, it needs a confirmation that states the consequence plainly.

**Verify.** Re-activate the module twice and confirm the secret is unchanged.

## Step 12. Make publishSite atomic

**Why.** It deletes `public/` and then copies into it. A copy that fails
partway, on a full disk or an odd permission, leaves a previously working
install with no `public/` at all, from a button someone pressed on a healthy
system.

**Do.** Copy to `public.new/`, then swap. Keep the old directory until the
swap succeeds.

**Verify.** Simulate a failed copy and confirm the existing `public/` survives.

## Step 13. Test install and upgrade

**Why.** None of the runtime work in this plan has been executed. It is
reasoned from reading the code, not run.

**Do.** Two passes.

Fresh install: build offline, zip as `cyphtwebmail-x.y.z.zip` with the module
directory at the archive root, deploy from Home, Setup, Modules, enable, then
confirm the tables exist, the three secrets are present, the webmail loads,
and contacts and templates both populate. The webmail loading at all proves
the bootstrap found `conf.php` and read the database, since none of that came
from a file.

Upgrade: deploy a newer zip over the top **without** disabling the module.
Confirm the schema check runs, secrets are unchanged, mail accounts still
open, and admin settings survive.

Note that deploying into `htdocs/custom` deletes the target directory first,
so make sure no working copy lives there.

## Dependencies

- Step 1 before everything, or the same fix gets written twice again.
- Step 2 early, because activation now does real work and fails silently on
  Linux without it.
- Step 5 before steps 6 and 7, which are triggered by it.
- Step 8 before step 9, which reads what it writes.
- Step 13 last, and it is the only step that turns any of this from reasoned
  into verified.
