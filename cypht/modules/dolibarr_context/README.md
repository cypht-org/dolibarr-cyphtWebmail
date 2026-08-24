# dolibarr_context

Puts a Dolibarr card under the headers of an open message: who the sender is,
the third party behind them, and what is still open against that third party.

## What it does

`Hm_Output_dolibarr_context_shell` runs after `filter_message_headers` and
appends an empty panel, carrying only the parsed sender address, to the
`msg_headers` string. `site.js` finds that panel once the message view is
built and asks `ajax_dolibarr_context` for the card, which reaches Dolibarr
through `bridge/context.php`.

The card is a summary line — third party, the person inside it, customer or
supplier — followed by one chip per thing still open. A chip expands to the
few most recent records, each linking to its Dolibarr page.

## Why the card is a second request

Everything else could have been rendered into the message response. Two
reasons it is not:

- The message body would then wait on an HTTP round trip to Dolibarr. A slow
  or unreachable Dolibarr would slow down reading mail, which is the one thing
  this module must never do.
- `imap/site.js` writes the message response into local storage. A cached copy
  of somebody's unpaid invoices has no business being there.

## What counts as open

Each block is scoped in SQL, so the heading is the status and no row needs to
carry one:

| Block | Rule |
| --- | --- |
| Proposals | `fk_statut = 1` — validated and still open |
| Orders | `fk_statut IN (1, 2)` — validated or in process |
| Unpaid invoices | `fk_statut = 1 AND paye = 0` |
| Tickets | `fk_statut < 8` — below `Ticket::STATUS_CLOSED` |
| Projects | `fk_statut = 1` — open |

A block with a count of zero is dropped in the bridge, so an empty block never
reaches the panel.

## Who the address resolves to

Contact, then third party, then Dolibarr user, then member — the same
precedence `bridge/contacts.php` uses when it de-duplicates on address, so a
name that appears both as a contact and as a company generic address resolves
to the person.

An address nobody owns is answered with a body, not a 404: "not in Dolibarr"
is a useful thing for the panel to be able to say, and a panel that appears
only sometimes is harder to read than one that is always there.

## Permissions

`societe->lire` gates the endpoint. Every block is then gated twice more, on
`isModEnabled()` and on the bridge user's own read right, so an install
without tickets, or a user without invoice rights, gets fewer blocks rather
than an error. Module keys and permission names differ throughout Dolibarr;
the pairs used here are copied from `core/ajax/selectsearchbox.php`.

## Configuration

Written into `.env` by `CyphtEnvironment`, overridable with Dolibarr constants:

| .env key | Dolibarr constant | Default |
| --- | --- | --- |
| `DOLIBARR_CONTEXT_URL` | `CYPHTWEBMAIL_BRIDGE_CONTEXT_URL` | resolved from `dol_buildpath()` |
| `DOLIBARR_CONTEXT_TTL` | `CYPHTWEBMAIL_CONTEXT_TTL` | `120` |
| `DOLIBARR_CONTEXT_CACHE` | `CYPHTWEBMAIL_CONTEXT_CACHE` | `20` |
| `DOLIBARR_CONTEXT_TIMEOUT` | `CYPHTWEBMAIL_CONTEXT_TIMEOUT` | `5` |
| `DOLIBARR_CONTEXT_INSECURE` | `CYPHTWEBMAIL_CONTEXT_INSECURE` | `false` |

Rows shown per block come from `CYPHTWEBMAIL_CONTEXT_ROWS` (default `3`,
clamped to 1–10) and are read in the bridge, not here.

The TTL is much shorter than the contacts or templates ones: an unpaid invoice
being settled while a mailbox is open is exactly the kind of change this panel
exists to reflect. Cards are cached per address, oldest evicted first, so
reading down a long folder cannot grow the session file without bound. A
failed fetch keeps serving the cached card and says so, rather than blanking a
panel the user is looking at.

## Security

Requests are signed exactly as `dolibarr_contacts` signs its own, with a
purpose tag of `context`, so a token minted for one endpoint cannot be
replayed against another. Tokens are valid for 60 seconds.

The address itself is not part of the signature, as `search` is not on the
contacts endpoint: the token proves who is asking, and the permission checks
decide what they may see about whoever they ask about.

## Files

| File | Role |
| --- | --- |
| `hm-dolibarr-context.php` | `Hm_Dolibarr_Context`, the signed HTTP client |
| `modules.php` | handler that fetches and caches, output that appends the shell |
| `setup.php` | page hooks and allowed input/output |
| `site.js` | finds the shell, fetches the card, draws it |
| `site.css` | chip sizing and the scroll cap on an expanded block |
