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

require_once __DIR__ . '/paths.class.php';
require_once __DIR__ . '/config.class.php';
require_once __DIR__ . '/../auth/token.class.php';

/**
 * \file        class/install/upgrade.class.php
 * \ingroup     cyphtwebmail
 * \brief       Bring an installation up to the schema the code expects.
 *
 *              DolibarrModules has no upgrade hook, so replacing the files
 *              runs new code against the old database with nothing called.
 *              This compares a stored version against SCHEMA_VERSION and is
 *              its own trigger. Activation calls it too, so install and
 *              upgrade share one path.
 */
class CyphtUpgrade
{
	/**
	 * Bump when a migration file is added, or when provision() gains
	 * something an existing installation would otherwise miss.
	 *
	 * 1: config table, secrets and site id moved out of llx_const.
	 * 2: cypht_version recorded on each stored user config.
	 */
	const SCHEMA_VERSION = 2;

	/**
	 * @var string Last failure.
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
	 * Fast path is one indexed read, so this is cheap enough to sit on a page
	 * load.
	 *
	 * @param bool $force Run even when the versions agree, used by activation
	 * @return bool
	 */
	public function run($force = false)
	{
		$stored = (int) CyphtConfig::get($this->db, 'SCHEMA_VERSION', '0');

		if ($stored === self::SCHEMA_VERSION && !$force) {
			return true;
		}

		if ($stored > self::SCHEMA_VERSION) {
			/* Migrations are forward only, so there is nothing safe to run. */
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
	 * Apply sql/migrations/NNN_*.sql in order, skipping any already recorded.
	 *
	 * run_sql() rather than hand written queries: it rewrites the llx_ prefix
	 * to whatever this installation uses.
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
				/* Refuse rather than guess an order. */
				$this->error = 'Migration file is not numbered: ' . basename($file);
				return false;
			}

			$version = (int) $m[1];
			if ($version <= $from || $version > self::SCHEMA_VERSION) {
				continue;
			}

			// 'default' lets "already exists" pass, so re-running is harmless.
			$result = run_sql($file, 1, $conf->entity, 1, '', 'default');
			if ($result <= 0) {
				$this->error = 'Migration failed: ' . basename($file);
				return false;
			}
		}

		return true;
	}

	/**
	 * Create the per-installation state a shipped build cannot carry.
	 *
	 * Idempotent: every getOrCreate returns what already exists, which matters
	 * most for USER_CONFIG_SECRET.
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
