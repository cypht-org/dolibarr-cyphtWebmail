# dolibarr_mail_templates

Puts Dolibarr's general purpose email templates on the Cypht compose screen.

## What it does

On every compose page load the module asks `bridge/mail_templates.php` for the
templates this user is allowed to see, and renders them as a picker above the
To/Subject/Message block. Choosing one fills the subject (only when it is still
empty) and inserts the body at the caret, so a template can be dropped in above
an existing signature without destroying it.

## Which templates appear

Only those with `type_template = 'all'`. Every other type in
`llx_c_email_templates` is transactional: its body is written around an object
and carries placeholders such as `__TICKET_URL__` or `__MEMBER_FULLNAME__` that
only `make_substitutions()` with that object can fill. Compose has no object,
so those templates would paste raw markers into the user's email. The filter
lives in the bridge, not here.

**Dolibarr core seeds no templates of type `all`.** A fresh install therefore
shows an empty, disabled picker carrying the hint from the bridge. This is the
expected state, not a fault. Create one under Home, Setup, Emails, Email
templates, with type "All".

## Known limitation

Template bodies are inserted verbatim. A template authored as HTML lands as
HTML source in the plain text compose box. Templates intended for webmail use
should be written as plain text until the compose screen grows an HTML mode.

## Configuration

Written into `.env` by `CyphtEnvironment`, overridable with Dolibarr constants:

| .env key | Dolibarr constant | Default |
| --- | --- | --- |
| `DOLIBARR_MAIL_TEMPLATES_URL` | `CYPHTWEBMAIL_BRIDGE_MAIL_TEMPLATES_URL` | resolved from `dol_buildpath()` |
| `DOLIBARR_MAIL_TEMPLATES_TTL` | `CYPHTWEBMAIL_MAIL_TEMPLATES_TTL` | `900` |
| `DOLIBARR_MAIL_TEMPLATES_TIMEOUT` | `CYPHTWEBMAIL_MAIL_TEMPLATES_TIMEOUT` | `5` |
| `DOLIBARR_MAIL_TEMPLATES_INSECURE` | `CYPHTWEBMAIL_MAIL_TEMPLATES_INSECURE` | `false` |

The list is cached in the Cypht session for `DOLIBARR_MAIL_TEMPLATES_TTL` seconds. A
failed fetch keeps serving the cached copy rather than blanking the picker; the
reason goes to the Cypht debug log.

## Security

Requests are signed exactly as `dolibarr_contacts` signs its own, with a
purpose tag of `templates` instead of `contacts`, so a token minted for one
endpoint cannot be replayed against the other. Tokens are valid for 60 seconds.
Visibility follows `FormMail::getEMailTemplate()`: entity scoping plus
`private = 0 OR fk_user = me`, enforced in the bridge's SQL.

## Files

| File | Role |
| --- | --- |
| `hm-dolibarr-mail-templates.php` | `Hm_Dolibarr_Mail_Templates`, the signed HTTP client |
| `modules.php` | handler that loads and caches, output that renders the picker |
| `setup.php` | page hooks and allowed output |
