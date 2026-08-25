# dolibarr_context

Shows who the sender of an open message is in Dolibarr, and what is still open
against them, on the message's own From row.

## What it does

Opening a message adds a short summary beside the sender's address, separated
from it by a rule:

```
From   Jane Doe <jane@acme.com>   │  Acme Corp ↗  Customer   2 Proposals €12,000   3 Unpaid invoices €4,200
```

The summary does not repeat the sender's name, since the From line already
carries it. It shows the company behind a contact, or — when the sender is the
company — what they are to you. That label links to the record. Each chip is a
count of something still open; clicking one expands the most recent records
beneath, each linking to its own page.

Four states:

| State | Shown |
| --- | --- |
| Sender has records, work outstanding | the record, plus a chip per open block |
| Sender has records, nothing outstanding | the record and *Nothing open* |
| Sender is in no record | an **Add sender as prospect** button |
| Lookup failed | a **Check records** button that retries |

## What counts as open

Each block is scoped in SQL, so the chip label carries the status and
individual rows do not need one:

| Block | Rule |
| --- | --- |
| Proposals | `fk_statut = 1` — validated and still open |
| Orders | `fk_statut IN (1, 2)` — validated or in process |
| Unpaid invoices | `fk_statut = 1 AND paye = 0` |
| Tickets | `fk_statut < 8` — below `Ticket::STATUS_CLOSED` |
| Projects | `fk_statut = 1` — open |

A block whose count is zero is dropped by the bridge and never reaches the
panel. Drafts appear nowhere.

## Who the address resolves to

Contact, then third party, then Dolibarr user, then member — the precedence
`bridge/contacts.php` uses when de-duplicating on address, so an address held
by both a contact and a company resolves to the person. Only the first match is
returned.

An address matching nothing is a normal answer, not an error: the panel offers
to create the sender as a prospect (`Societe::PROSPECT`), with the name
prefilled from the From display name, falling back to the part before the `@`.
The dialog reports the result and links to the new record.

## How it is wired

`Hm_Output_dolibarr_context_shell` runs after `filter_message_headers` and
appends an empty panel, carrying the parsed sender address, to the
`msg_headers` string. `site.js` moves that panel onto the From row and requests
the card from `ajax_dolibarr_context`, which calls `bridge/context.php`.

**The module set must be listed after `imap` in `CYPHT_MODULES`**, because its
output module attaches to `filter_message_headers`.

The card is fetched separately from the message rather than rendered into it,
for two reasons: the message body must never wait on an HTTP round trip to
Dolibarr, and `imap/site.js` writes the message response into browser local
storage, which is no place for a customer's outstanding invoices.

If the header markup changes so the panel cannot be moved onto the From row, it
stays where the output module placed it and renders a caption instead.

## Permissions

The module declares two of its own, grantable per user or group under the
user's Permissions tab:

| Permission | Effect |
| --- | --- |
| `cyphtwebmail->context->read` | the panel appears at all |
| `cyphtwebmail->context->create` | the **Add sender as prospect** button appears |

Both are then paired with Dolibarr's own rights — `societe->lire` to read,
`societe->creer` to create — and both must pass. The module permission decides
*where* a user may see or do something; Dolibarr's right decides *whether* they
may at all. Granting the module permission therefore cannot widen what anybody
sees: the panel only ever shows records its reader could already open in
Dolibarr.

A user without `context->read` gets no panel, not an error message. There is
nothing for them to retry, so reporting a failure would be misleading.

Each block is then gated twice more — on `isModEnabled()` and on the requesting
user's own read right (`propal->lire`, `commande->lire`, `facture->lire`,
`ticket->read`, `projet->lire`) — so an installation without tickets, or a user
without invoice rights, gets fewer blocks rather than an error.

Module keys and permission names differ throughout Dolibarr; the pairs used
here follow `core/ajax/selectsearchbox.php`.

Permissions are written to the database when the module is **activated**, so
after adding or changing one the module must be switched off and on again.

## Security

Requests are signed as `dolibarr_contacts` signs its own: an HMAC over
`login|timestamp|purpose` with the shared SSO secret, valid for 60 seconds. The
purpose tag is `context` for reads and `create` for writes, so a token minted
for one endpoint cannot be replayed against the other.

The write endpoint accepts POST only, enforces the acting user's own rights
rather than the web server's, and is idempotent on the email address: a repeat
returns the existing record instead of creating a second.

The address being asked about is not part of the signature. The token
establishes who is asking; the permission checks decide what they may see.

## Configuration

Written into `.env` by `CyphtEnvironment`, overridable with Dolibarr constants:

| .env key | Dolibarr constant | Default |
| --- | --- | --- |
| `DOLIBARR_CONTEXT_URL` | `CYPHTWEBMAIL_BRIDGE_CONTEXT_URL` | resolved from `dol_buildpath()` |
| `DOLIBARR_CONTEXT_CREATE_URL` | `CYPHTWEBMAIL_BRIDGE_CONTEXT_CREATE_URL` | resolved from `dol_buildpath()` |
| `DOLIBARR_CONTEXT_TTL` | `CYPHTWEBMAIL_CONTEXT_TTL` | `120` |
| `DOLIBARR_CONTEXT_CACHE` | `CYPHTWEBMAIL_CONTEXT_CACHE` | `20` |
| `DOLIBARR_CONTEXT_TIMEOUT` | `CYPHTWEBMAIL_CONTEXT_TIMEOUT` | `5` |
| `DOLIBARR_CONTEXT_INSECURE` | `CYPHTWEBMAIL_CONTEXT_INSECURE` | `false` |

Rows per expanded chip come from `CYPHTWEBMAIL_CONTEXT_ROWS` (default `3`,
clamped to 1–10), read by the bridge.

Cards are cached per address in the Cypht session for `DOLIBARR_CONTEXT_TTL`
seconds, `DOLIBARR_CONTEXT_CACHE` addresses at a time, least recently used
evicted first. The TTL is shorter than the contacts and templates ones because
an invoice being settled while a mailbox is open is the kind of change this
panel exists to reflect. A failed fetch keeps serving the cached card and says
so rather than blanking the panel.

## Files

| File | Role |
| --- | --- |
| `hm-dolibarr-context.php` | `Hm_Dolibarr_Context` reads, `Hm_Dolibarr_Context_Create` writes |
| `modules.php` | handlers for both endpoints, output module for the panel |
| `setup.php` | page hooks, allowed input and output |
| `site.js` | places the panel, fetches the card, draws it, runs the create dialog |
| `site.css` | chip sizing, the From row rule, the scroll cap on an expanded block |

Served by `bridge/context.php` and `bridge/create.php` in the Dolibarr module.
