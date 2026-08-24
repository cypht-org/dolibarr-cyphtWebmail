# Integration roadmap

Candidate Dolibarr features to link into the webmail, ranked. Written against
the pattern already established by `dolibarr_contacts` and
`dolibarr_mail_templates`.

## The pattern being extended

Every integration so far is the same four pieces:

| Piece | Where | Role |
|---|---|---|
| Bridge endpoint | `bridge/<name>.php` | `NOLOGIN` JSON endpoint, HMAC token with a purpose tag, 60s replay window, permission check against the resolved `User` |
| Integration class | `class/integration/<name>source.class.php` | `resolveBridgeUrl()`, overridable by a `CYPHTWEBMAIL_BRIDGE_*` constant |
| Env keys | `buildEnvOverrides()` in `class/install/environment.class.php` | URL, TTL, timeout, insecure |
| Cypht module set | `cypht/modules/<name>/` | `hm-<name>.php` signed client, `modules.php` handler + output, `setup.php` hooks and filters |

Two properties of that pattern shape everything below:

- **Read-only and GET-only.** Nothing has written back to Dolibarr yet. The
  first write endpoint is an architectural step, not just another copy of
  `contacts.php` — see the note under proposal 1.
- **Session-cached with a TTL, stale-on-failure.** A failed fetch keeps serving
  the last good copy. Any new source should keep that behaviour.

---

## 1. Log an email to the Dolibarr agenda (`llx_actioncomm`)

**The single biggest win.** Turns the webmail from an address-book consumer into
part of the CRM record. A "Log to Dolibarr" control in the message view files
the email as an agenda event, linked to the third party, contact, project,
ticket or invoice it belongs to.

Dolibarr already models exactly this. `ActionComm` carries `email_msgid`,
`email_from`, `email_to`, `email_tocc`, `email_tobcc`, `email_subject` and
`email_reply_to` (`comm/action/class/actioncomm.class.php:329-363`), and
`EmailCollector` populates them when it ingests IMAP mail
(`emailcollector/class/emailcollector.class.php:3161-3224`). We are reusing a
shape core already understands, not inventing one.

- **Dolibarr side:** `ActionComm::create()`, `type_code = 'AC_EMAIL_IN'` for
  received and `'AC_EMAIL'` for sent (`llx_c_actioncomm` ids 6 and 4);
  `elementtype`/`elementid`/`fk_element` for the object link;
  `fk_soc`/`fk_contact`/`fk_project` for the party link.
- **Permission:** `agenda->myactions->create`, plus read on the target element.
- **Cypht side:** a button in the message view via
  `add_output('ajax_imap_message_content', ..., 'filter_message_headers_end', 'after')`,
  and an ajax page that POSTs the message id, headers and body to the bridge.
- **Dedupe:** query `email_msgid` before insert; logging the same message twice
  should update, not duplicate. This is what makes the endpoint safe to retry.
- **Target picking:** default to the third party matched from the sender address
  — the contacts bridge already returns `dol_type` and `dol_id` in `all_fields`,
  so the mapping exists. Offer a search box for anything else.

**Note on the first write bridge.** `NOCSRFCHECK` is fine for a signed
machine-to-machine GET; for a write it means the HMAC *is* the whole access
control. Keep the same purpose tag scheme (`…|logmail`), keep the 60s window,
require POST, and make the operation idempotent on `email_msgid` so a replay
inside the window cannot create a second event. Worth putting this endpoint's
rules in `CONTRIBUTING.md` alongside the read pattern.

**Effort:** medium. **Risk:** low, once idempotency is in.

---

## 2. Sender context panel in the message view

Read-only, and mostly a matter of one more query set. When a message is open,
show who the sender is in Dolibarr: third party card, open proposals and
orders, unpaid invoices, last activity, open tickets — each a `target="_top"`
link into the record.

This is the highest value-per-line item on the list, because it needs no new
UI concepts and no writes, and it answers the question a user actually has
while reading a customer email.

- **Dolibarr side:** one bridge endpoint taking an email address, resolving it
  through `llx_socpeople` / `llx_societe` (same queries as `contacts.php`), then
  summary counts from `llx_propal`, `llx_commande`, `llx_facture`,
  `llx_ticket`, `llx_actioncomm`.
- **Permission:** `societe->lire` for the card, then gate each block on its own
  module and right (`propal->lire`, `commande->lire`, `facture->lire`,
  `ticket->read`) and on `isModEnabled()`. Same shape as the `member` block
  already does in `contacts.php:315`.
- **Cypht side:** `filter_message_headers_end` output, collapsed by default,
  fetched by ajax so it never delays the message body.
- **Cache:** short TTL keyed on the address, not on the user.

**Effort:** low-to-medium. **Risk:** low.

---

## 3. Attach a Dolibarr document to an outgoing email

"Send the invoice PDF" is the most common reason someone leaves the webmail to
go do something in Dolibarr. A picker on compose — browse or search the objects
the user may read, pick a generated document — closes that loop.

- **Dolibarr side:** two endpoints, or one with a mode: a *list* returning
  `llx_ecm_files` rows (`ecm/class/ecmfiles.class.php`) for a chosen object or
  ECM directory, and a *fetch* returning one file's bytes. Never accept a path
  from the caller — accept an `ecm_files` rowid and resolve the path server-side,
  or the endpoint becomes an arbitrary file read.
- **Permission:** read right on the owning element, checked per file.
- **Cypht side:** the attachment machinery is already there. Uploads land in
  `attachment_dir/md5(username)/` and are carried into the draft through the
  `uploaded_files` session list (`smtp/modules.php:281-330, 731-740`). A handler
  that writes the fetched bytes into that directory and appends to
  `uploaded_files` reuses the whole existing send path — no changes to
  `prepare_draft_mime()`.
