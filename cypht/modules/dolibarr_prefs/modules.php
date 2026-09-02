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

/**
 * Set the theme to match the light or dark choice made for the surrounding
 * page, which this webmail is embedded in.
 *
 * @param object $user_config user configuration object
 * @param object $session user session object
 * @param object $request page request object
 * @param object $config site configuration object
 * @param string $user username
 * @param string $pass password
 * @return void
 */
function dolibarr_apply_theme($user_config, $session, $request, $config, $user, $pass) {
    $theme = trim((string) Hm_Environment::get('DOLIBARR_USER_THEME', ''));
    if ($theme === '') {
        return;
    }

    /* Reaches a directory name below, so nothing but a theme name gets through. */
    if (!preg_match('/^[A-Za-z0-9_-]{1,32}$/', $theme)) {
        Hm_Debug::add(sprintf('Ignoring malformed DOLIBARR_USER_THEME "%s"', $theme));
        return;
    }

    if (!is_dir(APP_PATH.'modules/themes/assets/'.$theme)) {
        Hm_Debug::add(sprintf('No such theme "%s", keeping the current one', $theme));
        return;
    }

    $user_config->set('theme_setting', $theme);

    /* Read back on every page draw by Hm_Handler_dolibarr_theme_auto. */
    $user_config->set('dolibarr_theme_auto',
        trim((string) Hm_Environment::get('DOLIBARR_USER_THEME_AUTO', '')) === '1');
}

/**
 * Carry the "let the browser decide" flag from the stored config onto the page.
 * @subpackage dolibarr_prefs/handler
 */
class Hm_Handler_dolibarr_theme_auto extends Hm_Handler_Module {

    public function process() {
        $this->out('dolibarr_theme_auto', (bool) $this->user_config->get('dolibarr_theme_auto', false));
    }
}

/**
 * Layer the dark stylesheet on top, for the browser to apply or ignore.
 * @subpackage dolibarr_prefs/output
 */
class Hm_Output_dolibarr_theme_auto extends Hm_Output_Module {

    protected function output() {
        if (!$this->get('dolibarr_theme_auto', false)) {
            return '';
        }

        $theme = 'darkly';
        if (!is_dir(APP_PATH.'modules/themes/assets/'.$theme)) {
            return '';
        }

        return '<link href="'.ASSETS_THEMES_ROOT.'modules/themes/assets/'.$theme.'/css/'.$theme.'.css?v='.CACHE_ID.'"'.
            ' media="(prefers-color-scheme: dark)" rel="stylesheet" type="text/css" />';
    }
}
