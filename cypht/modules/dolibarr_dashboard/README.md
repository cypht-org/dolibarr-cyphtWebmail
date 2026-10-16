# dolibarr_dashboard

Saves the user's mail counts for the Webmail box on the Dolibarr home page.

## What it does

While the webmail is open, `site.js` calls `ajax_dolibarr_mail_status` 5
seconds after the page loads, then every 2 minutes. The handler counts, over
all the user's IMAP accounts:

| Count | How |
| --- | --- |
| Unread | `STATUS INBOX` unseen |
| Flagged | `SEARCH FLAGGED` in INBOX |
| From your contacts | `SEARCH UNSEEN SINCE` 30 days ago in INBOX, the last 50 headers matched against the contact cache |

Contacts are the user's Dolibarr contacts, third parties and members from the
`dolibarr_contacts` cache. Dolibarr users are left out, mail from colleagues
isn't customer mail.

The result goes to `llx_cyphtwebmail_mailstatus`, one row per user: the two
counts, the received dates of the unread contact messages as JSON, and the
check time in UTC. Only counts and dates are stored, never subjects or
senders.

A request less than 60 seconds after the last check returns without touching
IMAP, so several open tabs don't multiply the work.

## The Dolibarr side

`CyphtMailStatus::load_board()` in `class/integration/mailstatus.class.php`
reads the row for Dolibarr's dashboard, through the hooks in
`class/actions_cyphtwebmail.class.php`. It works out the late contact
messages from the stored dates and `CYPHTWEBMAIL_DELAY_UNREAD`, so changing
the delay needs no new check. A row older than 24 hours is ignored, and the
box is hidden.

## Database access

The handler writes with `Hm_DB`, using the Dolibarr database settings that
`CyphtEnvBootstrap` publishes at runtime. The table prefix comes from
`DOLIBARR_DB_PREFIX`.

## Files

| File | Role |
| --- | --- |
| `setup.php` | the `ajax_dolibarr_mail_status` page and its handler chain |
| `modules.php` | `Hm_Handler_dolibarr_mail_status` counts, `Hm_Dolibarr_Mail_Status` reads and writes the row |
| `site.js` | the refresh timer |

Listed after `imap` in `CYPHT_MODULES`, since the handler uses its mailbox
list.
