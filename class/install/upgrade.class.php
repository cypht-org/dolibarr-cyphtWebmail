<?php
/* Copyright (C) 2026  Camile   <camilevahviraki@gmail.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published
 * by the Free Software Foundation; either version 2.1 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

require_once __DIR__ . '/paths.class.php';
require_once __DIR__ . '/config.class.php';
require_once __DIR__ . '/../auth/token.class.php';

/**
 * \file        class/install/upgrade.class.php
 * \ingroup     cyphtwebmail
 * \brief       Upgrades the database to the current SCHEMA_VERSION.
 */
class CyphtUpgrade
{
	/**
	 * Increase when adding a migration file or something to provision().
	 */
	const SCHEMA_VERSION = 4;

	/**
	 * @var string Last error message
	 */
	public $error = '';

	/**
	 * @var DoliDB
	 */
	private $db;

	/**
	 * @param DoliDB $db
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Run pending migrations. Cheap when up to date (one query).
	 *
	 * @param bool $force Run even if up to date (used on activation)
	 * @return bool
	 */
	public function run($force = false)
	{
		$stored = (int) CyphtConfig::get($this->db, 'SCHEMA_VERSION', '0');

		if ($stored === self::SCHEMA_VERSION && !$force) {
			return true;
		}

		if ($stored > self::SCHEMA_VERSION) {
			$this->error = 'This installation is at schema version ' . $stored
				. ' but the installed code expects ' . self::SCHEMA_VERSION
				. '. The module files appear to be older than the database.';

			return false;
		}

		if (!$this->applyMigrations($stored)) {
			return false;
		}

		if (!$this->provision()) {
			return false;
		}

		CyphtConfig::set($this->db, 'SCHEMA_VERSION', (string) self::SCHEMA_VERSION);

		return true;
	}

	/**
	 * Run sql/migrations/NNN_*.sql in order, skipping those already applied.
	 * run_sql() handles the table prefix.
	 *
	 * @param int $from Version already applied
	 * @return bool
	 */
	private function applyMigrations($from)
	{
		global $conf;

		$paths = new CyphtPaths();
		$dir = $paths->getModuleRoot() . '/sql/migrations';
		if (!is_dir($dir)) {
			return true;
		}

		$files = glob($dir . '/*.sql');
		if ($files === false || !count($files)) {
			return true;
		}
		sort($files, SORT_STRING);

		require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';

		foreach ($files as $file) {
			if (!preg_match('/^0*([0-9]+)_/', basename($file), $m)) {
				$this->error = 'Migration file is not numbered: ' . basename($file);
				return false;
			}

			$version = (int) $m[1];
			if ($version <= $from || $version > self::SCHEMA_VERSION) {
				continue;
			}

			// 'default' ignores "already exists" errors, so it is safe to re-run.
			$result = run_sql($file, 1, $conf->entity, 1, '', 'default');
			if ($result <= 0) {
				$this->error = 'Migration failed: ' . basename($file);
				return false;
			}
		}

		return true;
	}

	/**
	 * Create the secrets and data directory for this install.
	 * Existing values are kept.
	 *
	 * @return bool
	 */
	private function provision()
	{
		try {
			$token = new CyphtToken($this->db);
			$token->getOrCreateSsoSecret();
			$token->getOrCreateConfigSecret();
			$token->getOrCreateSiteId();

			$paths = new CyphtPaths();
			$paths->getDataDir();
		} catch (Exception $e) {
			$this->error = 'Could not provision this installation: ' . $e->getMessage();

			return false;
		}

		return true;
	}
}
