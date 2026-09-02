<?php

if (!defined('DEBUG_MODE')) { die(); }

/* functional_api is the page cypht_login() runs. Its handlers are plain
 * functions, called after the user config is loaded and before it is dumped
 * into the session, which is the only moment a preference can be applied
 * without the user having to save anything. */
add_handler('functional_api', 'dolibarr_apply_language', true, 'dolibarr_prefs');

return array();
