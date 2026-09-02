<?php

/**
 * Applies the preferences Dolibarr already holds for the user to their webmail
 * account, so the two halves of the application do not disagree.
 * @package modules
 * @subpackage dolibarr_prefs
 */

if (!defined('DEBUG_MODE')) { die(); }

/**
 * Set the interface language from the one Dolibarr resolved for this user.
 *
 * The value arrives in the environment rather than over HTTP: the SSO login
 * runs Cypht in-process inside Dolibarr, so there is no request to sign.
 * An empty value means the setup page left the choice to each user.
 *
 * @param object $user_config user configuration object
 * @param object $session user session object
 * @param object $request page request object
 * @param object $config site configuration object
 * @param string $user username
 * @param string $pass password
 * @return void
 */
function dolibarr_apply_language($user_config, $session, $request, $config, $user, $pass) {
    $lang = trim((string) Hm_Environment::get('DOLIBARR_USER_LANG', ''));
    if ($lang === '') {
        return;
    }

    /* Reaches a filename below, so nothing but a language code gets through. */
    if (!preg_match('/^[A-Za-z]{2}(-[A-Za-z]{2,4})?$/', $lang)) {
        Hm_Debug::add(sprintf('Ignoring malformed DOLIBARR_USER_LANG "%s"', $lang));
        return;
    }

    if (!is_readable(APP_PATH.'language/'.$lang.'.php')) {
        Hm_Debug::add(sprintf('No Cypht translation for "%s", keeping the default', $lang));
        return;
    }

    $user_config->set('language_setting', $lang);
}
