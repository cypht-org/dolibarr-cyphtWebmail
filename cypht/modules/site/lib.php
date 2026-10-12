<?php

/**
 * Custom session, auth and user config classes for Cypht.
 *
 * @package modules
 * @subpackage site
 */
class Custom_Session extends Hm_Session {

    use Hm_Session_Auth;

    /** @var bool true when resuming an existing session */
    private $existing = false;

    private function session_dir() {
        $base = dirname(rtrim(Hm_Environment::get('USER_SETTINGS_DIR', sys_get_temp_dir()), '/\\'));
        $dir = $base.'/sso_sessions';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        return $dir;
    }

    private function session_file($key) {
        return $this->session_dir().'/'.preg_replace('/[^a-f0-9]/', '', (string) $key).'.session';
    }

    /**
     * Debug log for session problems. Only enable SESSION_DEBUG while
     * debugging, the file grows quickly.
     */
    private function dbg($line) {
        if (Hm_Environment::get('SESSION_DEBUG', 'false') !== 'true') {
            return;
        }
        $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '?';
        $method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '?';
        $logPath = dirname(rtrim(APP_PATH, '/\\'), 3) . '/session_debug.log';
        @file_put_contents(
            $logPath,
            sprintf("[%s] pid=%d %s %s key=%s :: %s\n", date('H:i:s.u'), getmypid(), $method, $uri, substr((string) $this->session_key, 0, 8), $line),
            FILE_APPEND
        );
    }

    /**
     * Delete abandoned session files.
     *
     * @return void
     */
    private function gc() {
        $divisor = (int) Hm_Environment::get('SESSION_GC_DIVISOR', 200);
        if ($divisor < 1 || random_int(1, $divisor) !== 1) {
            return;
        }

        $ttl = (int) Hm_Environment::get('SESSION_TTL', 604800);
        if ($ttl < 3600) {
            $ttl = 3600;
        }

        $cutoff = time() - $ttl;
        $files = @glob($this->session_dir().'/*.session');
        if (!is_array($files)) {
            return;
        }

        foreach ($files as $file) {
            $mtime = @filemtime($file);
            if ($mtime !== false && $mtime < $cutoff) {
                @unlink($file);
            }
        }
    }

    public function check($request, $user = false, $pass = false, $fingerprint = true) {
        if ($user !== false && $pass !== false) {
            if ($this->auth($user, $pass)) {
                $this->set_key($request);
                $this->session_key = bin2hex(random_bytes(16));
                $this->loaded = true;
                $this->data = [];
                $this->active = true;
                $this->dbg('NEW LOGIN established');

                $this->gc();
                if ($fingerprint) {
                    $this->set_fingerprint($request);
                } else {
                    $this->set('fingerprint', '');
                }
                $this->save_auth_detail();
                $this->just_started();
            } else {
                $this->dbg('auth() returned false for new login attempt');
            }
        } elseif (array_key_exists($this->cname, $request->cookie)) {
            $this->session_key = $request->cookie[$this->cname];
            $this->dbg('checking existing session cookie');
            $this->get_key($request);
            $this->existing = true;
            $this->start($request, true);
            if ($this->active) {
                $this->check_fingerprint($request);
                $this->dbg('active after fingerprint check = '.($this->active ? 'yes' : 'no'));
            }
        } else {
            $this->dbg('no cookie, no user/pass; anonymous request');
        }
        return $this->is_active();
    }

    public function start($request, $existing_session = false) {
        if (!$existing_session) {
            return;
        }
        $file = $this->session_file($this->session_key);
        $this->dbg('start(): about to check is_readable on '.$file);
        if (!is_readable($file)) {
            $this->active = false;
            $this->dbg('start(): file not readable, marking inactive');
            return;
        }

        $fh = @fopen($file, 'rb');
        if ($fh === false) {
            $this->active = false;
            $this->dbg('start(): fopen failed');
            return;
        }
        $this->dbg('start(): fopen ok, requesting LOCK_SH');
        flock($fh, LOCK_SH);
        $this->dbg('start(): LOCK_SH acquired, reading');
        $raw = stream_get_contents($fh);
        flock($fh, LOCK_UN);
        fclose($fh);
        $this->dbg('start(): read '.strlen($raw).' bytes, unlocked');

        $data = $this->plaintext($raw);
        if (is_array($data)) {
            $this->data = $data;
            $this->active = true;
            $this->dbg('start(): decrypt OK, session active');
        } else {
            $this->active = false;
            $this->dbg('start(): decrypt FAILED (raw len '.strlen($raw).'), session inactive');
        }
    }

