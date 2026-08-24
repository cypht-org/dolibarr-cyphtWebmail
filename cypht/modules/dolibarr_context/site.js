'use strict';

/**
 * Dolibarr card under the headers of an open message.
 *
 * The message response carries an empty shell with the sender's address on it.
 * This file spots that shell, asks ajax_dolibarr_context who the address is,
 * and draws a summary line: the third party, then one chip per thing still
 * open against them. A chip expands to the few most recent records.
 *
 * Every value drawn here comes from Dolibarr and is written with textContent,
 * never interpolated into HTML. Links carry target="_top" because Cypht runs
 * in an iframe inside Dolibarr, and without it a third party card would open
 * nested inside the webmail pane.
 *
 * This file is concatenated into the bundled site.js by config_gen.php, which
 * means it loads on every page. Every entry point therefore has to tolerate
 * the shell being absent.
 */

/**
 * The last card drawn, so opening a message whose response was replayed from
 * local storage before the server copy landed draws twice from memory rather
 * than asking Dolibarr twice for the same address.
 */
var dolibarr_context_state = {
    email: null,
    data: null,
    status: '',
    at: 0
};

var dolibarr_context_strings = function(root) {
    return {
        loading: root.getAttribute('data-str-loading') || '',
        unknown: root.getAttribute('data-str-unknown') || '',
        failed: root.getAttribute('data-str-failed') || '',
        stale: root.getAttribute('data-str-stale') || '',
        open: root.getAttribute('data-str-open') || '',
        more: root.getAttribute('data-str-more') || '',
        details: root.getAttribute('data-str-details') || '',
        customer: root.getAttribute('data-str-customer') || '',
        supplier: root.getAttribute('data-str-supplier') || ''
    };
};

var dolibarr_context_body = function(root) {
    return root.querySelector('.dolibarr_context_body');
};

var dolibarr_context_note = function(root, text, cls) {
    var body = dolibarr_context_body(root);
    if (!body) {
        return;
    }
    body.innerHTML = '';
    var note = document.createElement('span');
    note.className = cls || 'text-muted';
    note.textContent = text;
    body.appendChild(note);
};

/**
 * An anchor out to Dolibarr. Never built from a string, so a company name or a
 * document reference cannot close the attribute it sits in.
 */
var dolibarr_context_link = function(text, url, cls) {
    var link = document.createElement('a');
    link.className = cls || 'text-decoration-none';
    link.textContent = text;
    /* Escapes the webmail iframe, as the contacts notice does. */
    link.setAttribute('target', '_top');
    link.setAttribute('href', url);
    /* Cypht intercepts internal links to route them; this one is not ours. */
    link.setAttribute('data-external', 'true');
    return link;
};

var dolibarr_context_badge = function(text, cls) {
    var badge = document.createElement('span');
    badge.className = 'badge fw-normal ' + (cls || 'bg-body-secondary text-secondary');
    badge.textContent = text;
    return badge;
};

/**
 * The rows behind one chip: reference, date, amount, each linking to its card.
 */
var dolibarr_context_rows = function(block, strings) {
    var wrap = document.createElement('div');
    wrap.className = 'dolibarr_context_rows ms-1 mt-1 border-start ps-2';
    wrap.setAttribute('data-rows-for', block.key);
    wrap.hidden = true;

    (block.rows || []).forEach(function(row) {
        var line = document.createElement('div');
        line.className = 'd-flex flex-wrap align-items-baseline gap-2 py-1';

        line.appendChild(dolibarr_context_link(row.ref, row.url, 'text-decoration-none fw-medium'));

        if (row.date) {
            var date = document.createElement('span');
            date.className = 'text-muted';
            date.textContent = row.date;
            line.appendChild(date);
        }

        if (row.amount) {
            var amount = document.createElement('span');
            amount.className = 'ms-auto';
            amount.textContent = row.amount;
            line.appendChild(amount);
        }

        wrap.appendChild(line);
    });

    /* Only when the block holds more than the rows we were given, so the link
     * never promises a longer list than there is. */
    if (block.count > (block.rows || []).length && block.url) {
        var more = document.createElement('div');
        more.className = 'py-1';
        more.appendChild(dolibarr_context_link(strings.more, block.url, 'text-decoration-none small'));
        wrap.appendChild(more);
    }

    return wrap;
};

