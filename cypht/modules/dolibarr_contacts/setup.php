<?php

/**
 * @package modules
 * @subpackage dolibarr_contacts
 */

if (!defined('DEBUG_MODE')) { die(); }

handler_source('dolibarr_contacts');
output_source('dolibarr_contacts');

/* Contacts page */
add_handler('contacts', 'load_dolibarr_contacts', true, 'dolibarr_contacts', 'load_contacts', 'after');

/* Compose: To/Cc/Bcc autocomplete */
add_handler('compose', 'load_dolibarr_contacts', true, 'dolibarr_contacts', 'load_contacts', 'after');
add_handler('ajax_autocomplete_contact', 'load_dolibarr_contacts', true, 'dolibarr_contacts', 'load_contacts', 'after');

/* Message view */
add_handler('ajax_imap_message_content', 'load_dolibarr_contacts', true, 'dolibarr_contacts', 'load_contacts', 'after');

/* vCard export */
add_handler('export_contact', 'load_dolibarr_contacts', true, 'dolibarr_contacts', 'process_export_contacts', 'before');

/* Empty or error message above the contact tabs. */
add_output('contacts', 'dolibarr_contacts_notice', true, 'dolibarr_contacts', 'contacts_content_start', 'after');

return array(
    'allowed_output' => array(
        'dolibarr_contact_count' => array(FILTER_VALIDATE_INT, false),
        'dolibarr_contacts_status' => array(FILTER_SANITIZE_FULL_SPECIAL_CHARS, false),
    ),
);
