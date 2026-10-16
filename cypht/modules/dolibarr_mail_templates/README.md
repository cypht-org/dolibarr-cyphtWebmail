# dolibarr_mail_templates

Puts Dolibarr's email templates on the Cypht compose screen.

## What it does

The compose page gets a **Use a template** button. It opens a dialog listing
the templates grouped by type, with a search field and a preview. **Insert**
fills the subject when it is still empty and puts the body at the caret, so a
template can go above an existing signature. **Undo** puts back what was
there before.

The templates come from the cache file `index.php` writes before the iframe
loads (`CyphtMailTemplateCache`), and from `bridge/mail_templates.php` when
the cache is missing.

## Which templates appear

Every active template in `llx_c_email_templates` the user can see: entity
scoping plus `private = 0 OR fk_user = me`, like
`FormMail::getEMailTemplate()`.

The common substitution keys (company, user, dates) are filled with
`getCommonSubstitutionArray()`. Keys that need an object, like
`__TICKET_URL__` or `__MEMBER_FULLNAME__`, can't be filled from compose: they
stay in the text, the template is flagged in the list, and the preview lists
them under "Contains placeholders to fill in by hand".

## HTML and plain text

`site.js` follows the compose format (`data-compose-type` on the form):

- HTML editor: HTML templates go in as HTML, plain text ones are escaped with
  their line breaks turned into `<br>`.
- Plain text: HTML templates are converted to text, keeping the line breaks.

A body counts as HTML when it has real HTML tags, the same test as Dolibarr's
`dol_textishtml()`.

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
| `hm-dolibarr-mail-templates.php` | `Hm_Dolibarr_Mail_Templates`, reads the cache or calls the bridge |
| `modules.php` | handler that loads and caches, output that renders the button and dialog |
| `setup.php` | page hooks and allowed output |
| `site.js` | the dialog, the preview and inserting into either editor |
| `site.css` | dialog layout |
