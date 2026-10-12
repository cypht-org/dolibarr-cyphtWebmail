<?php

/**
 * Applies the user's Dolibarr language and theme to Cypht.
 * @package modules
 * @subpackage dolibarr_prefs
 */

if (!defined('DEBUG_MODE')) { die(); }

/**
 * Set the Cypht language from the user's Dolibarr language.
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

    /* Used in a file path below, so only accept a language code. */
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
 * Set the Cypht theme to match the Dolibarr light or dark setting.
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

    /* Used in a directory path below, so only accept a theme name. */
    if (!preg_match('/^[A-Za-z0-9_-]{1,32}$/', $theme)) {
        Hm_Debug::add(sprintf('Ignoring malformed DOLIBARR_USER_THEME "%s"', $theme));
        return;
    }

    if (!is_dir(APP_PATH.'modules/themes/assets/'.$theme)) {
        Hm_Debug::add(sprintf('No such theme "%s", keeping the current one', $theme));
        return;
    }

    $user_config->set('theme_setting', $theme);

    $user_config->set('dolibarr_theme_auto',
        trim((string) Hm_Environment::get('DOLIBARR_USER_THEME_AUTO', '')) === '1');
}

/**
 * Pass the "follow the browser theme" flag to the page.
 * @subpackage dolibarr_prefs/handler
 */
class Hm_Handler_dolibarr_theme_auto extends Hm_Handler_Module {

    public function process() {
        $this->out('dolibarr_theme_auto', (bool) $this->user_config->get('dolibarr_theme_auto', false));
    }
}

/**
 * Add the dark stylesheet, used only when the browser prefers dark mode.
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

/**
 * Pass the Dolibarr login of this session to the page.
 *
 * @subpackage dolibarr_prefs/handler
 */
class Hm_Handler_dolibarr_session_user extends Hm_Handler_Module {

    public function process() {
        $login = $this->session->get('username', '');
        if ($login) {
            $this->out('dolibarr_session_user', (string) $login);
        }
    }
}

/**
 * Clear Cypht's browser storage when the Dolibarr user changes.
 *
 * @subpackage dolibarr_prefs/output
 */
class Hm_Output_dolibarr_session_user extends Hm_Output_Module {

    protected function output() {
        $login = $this->get('dolibarr_session_user', '');
        if ($login === '') {
            return '';
        }

        $json = json_encode($login, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        return '<script>(function(){try{var u='.$json.',k="dolibarr_session_user";'.
            'if(window.sessionStorage.getItem(k)!==u){window.sessionStorage.clear();'.
            'try{window.localStorage.clear();}catch(e){}window.sessionStorage.setItem(k,u);}}'.
            'catch(e){}})();</script>';
    }
}
