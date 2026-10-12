<?php

/**
 * Loads Dolibarr contacts for Cypht.
 *
 * @package modules
 * @subpackage dolibarr_contacts
 */

if (!defined('DEBUG_MODE')) { die(); }

/**
 * Read-only contact source: the cache file, or bridge/contacts.php.
 *
 * @subpackage dolibarr_contacts/lib
 */
class Hm_Dolibarr_Contacts {

    /** @var string Bridge URL */
    private $url;
    /** @var string Shared HMAC secret (same as SSO) */
    private $secret;

    public function __construct() {
        $this->url = Hm_Environment::get('DOLIBARR_CONTACTS_URL', '');
        $this->secret = Hm_Environment::get('SSO_SHARED_SECRET', '');
    }

    /**
     * @return bool true if the URL and secret are set
     */
    public function configured() {
        return $this->url !== '' && $this->secret !== '';
    }

    /**
     * How long a fetched list is kept, in seconds.
     *
     * @return int
     */
    public function ttl() {
        return (int) Hm_Environment::get('DOLIBARR_CONTACTS_TTL', 300);
    }

    /**
     * Contacts from the cache file Dolibarr wrote.
     *
     * @param string $login
     * @return array|false
     */
    private function fromCache($login) {
        $data = Hm_Dolibarr_Cache::read('contacts', $login, 'contacts');

        return ($data === false) ? false : $data['contacts'];
    }

    /**
     * Get the contacts for a Dolibarr login, from the cache or the bridge.
     *
     * @param string $login Dolibarr login
     * @return array|false Rows for Hm_Contact_Store::import(), false on error
     */
    public function fetch($login) {
        $cached = $this->fromCache($login);
        if ($cached !== false) {
            return $cached;
        }

        if (!$this->configured()) {
            return false;
        }

        $timestamp = time();
        $signature = hash_hmac('sha256', $login.'|'.$timestamp.'|contacts', $this->secret);

        $url = $this->url.(strpos($this->url, '?') === false ? '?' : '&');
        $url .= http_build_query(array(
            'login' => $login,
            'token' => $timestamp.'.'.$signature,
        ));

        $body = $this->request($url);
        if ($body === false) {
            return false;
        }

        $data = json_decode($body, true);
        if (!is_array($data) || !array_key_exists('contacts', $data)) {
            Hm_Debug::add('dolibarr_contacts: unexpected response: '.substr($body, 0, 200));
            return false;
        }

        return $data['contacts'];
    }

    /**
     * @param string $url Signed request URL
     * @return string|false Response body, or false on failure
     */
    private function request($url) {
        $timeout = (int) Hm_Environment::get('DOLIBARR_CONTACTS_TIMEOUT', 5);

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
            /* For sites with HTTP auth in front of Dolibarr. */
            $proxyAuth = Hm_Environment::get('DOLIBARR_BRIDGE_HTTP_AUTH', '');
            if ($proxyAuth !== '') {
                curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
                curl_setopt($ch, CURLOPT_USERPWD, $proxyAuth);
            }
            if (Hm_Environment::get('DOLIBARR_CONTACTS_INSECURE', 'false') === 'true') {
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            }
            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);

            if ($body === false || $status !== 200) {
                Hm_Debug::add('dolibarr_contacts: HTTP '.$status.' '.$err);
                return false;
            }
            return $body;
        }

        $streamOpts = array(
            'timeout' => $timeout,
            'follow_location' => 0,
            'ignore_errors' => true,
        );
        $proxyAuth = Hm_Environment::get('DOLIBARR_BRIDGE_HTTP_AUTH', '');
        if ($proxyAuth !== '') {
            $streamOpts['header'] = 'Authorization: Basic '.base64_encode($proxyAuth)."\r\n";
        }
        $context = stream_context_create(array('http' => $streamOpts));
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            Hm_Debug::add('dolibarr_contacts: request failed, no curl and file_get_contents returned false');
            return false;
        }
        return $body;
    }
}