/**
 * One chip: a count, a label, and a total when the records carry money.
 */
var dolibarr_context_chip = function(block) {
    var chip = document.createElement('button');
    chip.type = 'button';
    chip.className = 'btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1 py-0 px-2';
    chip.setAttribute('data-block', block.key);
    chip.setAttribute('aria-expanded', 'false');

    if (block.icon) {
        var icon = document.createElement('i');
        icon.className = 'bi ' + block.icon;
        chip.appendChild(icon);
    }

    var count = document.createElement('span');
    count.className = 'fw-medium';
    count.textContent = String(block.count);
    chip.appendChild(count);

    var label = document.createElement('span');
    label.textContent = block.label;
    chip.appendChild(label);

    if (block.total) {
        var total = document.createElement('span');
        total.className = 'text-muted';
        total.textContent = block.total;
        chip.appendChild(total);
    }

    return chip;
};

/**
 * The identity line: third party, the person inside it, and what they are to
 * us. Falls back to the person alone for a colleague or a member, who have no
 * third party behind them.
 */
var dolibarr_context_identity = function(data, strings) {
    var line = document.createElement('div');
    line.className = 'd-flex flex-wrap align-items-baseline gap-2';

    var match = data.match;
    var party = data.thirdparty;

    if (party) {
        line.appendChild(dolibarr_context_link(party.name, party.url, 'text-decoration-none fw-semibold'));

        if (match && match.type !== 'thirdparty' && match.name) {
            var person = document.createElement('span');
            person.textContent = match.name + (match.job ? ' · ' + match.job : '');
            line.appendChild(person);
        }

        if (party.is_customer) {
            line.appendChild(dolibarr_context_badge(strings.customer, 'bg-primary-subtle text-primary-emphasis'));
        }
        if (party.is_supplier) {
            line.appendChild(dolibarr_context_badge(strings.supplier, 'bg-info-subtle text-info-emphasis'));
        }
    } else if (match) {
        line.appendChild(dolibarr_context_link(match.name || match.type_label, match.url, 'text-decoration-none fw-semibold'));
        line.appendChild(dolibarr_context_badge(match.type_label));
        if (match.job) {
            var job = document.createElement('span');
            job.textContent = match.job;
            line.appendChild(job);
        }
    }

    /* Right hand end, so the way out of the panel is always in the same place
     * whatever the identity line happens to contain. */
    var target = party || match;
    if (target && target.url) {
        var out = dolibarr_context_link(strings.open, target.url, 'text-decoration-none ms-auto');
        line.appendChild(out);
    }

    return line;
};

