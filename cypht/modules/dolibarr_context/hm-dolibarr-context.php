<?php

/**
 * Clients for bridge/context.php (read) and bridge/create.php (create).
 * @package modules
 * @subpackage dolibarr_context
 */

if (!defined('DEBUG_MODE')) { die(); }

/**
 * Gets the Dolibarr records for a sender address.
 * @subpackage dolibarr_context/lib
 */
class Hm_Dolibarr_Context {

    /**
     *  @var string Bridge URL
     */
    private $url;
    /**
     *  @var string Shared HMAC secret (same as SSO)
     */
    private $secret;
    /**
     *  @var int HTTP status of the last request
     */
    private $last_status = 0;

    public function __construct() {
        $this->url = Hm_Environment::get('DOLIBARR_CONTEXT_URL', '');
        $this->secret = Hm_Environment::get('SSO_SHARED_SECRET', '');
    }

    /**
     * @return bool true if the URL and secret are set
     */
    public function configured() {
        return $this->url !== '' && $this->secret !== '';
    }

    /**
     * How long a result is cached, in seconds.
     * @return int
     */
    public function ttl() {
        return (int) Hm_Environment::get('DOLIBARR_CONTEXT_TTL', 120);
    }

    /**
     * Max number of addresses cached in the session.
     * @return int
     */
    public function cache_limit() {
        $limit = (int) Hm_Environment::get('DOLIBARR_CONTEXT_CACHE', 20);
        return $limit > 0 ? $limit : 20;
    }

    /**
     * True if the last request was refused (403).
     * @return bool
     */
    public function forbidden() {
        return $this->last_status === 403;
    }

    /**
     * Get the Dolibarr records for an email address.
     * @param string $login Dolibarr login
     * @param string $email Sender address
     * @return array|false Data, or false on error
     */
    public function fetch($login, $email) {
        if (!$this->configured() || $email === '') {
            return false;
        }

        $timestamp = time();
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
            'can_create' => !empty($data['can_create']),
        );
    }

    /**
     * @param string $url Signed request URL
     * @return string|false Response body, or false on failure
     */
    private function request($url) {
        $timeout = (int) Hm_Environment::get('DOLIBARR_CONTEXT_TIMEOUT', 5);

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
            if (Hm_Environment::get('DOLIBARR_CONTEXT_INSECURE', 'false') === 'true') {
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            }
            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);

            $this->last_status = $status;

            if ($body === false || $status !== 200) {
                Hm_Debug::add('dolibarr_context: HTTP '.$status.' '.$err);
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
            Hm_Debug::add('dolibarr_context: request failed, no curl and file_get_contents returned false');
            return false;
        }
        return $body;
    }
}

/**
 * Creates a Dolibarr prospect from an email address.
 * @subpackage dolibarr_context/lib
 */
class Hm_Dolibarr_Context_Create {

    /**
     *  @var string Bridge URL
     */
    private $url;
    /**
     *  @var string Shared HMAC secret (same as SSO)
     */
    private $secret;

    public function __construct() {
        $this->url = Hm_Environment::get('DOLIBARR_CONTEXT_CREATE_URL', '');
        $this->secret = Hm_Environment::get('SSO_SHARED_SECRET', '');
    }

    /**
     * @return bool true if the URL and secret are set
     */
    public function configured() {
        return $this->url !== '' && $this->secret !== '';
    }

    /**
     * Create a prospect for an address.
     * @param string $login Dolibarr login
     * @param string $email Sender address
     * @param string $name  Sender name, can be empty
     * @return array|false Response data, or false on failure
     */
    public function create($login, $email, $name) {
        if (!$this->configured() || $email === '') {
            return false;
        }

        $timestamp = time();
        $signature = hash_hmac('sha256', $login.'|'.$timestamp.'|create', $this->secret);

        $fields = array(
            'login' => $login,
            'email' => $email,
            'name' => $name,
            'token' => $timestamp.'.'.$signature,
        );

        $body = $this->post($this->url, $fields);
        if ($body === false) {
            return false;
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            Hm_Debug::add('dolibarr_context_create: unexpected response: '.substr($body, 0, 200));
            return false;
        }

        return $data;
    }

    /**
     * @param string $url    Endpoint URL
     * @param array  $fields Form fields, including the token
     * @return string|false Response body, or false on failure
     */
    private function post($url, $fields) {
        $timeout = (int) Hm_Environment::get('DOLIBARR_CONTEXT_TIMEOUT', 5);

        if (!function_exists('curl_init')) {
            Hm_Debug::add('dolibarr_context_create: curl is required to create records');
            return false;
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        /* For sites with HTTP auth in front of Dolibarr. */
        $proxyAuth = Hm_Environment::get('DOLIBARR_BRIDGE_HTTP_AUTH', '');
        if ($proxyAuth !== '') {
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($ch, CURLOPT_USERPWD, $proxyAuth);
        }
        if (Hm_Environment::get('DOLIBARR_CONTEXT_INSECURE', 'false') === 'true') {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            Hm_Debug::add('dolibarr_context_create: transport failed: '.$err);
            return false;
        }
        if ($status !== 200) {
            /* Still return the body, it contains Dolibarr's error message. */
            Hm_Debug::add('dolibarr_context_create: HTTP '.$status.' '.substr($body, 0, 200));
        }
        return $body;
    }
}
