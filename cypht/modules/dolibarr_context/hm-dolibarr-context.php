<?php

/**
 * Read-only sender context source backed by bridge/context.php in the Dolibarr
 * module. Deliberately a near copy of Hm_Dolibarr_Contacts and
 * Hm_Dolibarr_Mail_Templates: same signing scheme, same transport, same
 * failure handling. The three differ only in the purpose tag and the env keys,
 * and keeping them parallel is worth more than the handful of lines that could
 * be shared.
 *
 * @package modules
 * @subpackage dolibarr_context
 */

if (!defined('DEBUG_MODE')) { die(); }

/**
 * @subpackage dolibarr_context/lib
 */
class Hm_Dolibarr_Context {

    /** @var string Endpoint URL, injected via .env at build time */
    private $url;
    /** @var string Shared HMAC secret, same one the SSO login uses */
    private $secret;

    public function __construct() {
        /* Hm_Environment::get() rather than the env() helper, for the same
         * reason documented in the generated modules/site/lib.php. */
        $this->url = Hm_Environment::get('DOLIBARR_CONTEXT_URL', '');
        $this->secret = Hm_Environment::get('SSO_SHARED_SECRET', '');
    }

    /**
     * @return bool true if this source has everything it needs to run
     */
    public function configured() {
        return $this->url !== '' && $this->secret !== '';
    }

    /**
     * Seconds a fetched card stays good for. Short by design: an unpaid
     * invoice being settled while a mailbox is open is exactly the kind of
     * change the panel exists to reflect.
     *
     * @return int
     */
    public function ttl() {
        return (int) Hm_Environment::get('DOLIBARR_CONTEXT_TTL', 120);
    }

    /**
     * How many addresses to keep cached in the session at once. Reading down a
     * folder would otherwise grow the session file by one card per message.
     *
     * @return int
     */
    public function cache_limit() {
        $limit = (int) Hm_Environment::get('DOLIBARR_CONTEXT_CACHE', 20);
        return $limit > 0 ? $limit : 20;
    }

    /**
     * Fetch the Dolibarr card for one email address.
     *
     * @param string $login Dolibarr username, as put in the session by SSO
     * @param string $email Address from the message's From header
     * @return array|false Decoded payload, or false on any transport or
     *                     protocol failure
     */
    public function fetch($login, $email) {
        if (!$this->configured() || $email === '') {
            return false;
        }

        $timestamp = time();
        /* The '|context' tag is what stops a contacts token being replayed
         * against this endpoint; bridge/context.php checks for it. */
        $signature = hash_hmac('sha256', $login.'|'.$timestamp.'|context', $this->secret);

        $url = $this->url.(strpos($this->url, '?') === false ? '?' : '&');
        $url .= http_build_query(array(
            'login' => $login,
            'email' => $email,
            'token' => $timestamp.'.'.$signature,
        ));

        $body = $this->request($url);
        if ($body === false) {
            return false;
        }

        $data = json_decode($body, true);
        /* 'match' is the one key every answer carries, including the answer
         * that nobody in Dolibarr owns this address, where it is null. Testing
         * for the key rather than a truthy value keeps that case a success. */
        if (!is_array($data) || !array_key_exists('match', $data)) {
            Hm_Debug::add('dolibarr_context: unexpected response: '.substr($body, 0, 200));
            return false;
        }

        return array(
            'match' => is_array($data['match']) ? $data['match'] : null,
            'thirdparty' => (array_key_exists('thirdparty', $data) && is_array($data['thirdparty']))
                ? $data['thirdparty'] : null,
            'blocks' => (array_key_exists('blocks', $data) && is_array($data['blocks']))
                ? $data['blocks'] : array(),
        );
    }

    /**
     * @param string $url Fully built, signed request URL
     * @return string|false Response body, or false on transport failure
     */
    private function request($url) {
        $timeout = (int) Hm_Environment::get('DOLIBARR_CONTEXT_TIMEOUT', 5);

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
            /* The token is short lived but still a bearer credential, so
             * never follow a redirect that could carry it off-host. */
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
            if (Hm_Environment::get('DOLIBARR_CONTEXT_INSECURE', 'false') === 'true') {
                /* For local XAMPP setups serving Dolibarr over a self-signed
                 * certificate. Off by default. */
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            }
            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);

            if ($body === false || $status !== 200) {
                Hm_Debug::add('dolibarr_context: HTTP '.$status.' '.$err);
                return false;
            }
            return $body;
        }

        $context = stream_context_create(array('http' => array(
            'timeout' => $timeout,
            'follow_location' => 0,
            'ignore_errors' => true,
        )));
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            Hm_Debug::add('dolibarr_context: request failed, no curl and file_get_contents returned false');
            return false;
        }
        return $body;
    }
}
