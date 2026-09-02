<?php

/**
 * @package modules
 * @subpackage dolibarr_context
 */

if (!defined('DEBUG_MODE')) { die(); }

handler_source('dolibarr_context');
output_source('dolibarr_context');

add_output('ajax_imap_message_content', 'dolibarr_context_shell', true, 'dolibarr_context', 'filter_message_headers', 'after');

setup_base_ajax_page('ajax_dolibarr_context', 'core');
add_handler('ajax_dolibarr_context', 'load_dolibarr_context', true, 'dolibarr_context', 'load_user_data', 'after');

setup_base_ajax_page('ajax_dolibarr_context_create', 'core');
add_handler('ajax_dolibarr_context_create', 'dolibarr_context_create', true, 'dolibarr_context', 'load_user_data', 'after');

return array(
    'allowed_pages' => array(
        'ajax_dolibarr_context',
        'ajax_dolibarr_context_create',
    ),
    'allowed_post' => array(
        'dolibarr_context_email' => FILTER_UNSAFE_RAW,
        'dolibarr_context_name' => FILTER_UNSAFE_RAW,
    ),
    'allowed_output' => array(
        'dolibarr_context_data' => array(FILTER_UNSAFE_RAW, false),
        'dolibarr_context_status' => array(FILTER_SANITIZE_FULL_SPECIAL_CHARS, false),
        'dolibarr_context_create_status' => array(FILTER_SANITIZE_FULL_SPECIAL_CHARS, false),
        'dolibarr_context_create_message' => array(FILTER_SANITIZE_FULL_SPECIAL_CHARS, false),
        'dolibarr_context_create_url' => array(FILTER_UNSAFE_RAW, false),
        'dolibarr_context_create_name' => array(FILTER_UNSAFE_RAW, false),
    ),
);