    public function get($name, $default = false, $user = false) {
        if ($user) {
            return (array_key_exists('user_data', $this->data) && array_key_exists($name, $this->data['user_data']))
                ? $this->data['user_data'][$name] : $default;
        }
        return array_key_exists($name, $this->data) ? $this->data[$name] : $default;
    }

    public function set($name, $value, $user = false) {
        if ($user) {
            $this->data['user_data'][$name] = $value;
        } else {
            $this->data[$name] = $value;
        }
    }

    public function del($name) {
        if (array_key_exists($name, $this->data)) {
            unset($this->data[$name]);
            return true;
        }
        return false;
    }

    public function end() {
        if ($this->active && !$this->session_closed) {
            $this->dbg('end(): persisting via write_locked()');
            $this->write_locked();
        }
        $this->active = false;
    }

    /**
     * Save and close the session early, before a slow operation.
     */
    public function close_early() {
        if ($this->active && !$this->session_closed) {
            $this->dbg('close_early(): persisting via write_locked()');
            $this->write_locked();
        }
        $this->session_closed = true;
    }

    private function write_locked() {
        $fh = @fopen($this->session_file($this->session_key), 'cb');
        if ($fh !== false) {
            flock($fh, LOCK_EX);
            $this->dbg('write_locked(): LOCK_EX acquired, writing');
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, $this->ciphertext($this->data));
            fflush($fh);
            flock($fh, LOCK_UN);
            fclose($fh);
            $this->dbg('write_locked(): write complete, unlocked');
        } else {
            $this->dbg('write_locked(): fopen failed');
        }
    }

    public function destroy($request) {
        @unlink($this->session_file($this->session_key));
        $this->delete_cookie($request, $this->cname);
        $this->delete_cookie($request, 'hm_id');
        $this->active = false;
    }
}

class Custom_Auth extends Hm_Auth_DB {

    /**
     * @param string $user Dolibarr login
     * @param string $pass "{timestamp}.{hmac}" token from
     *                      CyphtToken::generateSsoLoginToken()
     * @return bool true if the token is valid and not expired
     */
    public function check_credentials($user, $pass) {
        $secret = Hm_Environment::get('SSO_SHARED_SECRET', '');
        if ($secret === '' || strpos($pass, '.') === false) {
            return false;
        }

        list($timestamp, $signature) = explode('.', $pass, 2);
        if (!ctype_digit($timestamp)) {
            return false;
        }

        if (abs(time() - (int) $timestamp) > 60) {
            return false;
        }

        $expected = hash_hmac('sha256', $user.'|'.$timestamp, $secret);

        return hash_equals($expected, $signature);
    }
}

/**
 * Stores user settings in llx_cyphtwebmail_userconfig
 * (USER_CONFIG_TYPE=custom:Custom_User_Config).
 *
 * @package modules
 * @subpackage site
 */
class Custom_User_Config extends Hm_Config {

    /** @var object Site config */
    private $site_config;

    /** @var string Current username */
    private $username;

    /** @var bool Changed since the last save */
    private $dirty = false;

    /** @var bool Shutdown save already registered */
    private $flush_registered = false;

    /** @var object|null Database handle */
    private $dbh = null;

    /** @var int|null Dolibarr user id */
    private $fk_user = null;

    /** @var int Dolibarr entity of the user */
    private $entity = 1;

    /** @var bool Loaded from an old settings file, delete it after saving */
    private $migrated_from_file = false;

    public function __construct($config) {
        $this->site_config = $config;
        $this->config = array_merge($this->config, $config->user_defaults);
    }

    /**
     * Old settings file of a user (before settings moved to the database).
     *
     * @param string $username
     * @return string
     */
    private function get_path($username) {
        $dir = rtrim((string) $this->site_config->get('user_settings_dir', false), '/\\');
        $safe = preg_replace('/[^a-zA-Z0-9_.@-]/', '_', (string) $username);
        $safe = substr($safe, 0, 64);
        $fingerprint = substr(hash('sha256', (string) $username), 0, 12);

        return $dir.'/'.$safe.'-'.$fingerprint.'.json';
    }

