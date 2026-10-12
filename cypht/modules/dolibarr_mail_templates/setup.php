<?php

/**
 * @package modules
 * @subpackage dolibarr_mail_templates
 */

if (!defined('DEBUG_MODE')) { die(); }

handler_source('dolibarr_mail_templates');
output_source('dolibarr_mail_templates');

add_handler('compose', 'load_dolibarr_mail_templates', true, 'dolibarr_mail_templates', 'load_user_data', 'after');

/* site.js moves this under the message box. */
add_output('compose', 'dolibarr_mail_templates_picker', true, 'dolibarr_mail_templates', 'compose_form_attach', 'after');

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
        'dolibarr_template_index' => FILTER_VALIDATE_INT,
    ),
    'allowed_output' => array(
        'dolibarr_mail_template_list' => array(FILTER_UNSAFE_RAW, false),
        'dolibarr_mail_template_selected' => array(FILTER_UNSAFE_RAW, false),
        'dolibarr_mail_template_count' => array(FILTER_VALIDATE_INT, false),
        'dolibarr_mail_templates_status' => array(FILTER_SANITIZE_FULL_SPECIAL_CHARS, false),
        'dolibarr_mail_templates_hint' => array(FILTER_SANITIZE_FULL_SPECIAL_CHARS, false),
    ),
);
