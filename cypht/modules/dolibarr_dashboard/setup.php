<?php

/**
 * @package modules
 * @subpackage dolibarr_dashboard
 */

if (!defined('DEBUG_MODE')) { die(); }

handler_source('dolibarr_dashboard');

setup_base_ajax_page('ajax_dolibarr_mail_status', 'core');
add_handler('ajax_dolibarr_mail_status', 'load_imap_servers_from_config', true, 'imap', 'load_user_data', 'after');
add_handler('ajax_dolibarr_mail_status', 'imap_oauth2_token_check', true, 'imap', 'load_imap_servers_from_config', 'after');
add_handler('ajax_dolibarr_mail_status', 'close_session_early', true, 'core', 'imap_oauth2_token_check', 'after');
add_handler('ajax_dolibarr_mail_status', 'dolibarr_mail_status', true, 'dolibarr_dashboard', 'close_session_early', 'after');

return array(
    'allowed_pages' => array(
        'ajax_dolibarr_mail_status',
    ),
    'allowed_output' => array(
        'dolibarr_mail_status' => array(FILTER_SANITIZE_FULL_SPECIAL_CHARS, false),
    ),
);
