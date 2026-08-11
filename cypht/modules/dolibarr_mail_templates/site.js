'use strict';

/**
 * Compose screen template picker.
 *
 * A single line under the message box opens a dialog built with core's
 * Hm_Modal, so the picker looks and behaves like every other overlay in Cypht
 * rather than being a panel this module invented.
 *
 * Nothing about a template is in the page when it loads. Opening the dialog
 * fetches an index of names; choosing a row fetches that one template into a
 * preview; only Insert writes to the draft, and that is undoable.
 *
 * This file is concatenated into the bundled site.js by config_gen.php, which
 * means it loads on every page. Every entry point therefore has to tolerate
 * the picker being absent.
 */

var dolibarr_mail_templates_state = {
    index: null,
    loading: false,
    selected: null,
    selected_id: null,
    modal: null,
    undo: null,
    undo_timer: null
};

var dolibarr_mail_templates_strings = function(root) {
    return {
        title: root.getAttribute('data-str-title') || '',
        search: root.getAttribute('data-str-search') || '',
        preview: root.getAttribute('data-str-preview') || '',
        insert: root.getAttribute('data-str-insert') || '',
        loading: root.getAttribute('data-str-loading') || '',
        empty: root.getAttribute('data-str-empty') || '',
        failed: root.getAttribute('data-str-failed') || '',
        warn: root.getAttribute('data-str-warn') || '',
        flagged: root.getAttribute('data-str-flagged') || '',
        inserted: root.getAttribute('data-str-inserted') || ''
    };
};

var dolibarr_mail_templates_nodes = function() {
    return {
        search: document.getElementById('dolibarr_mail_template_search'),
        list: document.getElementById('dolibarr_mail_template_list'),
        preview: document.getElementById('dolibarr_mail_template_preview'),
        previewSubject: document.getElementById('dolibarr_mail_template_preview_subject'),
        previewBody: document.getElementById('dolibarr_mail_template_preview_body'),
        warning: document.getElementById('dolibarr_mail_template_warning'),
        undoBar: document.getElementById('dolibarr_mail_template_undo_bar'),
        undoText: document.getElementById('dolibarr_mail_template_undo_text'),
        undo: document.getElementById('dolibarr_mail_template_undo')
    };
};

var dolibarr_mail_templates_note = function(listEl, text) {
    listEl.innerHTML = '';
    var note = document.createElement('div');
    note.className = 'small text-muted p-2';
    note.textContent = text;
    listEl.appendChild(note);
};

/**
 * Draw the index, grouped by type, filtered by the search term.
 */
var dolibarr_mail_templates_render = function(strings, term) {
    var nodes = dolibarr_mail_templates_nodes();
    if (!nodes.list) {
        return;
    }
    var index = dolibarr_mail_templates_state.index || [];
    var needle = (term || '').toLowerCase();
    var shown = index.filter(function(row) {
        if (needle === '') {
            return true;
        }
        return (row.label + ' ' + row.type_label).toLowerCase().indexOf(needle) > -1;
    });

    if (!shown.length) {
        dolibarr_mail_templates_note(nodes.list, strings.empty);
        return;
    }

    nodes.list.innerHTML = '';

    /* Group in one pass so each heading carries its own count and container.
     * Type is a heading, not a gate: as a gate it hid the catalogue behind a
     * guess at which type a template lived under. */
    var groups = [];
    var byType = {};
    shown.forEach(function(row) {
        if (!byType[row.type]) {
            byType[row.type] = { type: row.type, label: row.type_label || row.type, rows: [] };
            groups.push(byType[row.type]);
        }
        byType[row.type].rows.push(row);
    });

    groups.forEach(function(group) {
        var head = document.createElement('button');
        head.type = 'button';
        head.className = 'btn btn-sm d-flex align-items-center gap-1 w-100 text-start px-2 py-1 fw-bold border-0';
        head.setAttribute('data-group', group.type);
        /* Collapsed state is per group and starts open, so the catalogue is
         * visible on arrival and folding away the noise is the user's choice
         * rather than the default. */
        head.setAttribute('aria-expanded', 'true');

        var caret = document.createElement('i');
        caret.className = 'bi bi-chevron-down';
        head.appendChild(caret);

        var name = document.createElement('span');
        name.textContent = group.label;
        head.appendChild(name);

        var count = document.createElement('span');
        count.className = 'badge bg-body-secondary text-secondary fw-normal ms-1';
        count.textContent = String(group.rows.length);
        head.appendChild(count);

        nodes.list.appendChild(head);

        /* Indented, so a row reads as belonging to the heading above it. */
        var items = document.createElement('div');
        items.className = 'ms-3';
        items.setAttribute('data-group-items', group.type);

        group.rows.forEach(function(row) {
            var item = document.createElement('button');
            item.type = 'button';
            item.className = 'btn btn-sm btn-link text-decoration-none d-block text-start w-100 px-2 py-1 rounded';
            item.setAttribute('role', 'option');
            item.setAttribute('data-id', String(row.id));
            item.textContent = row.label;

            if (row.flagged) {
                /* Info blue, not an amber triangle: placeholders are a
                 * property of the template, not a fault. Matches the preview
                 * note. */
                var mark = document.createElement('i');
                mark.className = 'bi bi-braces ms-1 text-primary';
                mark.setAttribute('title', strings.flagged);
                item.appendChild(mark);
            }

            items.appendChild(item);
        });

        nodes.list.appendChild(items);
    });

    /* Redrawing wipes the highlight, so put it back on whatever is still
     * selected and still visible. */
    dolibarr_mail_templates_highlight(dolibarr_mail_templates_state.selected_id);
};

