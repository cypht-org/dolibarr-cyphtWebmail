<?php
/* Copyright (C) 2026  Camile   <camilevahviraki@gmail.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file        class/runtime/envbootstrap.class.php
 * \ingroup     cyphtwebmail
 * \brief       Supplies Cypht's per-installation configuration at runtime,
 *              without writing anything to disk.
 *
 *              A shipped build carries only the settings that are the same
 *              everywhere. Everything else, database credentials, generated
 *              secrets, data paths and bridge URLs, is discovered on each
 *              request: the credentials and paths from Dolibarr's own
 *              conf.php, the rest from llx_const.
 *
 *              This exists so an installed module never has to write a file.
 *              Shared hosting routinely denies the webserver write access
 *              inside the web root, and a module that must write its own
 *              configuration to start is a module that cannot be installed
 *              from a zip.
 *
 *              Deliberately plain PHP. It runs before Dolibarr is loaded, so
 *              it cannot use any Dolibarr helper, and before Cypht's
 *              autoloader, so it cannot use any Cypht class.
 */
class CyphtEnvBootstrap
{
	/**
	 * @var string Last failure, for the caller to surface or log.
	 */
	public $error = '';

	/**
	 * @var string Absolute path to the module root.
	 */
	private $moduleRoot;

	/**
	 * @param string $moduleRoot Directory holding public/, class/, vendor/
	 */
	public function __construct($moduleRoot)
	{
		$this->moduleRoot = rtrim($moduleRoot, '/\\');
	}

	/**
	 * Discover everything and put it in $_ENV.
	 *
	 * Hm_Environment::get() reads array_merge($_ENV, $_SERVER) on every call,
	 * so populating $_ENV before Cypht loads its config is equivalent to
	 * having written the values into .env, minus the write.
	 *
	 * Existing values win. A real .env entry is an explicit operator override
	 * and must not be silently replaced by something derived.
	 *
	 * @return bool True if the per-installation values are now available
	 */
	public function apply()
	{
		$conf = $this->readDolibarrConf();
		if ($conf === null) {
			return false;
		}

		$values = $this->fromConf($conf);

		$consts = $this->readConsts($conf);
		if ($consts === null) {
			/* Reaching conf.php but not the database is worth distinguishing:
			 * the first is a layout problem, the second a credentials or
			 * server problem. Both leave $values usable for the paths. */
			$this->error = 'Read Dolibarr conf.php but could not query its database: ' . $this->error;
		} else {
			$values = array_merge($values, $consts);
		}

		foreach ($values as $key => $value) {
			if ($value === null || $value === '') {
				continue;
			}
			if (array_key_exists($key, $_ENV) && $_ENV[$key] !== '') {
				continue;
			}
			$_ENV[$key] = (string) $value;
		}

		return $consts !== null;
	}

	/**
	 * Locate and evaluate Dolibarr's conf.php.
	 *
	 * Included inside a method so its many $dolibarr_* variables stay local
	 * rather than polluting the global scope Cypht is about to use.
	 *
	 * @return array<string,mixed>|null
	 */
	private function readDolibarrConf()
	{
		$path = $this->locateDolibarrConf();
		if ($path === null) {
			$this->error = 'Could not find Dolibarr conf.php from ' . $this->moduleRoot
				. '. Set CYPHTWEBMAIL_DOLIBARR_CONF in the environment to point at it.';
			return null;
		}

		/* conf.php only assigns variables, but include it defensively: a
		 * parse error here must not take down the whole request with a fatal
		 * that says nothing useful. */
		$dolibarr_main_db_type = '';
		$dolibarr_main_db_host = '';
		$dolibarr_main_db_port = '';
		$dolibarr_main_db_name = '';
		$dolibarr_main_db_user = '';
		$dolibarr_main_db_pass = '';
		$dolibarr_main_db_prefix = '';
		$dolibarr_main_data_root = '';
		$dolibarr_main_url_root = '';

		include $path;

		if ($dolibarr_main_db_name === '') {
			$this->error = 'Dolibarr conf.php at ' . $path . ' defines no database name.';
			return null;
		}

		return array(
			'type' => $dolibarr_main_db_type !== '' ? $dolibarr_main_db_type : 'mysqli',
			'host' => $dolibarr_main_db_host,
			'port' => $dolibarr_main_db_port,
			'name' => $dolibarr_main_db_name,
			'user' => $dolibarr_main_db_user,
			'pass' => $dolibarr_main_db_pass,
			'prefix' => $dolibarr_main_db_prefix !== '' ? $dolibarr_main_db_prefix : 'llx_',
			'data_root' => $dolibarr_main_data_root,
			'url_root' => rtrim($dolibarr_main_url_root, '/'),
		);
	}

