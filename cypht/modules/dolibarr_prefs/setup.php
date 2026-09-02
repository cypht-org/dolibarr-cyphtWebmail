<?php

if (!defined('DEBUG_MODE')) { die(); }

add_handler('functional_api', 'dolibarr_apply_language', true, 'dolibarr_prefs');
add_handler('functional_api', 'dolibarr_apply_theme', true, 'dolibarr_prefs');

handler_source('dolibarr_prefs');
output_source('dolibarr_prefs');

add_module_to_all_pages('handler', 'dolibarr_theme_auto', true, 'dolibarr_prefs', 'load_theme', 'after');
add_module_to_all_pages('output', 'dolibarr_theme_auto', true, 'dolibarr_prefs', 'theme_css', 'after');

return array(
    'allowed_output' => array(
        'dolibarr_theme_auto' => array(FILTER_VALIDATE_BOOLEAN, false),
    ),
);