/**
 * Mark one row as the chosen one. The preview alone does not say which row it
 * came from once the list has scrolled.
 */
var dolibarr_mail_templates_highlight = function(id) {
    var listEl = document.getElementById('dolibarr_mail_template_list');
    if (!listEl) {
        return;
    }
    [].forEach.call(listEl.querySelectorAll('[data-id]'), function(el) {
        var on = id !== null && el.getAttribute('data-id') === String(id);
        el.classList.toggle('bg-body-secondary', on);
        el.classList.toggle('fw-medium', on);
        el.setAttribute('aria-selected', on ? 'true' : 'false');
    });
};

var dolibarr_mail_templates_toggle_group = function(head) {
    var type = head.getAttribute('data-group');
    var listEl = document.getElementById('dolibarr_mail_template_list');
    if (!listEl) {
        return;
    }
    var items = listEl.querySelector('[data-group-items="' + type + '"]');
    if (!items) {
        return;
    }
    var open = head.getAttribute('aria-expanded') !== 'false';
    items.hidden = open;
    head.setAttribute('aria-expanded', open ? 'false' : 'true');
    var caret = head.querySelector('i');
    if (caret) {
        caret.className = open ? 'bi bi-chevron-right' : 'bi bi-chevron-down';
    }
};

/**
 * List the markers that survived substitution.
 *
 * Not styled as a warning: leftover markers are the normal state for a
 * template written around an object compose does not have. Chips, not alert
 * text, so they read as blanks to fill.
 *
 * @param {HTMLElement} box     Container, emptied and rebuilt
 * @param {Object}      strings Translated strings from the trigger
 * @param {Array}       list    Placeholder markers, may be empty or absent
 */
var dolibarr_mail_templates_placeholders = function(box, strings, list) {
    if (!box) {
        return;
    }
    box.innerHTML = '';

    if (!list || !list.length) {
        box.hidden = true;
        return;
    }

    var lead = document.createElement('div');
    lead.className = 'text-secondary mb-1';
    lead.textContent = strings.warn;
    box.appendChild(lead);

    var chips = document.createElement('div');
    chips.className = 'd-flex flex-wrap gap-1';

    list.forEach(function(marker) {
        var chip = document.createElement('span');
        chip.className = 'badge bg-light text-primary border border-primary fw-normal font-monospace';
        /* textContent: a marker is template content, not markup. */
        chip.textContent = marker;
        chips.appendChild(chip);
    });

    box.appendChild(chips);
    box.hidden = false;
};

var dolibarr_mail_templates_show_preview = function(strings, template) {
    var nodes = dolibarr_mail_templates_nodes();
    dolibarr_mail_templates_state.selected = template;
    if (!nodes.preview) {
        return;
    }

    nodes.previewSubject.textContent = template.subject;
    /* Plain text, truncated: this is a glance to confirm the right template,
     * not a rendering of the mail. */
    var plain = String(template.body).replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
    nodes.previewBody.textContent = plain.length > 220 ? plain.slice(0, 220) + '…' : plain;

    dolibarr_mail_templates_placeholders(nodes.warning, strings, template.placeholders);

    nodes.preview.hidden = false;
};

var dolibarr_mail_templates_hide_undo = function() {
    var nodes = dolibarr_mail_templates_nodes();
    if (dolibarr_mail_templates_state.undo_timer) {
        clearTimeout(dolibarr_mail_templates_state.undo_timer);
        dolibarr_mail_templates_state.undo_timer = null;
    }
    if (nodes.undoBar) {
        nodes.undoBar.hidden = true;
    }
    dolibarr_mail_templates_state.undo = null;
};

/**
 * Write the selected template into the compose fields, remembering enough to
 * put them back.
 */