	/**
	 * Where conf.php lives.
	 *
	 * The normal layout is <dolibarr>/htdocs/custom/<module>, so conf.php is
	 * two directories above the module. The environment override exists for
	 * installations that put the module somewhere else entirely, which the
	 * module already supports for building.
	 *
	 * @return string|null
	 */
	private function locateDolibarrConf()
	{
		$override = getenv('CYPHTWEBMAIL_DOLIBARR_CONF');
		if (is_string($override) && $override !== '' && is_readable($override)) {
			return $override;
		}

		$candidates = array(
			dirname($this->moduleRoot, 2) . '/conf/conf.php',
			dirname($this->moduleRoot, 3) . '/htdocs/conf/conf.php',
			dirname($this->moduleRoot) . '/conf/conf.php',
		);

		foreach ($candidates as $candidate) {
			if (is_readable($candidate)) {
				return $candidate;
			}
		}

		return null;
	}

	/**
	 * The values conf.php alone can answer: how to reach the database, where
	 * Dolibarr keeps its data, and what URL it is served from.
	 *
	 * Deriving the paths rather than storing them is deliberate. A stored
	 * absolute path goes stale the moment the installation is moved or
	 * restored somewhere else, and stale is worse than absent because it
	 * fails later and less clearly.
	 *
	 * @param array<string,mixed> $conf
	 * @return array<string,string>
	 */
	private function fromConf(array $conf)
	{
		$dataDir = rtrim($conf['data_root'], '/\\') . '/cyphtwebmail';
		$moduleUrl = $conf['url_root'] . '/custom/cyphtwebmail';

		return array(
			'DB_DRIVER' => ($conf['type'] === 'pgsql') ? 'pgsql' : 'mysql',
			'DB_HOST' => $conf['host'],
			'DB_PORT' => $conf['port'],
			'DB_NAME' => $conf['name'],
			'DB_USER' => $conf['user'],
			'DB_PASS' => $conf['pass'],
			'DOLIBARR_DB_PREFIX' => $conf['prefix'],
			'USER_SETTINGS_DIR' => $dataDir . '/users',
			'ATTACHMENT_DIR' => $dataDir . '/attachments',
			'DOLIBARR_CONTACTS_URL' => $moduleUrl . '/bridge/contacts.php',
			'DOLIBARR_MAIL_TEMPLATES_URL' => $moduleUrl . '/bridge/mail_templates.php',
			'DOLIBARR_NEW_CONTACT_URL' => $conf['url_root'] . '/contact/card.php?action=create',
		);
	}