    /**
     * Even older file name, without the hash.
     *
     * @param string $username
     * @return string
     */
    private function get_legacy_path($username) {
        $dir = rtrim((string) $this->site_config->get('user_settings_dir', false), '/\\');
        $safe = preg_replace('/[^a-zA-Z0-9_.@-]/', '_', (string) $username);

        return $dir.'/'.$safe.'.json';
    }

    /**
     * Load the user's settings from the database, or from an old file.
     *
     * @param string $username username
     * @param string $key not used
     */
    public function load($username, $key = null) {
        $this->username = $username;

        $str_data = $this->db_load($username);

        if ($str_data === false) {
            $str_data = $this->file_load($username);
            if ($str_data !== false) {
                $this->migrated_from_file = true;
                $this->dirty = true;
            }
        }

        if ($str_data === false || $str_data === '') {
            return;
        }

        $data = $this->decode($str_data);
        if (is_array($data)) {
            $this->config = array_merge($this->config, $this->decrypt_passwords($data));
            $this->set_tz();
        }
    }

    /**
     * Connection to the Dolibarr database, using Cypht's DB_* settings.
     *
     * @return object|false
     */
    private function db() {
        if ($this->dbh === null) {
            $this->dbh = Hm_DB::connect($this->site_config);
            if (!$this->dbh) {
                Hm_Debug::add('cyphtWebmail: could not connect to the Dolibarr database');
            }
        }

        return $this->dbh;
    }

    /**
     * Dolibarr table prefix.
     *
     * @return string
     */
    private function prefix() {
        $prefix = (string) Hm_Environment::get('DOLIBARR_DB_PREFIX', 'llx_');

        // Used directly in SQL, so keep only safe characters.
        return preg_replace('/[^a-zA-Z0-9_]/', '', $prefix);
    }

    /**
     * Get the Dolibarr user id and entity for a login.
     *
     * @param string $username
     * @return array|false [fk_user, entity]
     */
    private function resolve_user($username) {
        if ($this->fk_user !== null) {
            return array($this->fk_user, $this->entity);
        }
        $dbh = $this->db();
        if (!$dbh) {
            return false;
        }

        $row = Hm_DB::execute(
            $dbh,
            'select rowid, entity from '.$this->prefix().'user where login = ?',
            array($username),
            'select'
        );
        if (!is_array($row) || empty($row['rowid'])) {
            Hm_Debug::add('cyphtWebmail: no Dolibarr user matches login '.$username);
            return false;
        }

        $this->fk_user = (int) $row['rowid'];
        $this->entity = (int) (empty($row['entity']) ? 1 : $row['entity']);

        return array($this->fk_user, $this->entity);
    }

    /**
     * @param string $username
     * @return string|false Decrypted JSON, or false when there is no row
     */
    private function db_load($username) {
        $ids = $this->resolve_user($username);
        if ($ids === false) {
            return false;
        }
        list($fkUser, $entity) = $ids;

        $row = Hm_DB::execute(
            $this->db(),
            'select config from '.$this->prefix().'cyphtwebmail_userconfig where fk_user = ? and entity = ?',
            array($fkUser, $entity),
            'select'
        );
        if (!is_array($row) || !isset($row['config']) || $row['config'] === '') {
            return false;
        }

        return $this->decrypt_payload($row['config'], $username);
    }

    /**
     * Read an old settings file, if there is one.
     *
     * @param string $username
     * @return string|false
     */
    private function file_load($username) {
        foreach (array($this->get_path($username), $this->get_legacy_path($username)) as $source) {
            if (!is_readable($source)) {
                continue;
            }
            $raw = file_get_contents($source);
            if ($raw === false || $raw === '') {
                continue;
            }
            Hm_Debug::add('cyphtWebmail: migrating settings out of '.$source);

            return $this->decrypt_payload($raw, $username);
        }

        return false;
    }

    /**
     * Return the settings as JSON. Older versions encrypted the whole thing.
     *
     * @param string $raw Stored value
     * @param string $username
     * @return string JSON
     */
    private function decrypt_payload($raw, $username) {
        if (substr(ltrim($raw), 0, 1) === '{') {
            return $raw;
        }

        $secret = $this->config_secret();
        if ($secret === '') {
            return $raw;
        }

        $decrypted = Hm_Crypt::plaintext($raw, $secret);
        if ($decrypted !== false) {
            Hm_Debug::add('cyphtWebmail: converting whole-blob encrypted config for '.$username);
            return $decrypted;
        }

        Hm_Debug::add('cyphtWebmail: config for '.$username.' is neither JSON nor decryptable');

        return $raw;
    }