var dolibarr_mail_templates_insert = function(strings) {
    var template = dolibarr_mail_templates_state.selected;
    if (!template) {
        return;
    }
    var nodes = dolibarr_mail_templates_nodes();
    var subject = document.getElementById('compose_subject');
    var body = document.getElementById('compose_body');

    dolibarr_mail_templates_state.undo = {
        subject: subject ? subject.value : null,
        body: body ? body.value : null
    };

    /* The subject is only ever filled when empty. Overwriting a subject the
     * user already typed loses work. */
    if (subject && subject.value === '') {
        subject.value = template.subject;
    }

    /* Insert at the caret rather than replacing, so a template can be dropped
     * in above an existing signature. */
    if (body) {
        var start = body.selectionStart || 0;
        var end = body.selectionEnd || 0;
        var value = body.value;
        body.value = value.slice(0, start) + template.body + value.slice(end);
        body.selectionStart = body.selectionEnd = start + template.body.length;
    }

    if (dolibarr_mail_templates_state.modal) {
        dolibarr_mail_templates_state.modal.hide();
    }
    if (body) {
        body.focus();
    }

    /* Inserting is the one destructive thing this picker does, and it lands in
     * the middle of text the user may have written. Offer the way back. */
    if (nodes.undoBar) {
        nodes.undoText.textContent = strings.inserted;
        nodes.undoBar.hidden = false;
        if (dolibarr_mail_templates_state.undo_timer) {
            clearTimeout(dolibarr_mail_templates_state.undo_timer);
        }
        /* Long enough to read the inserted text, decide it is the wrong
         * template, and act. A shorter window expires while the user is still
         * reading the thing they would want to undo. */
        dolibarr_mail_templates_state.undo_timer = setTimeout(function() {
            dolibarr_mail_templates_hide_undo();
        }, 30000);
    }
};

var dolibarr_mail_templates_undo = function() {
    var previous = dolibarr_mail_templates_state.undo;
    if (!previous) {
        return;
    }
    var subject = document.getElementById('compose_subject');
    var body = document.getElementById('compose_body');
    if (subject && previous.subject !== null) {
        subject.value = previous.subject;
    }
    if (body && previous.body !== null) {
        body.value = previous.body;
    }
    dolibarr_mail_templates_hide_undo();
};

var dolibarr_mail_templates_load_index = function(strings) {
    var nodes = dolibarr_mail_templates_nodes();
    if (dolibarr_mail_templates_state.index) {
        dolibarr_mail_templates_render(strings, '');
        return;
    }
    if (dolibarr_mail_templates_state.loading) {
        return;
    }
    dolibarr_mail_templates_state.loading = true;
    dolibarr_mail_templates_note(nodes.list, strings.loading);

    Hm_Ajax.request(
        [{'name': 'hm_ajax_hook', 'value': 'ajax_dolibarr_mail_templates'},
        {'name': 'dolibarr_template_index', 'value': '1'}],
        function(res) {
            dolibarr_mail_templates_state.loading = false;
            var listEl = document.getElementById('dolibarr_mail_template_list');
            if (!listEl) {
                return; /* dialog closed while the request was in flight */
            }
            if (!res.dolibarr_mail_template_list) {
                dolibarr_mail_templates_note(listEl, strings.failed);
                return;
            }
            try {
                dolibarr_mail_templates_state.index = JSON.parse(res.dolibarr_mail_template_list);
            } catch (err) {
                dolibarr_mail_templates_note(listEl, strings.failed);
                return;
            }
            dolibarr_mail_templates_render(strings, '');
        }
    );
};

/**
 * The dialog body. Static markup only: every row and every line of preview
 * text is written later with textContent, never interpolated into HTML.
 */
var dolibarr_mail_templates_shell = function(strings) {
    /* Search and results share one bordered box: the field filters the list
     * directly under it, and framing them together says so. */
    return '<div class="border rounded p-2">' +
        '<input type="text" id="dolibarr_mail_template_search" class="form-control form-control-sm mb-2"' +
        ' autocomplete="off" placeholder="' + strings.search + '" aria-label="' + strings.search + '">' +
        '<div id="dolibarr_mail_template_list" role="listbox" style="max-height:18rem; overflow-y:auto;"></div>' +
        '</div>' +
        /* text-break is load-bearing: a marker like __TICKET_USER_ASSIGN__ is
         * one unbroken word and pushes the dialog past the viewport without
         * it. */
        '<div id="dolibarr_mail_template_preview" class="mt-3" hidden>' +
        '<div class="fw-bold mb-1">' + strings.preview + '</div>' +
        '<div class="border rounded p-2">' +
        '<div id="dolibarr_mail_template_preview_subject" class="small fw-medium text-break"></div>' +
        '<div id="dolibarr_mail_template_preview_body" class="small text-secondary text-break border-top mt-2 pt-2" style="white-space:pre-wrap;"></div>' +
        '</div>' +
        '<div id="dolibarr_mail_template_warning" class="small border rounded bg-body-tertiary p-2 mt-2" hidden></div>' +
        '</div>';
};