	/**
	 * The stored half: generated secrets and whatever the setup page saved.
	 *
	 * These already live in llx_const, written at activation and whenever the
	 * setup form is saved, so there is nothing new to persist and no second
	 * table to keep in step.
	 *
	 * @param array<string,mixed> $conf
	 * @return array<string,string>|null Null if the database was unreachable
	 */
	private function readConsts(array $conf)
	{
		/* Only the keys this module owns, mapped to the names Cypht expects.
		 * An allow list rather than a prefix sweep: llx_const is shared, and
		 * nothing outside this map should be able to reach Cypht's config by
		 * being named suggestively. */
		$map = array(
			'CYPHTWEBMAIL_IMAP_NAME' => 'IMAP_AUTH_NAME',
			'CYPHTWEBMAIL_IMAP_SERVER' => 'IMAP_AUTH_SERVER',
			'CYPHTWEBMAIL_IMAP_PORT' => 'IMAP_AUTH_PORT',
			'CYPHTWEBMAIL_IMAP_TLS' => 'IMAP_AUTH_TLS',
			'CYPHTWEBMAIL_SESSION_DEBUG' => 'SESSION_DEBUG',
			'CYPHTWEBMAIL_SESSION_TTL' => 'SESSION_TTL',
			'CYPHTWEBMAIL_SESSION_GC_DIVISOR' => 'SESSION_GC_DIVISOR',
			'CYPHTWEBMAIL_CONTACTS_TTL' => 'DOLIBARR_CONTACTS_TTL',
			'CYPHTWEBMAIL_CONTACTS_TIMEOUT' => 'DOLIBARR_CONTACTS_TIMEOUT',
			'CYPHTWEBMAIL_CONTACTS_INSECURE' => 'DOLIBARR_CONTACTS_INSECURE',
			'CYPHTWEBMAIL_MAIL_TEMPLATES_TTL' => 'DOLIBARR_MAIL_TEMPLATES_TTL',
			'CYPHTWEBMAIL_MAIL_TEMPLATES_TIMEOUT' => 'DOLIBARR_MAIL_TEMPLATES_TIMEOUT',
			'CYPHTWEBMAIL_MAIL_TEMPLATES_INSECURE' => 'DOLIBARR_MAIL_TEMPLATES_INSECURE',
			'CYPHTWEBMAIL_BRIDGE_URL' => 'DOLIBARR_CONTACTS_URL',
			'CYPHTWEBMAIL_BRIDGE_MAIL_TEMPLATES_URL' => 'DOLIBARR_MAIL_TEMPLATES_URL',
		);

		$pdo = $this->connect($conf);
		if ($pdo === null) {
			return null;
		}

		$table = $conf['prefix'] . 'const';
		$names = array_keys($map);
		$slots = implode(',', array_fill(0, count($names), '?'));

		try {
			/* Ordered by entity so a value scoped to the current entity
			 * overwrites the global one during the loop below. */
			$stmt = $pdo->prepare(
				'SELECT name, value FROM ' . $table . ' WHERE name IN (' . $slots . ') ORDER BY entity ASC'
			);
			$stmt->execute($names);
			$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
		} catch (Exception $e) {
			$this->error = 'Query on ' . $table . ' failed: ' . $e->getMessage();
			return null;
		}

		$out = $this->readModuleConfig($pdo, $conf);

		foreach ($rows as $row) {
			$name = isset($row['name']) ? $row['name'] : '';
			if (!isset($map[$name])) {
				continue;
			}
			$out[$map[$name]] = isset($row['value']) ? (string) $row['value'] : '';
		}

		return $out;
	}

	/**
	 * The module's own configuration table.
	 *
	 * Separate from llx_const because dolibarr_set_const() encrypts the value
	 * of any constant whose name ends in _SECRET, _KEY, _PASS and a few more.
	 * Dolibarr decrypts them again when it builds $conf->global, so its own
	 * code never notices, but this reader is deliberately running before
	 * Dolibarr exists and would get ciphertext. The signed bridge requests
	 * would then be built from a different secret at each end and every one
	 * would come back "Bad signature".
	 *
	 * Names in this table are already Cypht's own, so the rows map straight
	 * across.
	 *
	 * @param PDO $pdo
	 * @param array<string,mixed> $conf
	 * @return array<string,string>
	 */
	private function readModuleConfig(PDO $pdo, array $conf)
	{
		$table = $conf['prefix'] . 'cyphtwebmail_config';

		try {
			$stmt = $pdo->query('SELECT name, value FROM ' . $table . ' ORDER BY entity ASC');
			$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
		} catch (Exception $e) {
			/* Not fatal on its own: an installation that has not been
			 * activated since this table was introduced simply has nothing
			 * here yet, and the caller still gets the paths. */
			$this->error = 'Could not read ' . $table . ': ' . $e->getMessage();
			return array();
		}

		$out = array();
		foreach ($rows as $row) {
			if (!isset($row['name'])) {
				continue;
			}
			$out[(string) $row['name']] = isset($row['value']) ? (string) $row['value'] : '';
		}

		return $out;
	}

	/**
	 * @param array<string,mixed> $conf
	 * @return PDO|null
	 */
	private function connect(array $conf)
	{
		$driver = ($conf['type'] === 'pgsql') ? 'pgsql' : 'mysql';
		$dsn = $driver . ':host=' . $conf['host'];
		if ($conf['port'] !== '' && $conf['port'] !== null) {
			$dsn .= ';port=' . $conf['port'];
		}
		$dsn .= ';dbname=' . $conf['name'];
		if ($driver === 'mysql') {
			$dsn .= ';charset=utf8mb4';
		}

		try {
			return new PDO($dsn, $conf['user'], $conf['pass'], array(
				PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
				PDO::ATTR_TIMEOUT => 5,
			));
		} catch (Exception $e) {
			$this->error = $e->getMessage();
			return null;
		}
	}
}