var dolibarr_context_render = function(root, strings, data, status) {
    var body = dolibarr_context_body(root);
    if (!body) {
        return;
    }

    if (!data || !data.match) {
        /* Deliberately not hidden. "This sender is nobody we have a record of"
         * is worth a line, and a panel that appears only sometimes is harder
         * to read than one that is always there. */
        dolibarr_context_note(root, strings.unknown, 'text-muted fst-italic');
        return;
    }

    body.innerHTML = '';
    body.appendChild(dolibarr_context_identity(data, strings));

    var blocks = data.blocks || [];
    if (blocks.length) {
        var chips = document.createElement('div');
        chips.className = 'd-flex flex-wrap gap-2 mt-2';

        var rows = document.createElement('div');
        rows.className = 'mt-1';

        blocks.forEach(function(block) {
            chips.appendChild(dolibarr_context_chip(block));
            rows.appendChild(dolibarr_context_rows(block, strings));
        });

        body.appendChild(chips);
        body.appendChild(rows);

        chips.addEventListener('click', function(ev) {
            var chip = ev.target.closest('[data-block]');
            if (!chip) {
                return;
            }
            var key = chip.getAttribute('data-block');
            var panel = rows.querySelector('[data-rows-for="' + key + '"]');
            if (!panel) {
                return;
            }
            var opening = panel.hidden;
            /* One open at a time: the panel sits above the message and should
             * not grow to push the body off the screen. */
            rows.querySelectorAll('[data-rows-for]').forEach(function(other) {
                other.hidden = true;
            });
            chips.querySelectorAll('[data-block]').forEach(function(other) {
                other.setAttribute('aria-expanded', 'false');
                other.classList.remove('active');
            });
            panel.hidden = !opening;
            chip.setAttribute('aria-expanded', opening ? 'true' : 'false');
            chip.classList.toggle('active', opening);
        });
    }

    if (status === 'stale') {
        var warn = document.createElement('div');
        warn.className = 'small text-warning-emphasis mt-1';
        warn.textContent = strings.stale;
        body.appendChild(warn);
    }
};

var dolibarr_context_load = function(root, strings) {
    var email = root.getAttribute('data-email');

    /* Short, and deliberately shorter than the session cache behind it: this
     * one exists to absorb the double render of a single message, not to hold
     * a card across the minutes a user spends reading one. */
    if (dolibarr_context_state.email === email
            && dolibarr_context_state.data
            && (Date.now() - dolibarr_context_state.at) < 15000) {
        dolibarr_context_render(root, strings, dolibarr_context_state.data, dolibarr_context_state.status);
        return;
    }

    dolibarr_context_note(root, strings.loading);

    Hm_Ajax.request(
        [{'name': 'hm_ajax_hook', 'value': 'ajax_dolibarr_context'},
        {'name': 'dolibarr_context_email', 'value': email}],
        function(res) {
            /* The message may have been closed, or another one opened, while
             * the request was in flight. Redrawing the shell we were given
             * rather than whatever is on screen now keeps the card and the
             * message it belongs to together. */
            if (!document.body.contains(root)) {
                return;
            }

            var status = res.dolibarr_context_status || 'error';

            if (status === 'unconfigured') {
                /* Nothing was wired up. A build problem is not something to
                 * explain on top of somebody's mail; the debug log has it. */
                root.hidden = true;
                return;
            }

            if (!res.dolibarr_context_data) {
                dolibarr_context_note(root, strings.failed, 'text-danger');
                return;
            }

            var data;
            try {
                data = JSON.parse(res.dolibarr_context_data);
            } catch (err) {
                dolibarr_context_note(root, strings.failed, 'text-danger');
                return;
            }

            dolibarr_context_state = {
                email: email,
                data: data,
                status: status,
                at: Date.now()
            };

            dolibarr_context_render(root, strings, data, status);
        }
    );
};

var dolibarr_context_init = function() {
    var root = document.getElementById('dolibarr_context');
    if (!root || !root.getAttribute('data-email')) {
        return; /* not a message view, or no address to look up */
    }

    /* The message response is replayed from local storage before the server
     * copy lands, so this runs twice for one message. */
    if (root.getAttribute('data-dolibarr-bound') === '1') {
        return;
    }
    root.setAttribute('data-dolibarr-bound', '1');

    dolibarr_context_load(root, dolibarr_context_strings(root));
};

/* imap/site.js calls this once the headers, body and parts are all in the DOM,
 * which is the first moment the shell exists to be found. */
var dolibarr_context_view_finished = window.imap_message_view_finished;
window.imap_message_view_finished = function(msg_uid, detail, listParent, skip_links) {
    var result = dolibarr_context_view_finished
        ? dolibarr_context_view_finished(msg_uid, detail, listParent, skip_links)
        : undefined;

    dolibarr_context_init();

    return result;
};

$(document).ready(function() {
    dolibarr_context_init();
});