    /**
     * Prefix of encrypted passwords.
     */
    const PASS_PREFIX = 'enc:v1:';

    /**
     * Encrypt the mailbox passwords. The rest stays as plain JSON.
     *
     * @param array $config
     * @return array
     */
    private function encrypt_passwords($config) {
        $secret = $this->config_secret();
        if ($secret === '') {
            return $config;
        }
        foreach (array('imap_servers', 'smtp_servers', 'pop3_servers') as $key) {
            if (empty($config[$key]) || !is_array($config[$key])) {
                continue;
            }
            foreach ($config[$key] as $index => $server) {
                if (!is_array($server) || empty($server['pass'])) {
                    continue;
                }
                if (strpos($server['pass'], self::PASS_PREFIX) === 0) {
                    continue; // already encrypted
                }
                $config[$key][$index]['pass'] = self::PASS_PREFIX.Hm_Crypt::ciphertext($server['pass'], $secret);
            }
        }

        return $config;
    }

    /**
     * Decrypt the mailbox passwords.
     *
     * @param array $config
     * @return array
     */
    private function decrypt_passwords($config) {
        $secret = $this->config_secret();
        if ($secret === '') {
            return $config;
        }
        foreach (array('imap_servers', 'smtp_servers', 'pop3_servers') as $key) {
            if (empty($config[$key]) || !is_array($config[$key])) {
                continue;
            }
            foreach ($config[$key] as $index => $server) {
                if (!is_array($server) || empty($server['pass'])) {
                    continue;
                }
                if (strpos($server['pass'], self::PASS_PREFIX) !== 0) {
                    continue;
                }
                $plain = Hm_Crypt::plaintext(substr($server['pass'], strlen(self::PASS_PREFIX)), $secret);
                // If decryption fails, clear it so Cypht asks for the password again.
                $config[$key][$index]['pass'] = ($plain === false ? '' : $plain);
            }
        }

        return $config;
    }

    /**
     * Delete the old settings file after the settings were saved to the database.
     *
     * @param string $username
     * @return void
     */
    private function migrate_file_away($username) {
        if (!$this->migrated_from_file) {
            return;
        }
        foreach (array($this->get_path($username), $this->get_legacy_path($username)) as $path) {
            if (file_exists($path) && @unlink($path)) {
                Hm_Debug::add('cyphtWebmail: removed migrated settings file '.$path);
            }
        }
        $this->migrated_from_file = false;
    }


    /**
     * Key used to encrypt passwords. If empty, passwords are stored unencrypted.
     *
     * @return string
     */
    private function config_secret() {
        return (string) Hm_Environment::get('USER_CONFIG_SECRET', '');
    }

    /**
     * Load the session data and save it, like Tiki_Hm_User_Config::reload().
     *
     * @param array $data new user data
     * @param string|false $username
     */
    public function reload($data, $username = false) {
        $this->username = $username;
        $this->config = $data;
        $this->set_tz();

        if (!$username) {
            return;
        }

        $this->dirty = true;
        if (!$this->flush_registered) {
            $this->flush_registered = true;
            register_shutdown_function(array($this, 'flush_pending'));
        }
    }



    /**
     * Config as a string to compare, minus the per-request values.
     *
     * @param array $config
     * @return string
     */
    private function comparable($config) {
        unset($config['updated_at']);
        foreach (array('pop3_servers', 'imap_servers', 'smtp_servers') as $key) {
            if (empty($config[$key]) || !is_array($config[$key])) {
                continue;
            }
            foreach ($config[$key] as $index => $server) {
                if (is_array($server)) {
                    unset($config[$key][$index]['object'], $config[$key][$index]['connected']);
                }
            }
        }
        ksort($config);

        return json_encode($config);
    }

    /**
     * Set a value. It is saved at the end of the request.
     *
     * @param string $name config value name
     * @param mixed $value config value
     */
    public function set($name, $value) {
        $this->config[$name] = $value;

        if (!$this->username) {
            return;
        }

        $this->dirty = true;
        if (!$this->flush_registered) {
            $this->flush_registered = true;
            register_shutdown_function(array($this, 'flush_pending'));
        }
    }