- **Guard:** cap the file size, and prefer streaming the fetch to disk over
  holding it in memory.

**Effort:** medium-high (two endpoints plus a picker UI). **Risk:** medium —
this is the one where a sloppy path parameter turns into a file disclosure.

---

## 4. Transactional templates with object substitution

This removes the limitation already written down in
`cypht/modules/dolibarr_mail_templates/README.md`: only `type_template = 'all'`
is offered, because every other type carries placeholders like
`__TICKET_URL__` that only `make_substitutions()` with a real object can fill.

The fix is to let the user pick the object. Choose a template of type
`facture`, then pick an invoice, and the bridge renders subject and body
server-side with `FormMail`/`make_substitutions()` before returning them.

- **Dolibarr side:** extend `bridge/mail_templates.php` with a render mode:
  `template_id` + `object_type` + `object_id` → substituted subject and body.
  Reuse `FormMail::getEMailTemplate()` visibility rules already implemented
  there, and add the read-right check for the object type.
- **Cypht side:** the picker already fetches bodies on demand rather than
  embedding them (a deliberate choice recorded in `setup.php:26-29`), so the
  render call slots into the existing ajax page — it gains parameters, not a
  new page.
- **Pairs naturally with 3:** having chosen the invoice for the template, the
  obvious next click is attaching its PDF.

**Effort:** medium. **Risk:** low. Mostly a bridge change; the Cypht side barely
moves.

---

## 5. Create or reply to a ticket from an email

The ticket module is where inbound mail most often needs to become work. Core
already ingests email into tickets through `EmailCollector`, and `Ticket`
carries `origin_email` and `email_msgid` with a `fetch()` that can look a ticket
up by message id (`ticket/class/ticket.class.php:660, 720`).

Two operations:

- **Create ticket from message** — subject becomes the label, body the message,
  sender resolved to `fk_soc`, `origin_email` and `email_msgid` filled so core's
  own threading recognises it later.
- **Append to existing ticket** — match on `In-Reply-To`/`References` against
  `email_msgid`, or let the user search by ticket ref, then add a ticket
  message.

Depends on the write-bridge groundwork from proposal 1; after that it is
mostly the same endpoint with a different target.

**Effort:** medium. **Risk:** low-medium (duplicate tickets if dedupe is weak —
same idempotency discipline as 1).

---

## 6. Identity and signature from Dolibarr

Small, quick, and it removes setup friction that every new user hits today.

- `llx_user.signature` (`user/class/user.class.php:155`) → seed the Cypht
  profile signature.
- `llx_user.email` → default From address on first login.
- `llx_c_email_senderprofile` (`core/class/emailsenderprofile.class.php`) →
  offer Dolibarr's configured sender profiles as From identities.
- `MAIN_MAIL_SMTP_SERVER_*` constants → prefill the SMTP server on the
  Add-an-Account form, so the first-run experience is confirm-and-save rather
  than fill-in-eight-fields.

Fits the existing `Custom_User_Config` seam rather than needing a new module
set: seed defaults on first login when the user has no stored config.

**Effort:** low. **Risk:** low. Decide once whether Dolibarr keeps overwriting
these or only seeds them — overwriting a signature a user edited in Cypht would
be surprising.

---

## 7. Create a third party, contact or lead from a sender

The contacts notice currently tells the user that the address book is filled
from Dolibarr and there is nothing to add here directly. This turns that dead
end into an action: an unknown sender gets an "Add to Dolibarr" control that
creates a contact against an existing third party, or a new prospect.

- **Dolibarr side:** `Contact::create()` / `Societe::create()`, permissions
  `societe->creer` and `societe->contact->creer`.
- **Guard:** duplicate detection on email before create, and respect
  `SOCIETE_EMAIL_UNIQUE` if set.

Depends on proposal 1's write bridge. Cheap once that exists.

**Effort:** low-medium after 1. **Risk:** low.

---

## 8. Save an attachment to a Dolibarr object

The mirror of proposal 3: take an attachment off an inbound message and file it
under a third party, project, ticket or invoice.

- **Dolibarr side:** write into the object's document directory and index with
  `addFileIntoDatabaseIndex()` / `dol_add_file_process()`, so it appears in the
  object's Documents tab and in ECM.
- **Permission:** write right on the target element.

Naturally shares the target-picker UI with proposal 1 — build them together and
the picker is written once.

**Effort:** medium. **Risk:** medium (upload paths, MIME sniffing, size caps).

---

## Suggested order

1. **2 — sender context panel.** Read-only, no new architecture, immediately
   visible value. Good first proof that the message view is a workable surface.
2. **6 — identity and signature.** Small, independent, removes first-run
   friction while 1 is being designed.
3. **1 — log to agenda.** The write bridge. Everything from here depends on the
   conventions this one sets, so it is worth doing carefully rather than fast.
4. **4 — transactional templates.** Closes a known limitation, mostly bridge-side.
5. **3 — attach Dolibarr documents.** The other half of the compose story.
6. **5, 7, 8.** Cheap once the write bridge and the target picker exist.

## Cross-cutting decisions worth making before 1

- **Purpose tags.** One per endpoint, already the rule. Keep a list somewhere
  so two endpoints never share a tag.
- **Write semantics.** POST only, idempotent on a natural key, and a documented
  answer to "what happens if the same call arrives twice".
- **Target resolution.** Sender address → Dolibarr object is needed by 1, 2, 5,
  7 and 8. Write it once, in one place, and let the endpoints share it.
- **Permission checks are per-block, not per-endpoint.** `contacts.php` already
  does this for members; the context panel makes it the norm.
- **Module presence.** `isModEnabled()` around every optional source, so an
  install without tickets or projects degrades quietly.
