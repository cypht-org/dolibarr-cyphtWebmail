<?php

/**
 * @package modules
 * @subpackage dolibarr_mail_templates
 */

if (!defined('DEBUG_MODE')) { die(); }

handler_source('dolibarr_mail_templates');
output_source('dolibarr_mail_templates');

/* Compose is the only page that needs these. 'load_user_data' is the marker
 * the contacts module set uses on this page too, so the ordering is already
 * known good: user data first, then anything that depends on the session. */
add_handler('compose', 'load_dolibarr_mail_templates', true, 'dolibarr_mail_templates', 'load_user_data', 'after');

/* Last in the form. Rendering between 'compose_form_content' and the blocks
 * that follow splits Cypht's own button row, orphaning Sign onto a line of its
 * own. site.js moves the element up under the message box once the page is
 * built, which lands it where it belongs without breaking core's layout.
 * Above the To field, where it started, it blocked the ordinary path of
 * writing an email to reach a feature most messages do not use. */
add_output('compose', 'dolibarr_mail_templates_picker', true, 'dolibarr_mail_templates', 'compose_form_attach', 'after');

/* Bodies are fetched on demand, never embedded in the compose page: templates
 * hold pricing and contract wording, and putting all of them in the DOM to
 * serve the one that gets picked exposes the lot. This page returns labels for
 * a chosen type, and a body only for a chosen template. */
setup_base_ajax_page('ajax_dolibarr_mail_templates', 'core');
add_handler('ajax_dolibarr_mail_templates', 'load_dolibarr_mail_templates', true, 'dolibarr_mail_templates', 'load_user_data', 'after');
add_handler('ajax_dolibarr_mail_templates', 'dolibarr_mail_templates_request', true, 'dolibarr_mail_templates', 'load_dolibarr_mail_templates', 'after');

return array(
    'allowed_pages' => array(
        'ajax_dolibarr_mail_templates',
    ),
    'allowed_post' => array(
        'dolibarr_template_type' => FILTER_UNSAFE_RAW,
        'dolibarr_template_id' => FILTER_VALIDATE_INT,
        /* Asks for the whole index: every template's id, label and type, and
         * whether it carries placeholders. No subject and no body, so opening
         * the picker still reveals nothing a user has not chosen to read. */
        'dolibarr_template_index' => FILTER_VALIDATE_INT,
    ),
    'allowed_output' => array(
        /* UNSAFE_RAW because these carry JSON whose payload is template text.
         * FULL_SPECIAL_CHARS would entity encode every quote and force the
         * caller into the &quot; unpicking the contacts module has to do. The
         * values are consumed with .value and textContent, never innerHTML. */
        'dolibarr_mail_template_list' => array(FILTER_UNSAFE_RAW, false),
        'dolibarr_mail_template_selected' => array(FILTER_UNSAFE_RAW, false),
        'dolibarr_mail_template_count' => array(FILTER_VALIDATE_INT, false),
        'dolibarr_mail_templates_status' => array(FILTER_SANITIZE_FULL_SPECIAL_CHARS, false),
        'dolibarr_mail_templates_hint' => array(FILTER_SANITIZE_FULL_SPECIAL_CHARS, false),
    ),
);
