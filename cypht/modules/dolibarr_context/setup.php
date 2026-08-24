<?php

/**
 * @package modules
 * @subpackage dolibarr_context
 */

if (!defined('DEBUG_MODE')) { die(); }

handler_source('dolibarr_context');
output_source('dolibarr_context');

/* After 'filter_message_headers', because that module is what writes the
 * msg_headers string this one appends to, and what puts the parsed sender
 * address into output. Before it, both would be missing. */
add_output('ajax_imap_message_content', 'dolibarr_context_shell', true, 'dolibarr_context', 'filter_message_headers', 'after');

/* The card is fetched on its own request, never embedded in the message
 * response: the message body must not wait on an HTTP round trip to Dolibarr,
 * and imap/site.js writes that response into local storage, where a cached
 * copy of somebody's unpaid invoices has no business being. */
setup_base_ajax_page('ajax_dolibarr_context', 'core');
add_handler('ajax_dolibarr_context', 'load_dolibarr_context', true, 'dolibarr_context', 'load_user_data', 'after');

return array(
    'allowed_pages' => array(
        'ajax_dolibarr_context',
    ),
    'allowed_post' => array(
        /* UNSAFE_RAW, then validated as an address in the handler:
         * FILTER_VALIDATE_EMAIL here would drop the field silently and the
         * panel would have no way to tell a bad address from a missing one. */
        'dolibarr_context_email' => FILTER_UNSAFE_RAW,
    ),
    'allowed_output' => array(
        /* UNSAFE_RAW because this carries JSON whose payload is company names
         * and document references. FULL_SPECIAL_CHARS would entity encode
         * every apostrophe in every company name. The values are consumed with
         * textContent and href, never innerHTML. */
        'dolibarr_context_data' => array(FILTER_UNSAFE_RAW, false),
        'dolibarr_context_status' => array(FILTER_SANITIZE_FULL_SPECIAL_CHARS, false),
    ),
);