    /**
     * Save at the end of the request if something changed. Public because
     * register_shutdown_function() calls it.
     */
    public function flush_pending() {
        if (!$this->dirty || !$this->username) {
            return;
        }
        $this->dirty = false;

        ob_start();
        try {
            $raw = $this->db_load($this->username);
            $existing = ($raw === false || $raw === '') ? array() : $this->decode($raw);
            if (!is_array($existing)) {
                $existing = array();
            }
            $existing = $this->decrypt_passwords($existing);

            if ($this->comparable($existing) === $this->comparable($this->config)) {
                ob_end_clean();
                return;
            }

            if (!empty($existing['updated_at']) && !empty($this->config['updated_at'])
                && $existing['updated_at'] > $this->config['updated_at']) {
                ob_end_clean();
                return;
            }

            $this->save($this->username);
        } catch (Exception $e) {
            Hm_Debug::add('cyphtWebmail: deferred save failed: '.$e->getMessage());
        } catch (Throwable $e) {
            Hm_Debug::add('cyphtWebmail: deferred save failed: '.$e->getMessage());
        }
        ob_end_clean();
    }

    /**
     * @param string $username username
     * @param string $key not used
     */
    public function save($username, $key = null) {
        $this->dirty = false;
        $this->shuffle();

        $removed = $this->filter_servers();

        $this->config['updated_at'] = microtime(true);
        ksort($this->config);

        $payload = json_encode($this->encrypt_passwords($this->config));

        if ($this->db_save($username, $payload)) {
            $this->migrate_file_away($username);
        }

        $this->restore_servers($removed);
    }

    /**
     * Update or insert the user's config row.
     *
     * @param string $username
     * @param string $payload Config JSON
     * @return bool
     */
    private function db_save($username, $payload) {
        $ids = $this->resolve_user($username);
        if ($ids === false) {
            return false;
        }
        list($fkUser, $entity) = $ids;

        $dbh = $this->db();
        if (!$dbh) {
            return false;
        }

        $cyphtVersion = Hm_Environment::get('CYPHT_VERSION', '');
        $cyphtVersion = ($cyphtVersion !== '') ? (string) $cyphtVersion : null;

        $updated = Hm_DB::execute(
            $dbh,
            'update '.$this->prefix().'cyphtwebmail_userconfig set config = ?, cypht_version = ? where fk_user = ? and entity = ?',
            array($payload, $cyphtVersion, $fkUser, $entity),
            'modify'
        );

        if ($updated === false) {
            return false;
        }
        if ((int) $updated > 0) {
            return true;
        }

        $inserted = Hm_DB::execute(
            $dbh,
            'insert into '.$this->prefix().'cyphtwebmail_userconfig (entity, fk_user, config, cypht_version, date_creation) values (?, ?, ?, ?, ?)',
            array($entity, $fkUser, $payload, $cyphtVersion, date('Y-m-d H:i:s')),
            'insert'
        );

        return $inserted !== false;
    }

    /**
     * Clear the connection state ('object', 'connected') of each server
     * before saving (like Tiki_Hm_User_Config::filter_servers).
     *
     * @return array
     */
    public function filter_servers() {
        foreach ($this->config as $key => $vals) {
            if (in_array($key, array('pop3_servers', 'imap_servers', 'smtp_servers'), true) && is_array($vals)) {
                foreach ($vals as $index => $server) {
                    if (is_array($server)) {
                        $this->config[$key][$index]['object'] = false;
                        $this->config[$key][$index]['connected'] = false;
                    }
                }
            }
        }

        return parent::filter_servers();
    }
}

/**
 * Reads the cache files Dolibarr writes in DOLIBARR_CACHE_DIR.
 *
 * @subpackage site/lib
 */
class Hm_Dolibarr_Cache {

    /**
     * @param string $kind   'contacts', 'mail-templates'
     * @param string $login  Dolibarr login
     * @param string $expect Key the payload must carry
     * @return array|false
     */
    public static function read($kind, $login, $expect) {
        $dir = Hm_Environment::get('DOLIBARR_CACHE_DIR', '');
        $root = Hm_Environment::get('DOLIBARR_MODULE_ROOT', '');
        if ($dir === '' || $root === '') {
            return false;
        }

        $naming = $root.'/class/integration/cachefiles.class.php';
        if (!is_readable($naming)) {
            Hm_Debug::add('dolibarr cache: cannot load '.$naming);
            return false;
        }
        require_once $naming;

        $data = CyphtCacheFiles::read($dir, $login, $kind, $expect);
        if ($data === false) {
            Hm_Debug::add('dolibarr cache: no usable '.$kind.' file for '.$login);
        }

        return $data;
    }
}