var dolibarr_mail_templates_open = function(strings) {
    var modal = new Hm_Modal({
        title: strings.title,
        size: 'lg',
        modalId: 'dolibarr_mail_template_modal'
    });
    dolibarr_mail_templates_state.modal = modal;
    /* Rebuilt each open, so state cannot leak from a previous session. */
    dolibarr_mail_templates_state.selected = null;
    dolibarr_mail_templates_state.selected_id = null;

    modal.setContent(dolibarr_mail_templates_shell(strings));
    modal.addFooterBtn(strings.insert, 'btn-primary', function() {
        dolibarr_mail_templates_insert(strings);
    });
    modal.open();

    var nodes = dolibarr_mail_templates_nodes();

    if (nodes.search) {
        nodes.search.addEventListener('input', function() {
            if (dolibarr_mail_templates_state.index) {
                dolibarr_mail_templates_render(strings, nodes.search.value);
            }
        });
    }

    if (nodes.list) {
        nodes.list.addEventListener('click', function(ev) {
            /* Headings fold their group; rows load a preview. Checked in this
             * order because a heading is never inside a row. */
            var head = ev.target.closest('[data-group]');
            if (head) {
                dolibarr_mail_templates_toggle_group(head);
                return;
            }

            var item = ev.target.closest('[data-id]');
            if (!item) {
                return;
            }

            dolibarr_mail_templates_state.selected_id = item.getAttribute('data-id');
            dolibarr_mail_templates_highlight(dolibarr_mail_templates_state.selected_id);

            Hm_Ajax.request(
                [{'name': 'hm_ajax_hook', 'value': 'ajax_dolibarr_mail_templates'},
                {'name': 'dolibarr_template_id', 'value': item.getAttribute('data-id')}],
                function(res) {
                    if (!res.dolibarr_mail_template_selected) {
                        return;
                    }
                    var template;
                    try {
                        template = JSON.parse(res.dolibarr_mail_template_selected);
                    } catch (err) {
                        return;
                    }
                    dolibarr_mail_templates_show_preview(strings, template);
                }
            );
        });
    }

    dolibarr_mail_templates_load_index(strings);
};

var dolibarr_mail_templates_init = function() {
    var root = document.getElementById('dolibarr_mail_templates_picker');
    var toggle = document.getElementById('dolibarr_mail_template_toggle');
    if (!root || !toggle) {
        return; /* not the compose page, or nothing to show */
    }

    /* Both entry points below can fire against one render, and the listeners
     * would stack. The flag lives on the button, so a page swap that brings a
     * fresh one starts clean. */
    if (toggle.getAttribute('data-dolibarr-bound') === '1') {
        return;
    }
    toggle.setAttribute('data-dolibarr-bound', '1');

    /* The output module has to render last, or it splits Cypht's button row,
     * so move it under the message box here. Guarded at every step: an
     * upstream markup change should leave the trigger where it is, not
     * throw. */
    var composeBody = document.getElementById('compose_body');
    if (composeBody) {
        var anchor = composeBody.closest('.form-floating') || composeBody.parentNode;
        if (anchor && anchor.parentNode) {
            anchor.parentNode.insertBefore(root, anchor.nextSibling);
        }
    }

    var strings = dolibarr_mail_templates_strings(root);

    toggle.addEventListener('click', function() {
        dolibarr_mail_templates_hide_undo();
        dolibarr_mail_templates_open(strings);
    });

    var undoBtn = document.getElementById('dolibarr_mail_template_undo');
    if (undoBtn) {
        undoBtn.addEventListener('click', dolibarr_mail_templates_undo);
    }
};

/* Two entry points, because there are two ways to arrive at compose.
 *
 * A full page load fires document ready. An in-app navigation does not: it
 * swaps #cypht-main and calls the route handler registered for the page,
 * within the same document, so ready never fires a second time. Relying on
 * ready alone left the picker wherever the output module had rendered it, at
 * the end of the form, with nothing bound to its button.
 *
 * Wrapping is safe this early: routes.js resolves handler names off window
 * when it builds ROUTES, and config_gen appends the navigation files after
 * every module, so this replacement is what gets registered. */
var dolibarr_mail_templates_compose_handler = window.applyComposePageHandlers;
window.applyComposePageHandlers = function(routeParams) {
    var unmount = dolibarr_mail_templates_compose_handler
        ? dolibarr_mail_templates_compose_handler(routeParams)
        : undefined;

    dolibarr_mail_templates_init();

    return unmount;
};

$(document).ready(function() {
    dolibarr_mail_templates_init();
});
