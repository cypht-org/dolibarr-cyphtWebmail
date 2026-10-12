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

/**
 * \file        class/install/paths.class.php
 * \ingroup     cyphtWebmail
 * \brief       Paths and version info for the module and the vendored Cypht.
 */
class CyphtPaths
{
	/**
	 * Absolute path to the module root.
	 *
	 * @return string
	 */
	public function getModuleRoot()
	{
		return dirname(__DIR__, 2);
	}

	/**
	 * Absolute path to the vendored Cypht package.
	 *
	 * @return string
	 */
	public function getCyphtPath()
	{
		return $this->getModuleRoot() . '/vendor/jason-munro/cypht';
	}

	/**
	 * Where config_gen.php writes the built site/ (inside the Cypht package).
	 *
	 * @return string
	 */
	public function getCyphtSitePath()
	{
		return $this->getCyphtPath() . '/site';
	}

	/**
	 * The module's public/ directory, where the built site/ is copied.
	 *
	 * @return string
	 */
	public function getPublicPath()
	{
		return $this->getModuleRoot() . '/public';
	}

	/**
	 * The module's data directory in Dolibarr's documents folder. Creates
	 * the users/ and attachments/ subfolders.
	 *
	 * @return string
	 */
	public function getDataDir()
	{
		/* Offline build */
		if (!defined('DOL_DATA_ROOT')) {
			$dir = rtrim(sys_get_temp_dir(), '/\\') . '/cyphtwebmail-build';
			if (!is_dir($dir)) {
				@mkdir($dir, 0775, true);
			}

			return $dir;
		}

		$dir = DOL_DATA_ROOT . '/cyphtwebmail';

		require_once DOL_DOCUMENT_ROOT . '/core/lib/files.lib.php';
		dol_mkdir($dir . '/users');
		dol_mkdir($dir . '/attachments');

		return $dir;
	}

	/**
	 * Read build.json, or null if missing.
	 *
	 * @return array<string,string>|null Keys: module_version, cypht_version, built_at
	 */
	public function getBuildInfo()
	{
		$file = $this->getModuleRoot() . '/build.json';
		if (!is_readable($file)) {
			return null;
		}

		$data = json_decode((string) file_get_contents($file), true);

		return is_array($data) ? $data : null;
	}

	/**
	 * Module version from cyphtwebmail_version.inc.php.
	 *
	 * @return string Empty when it cannot be read
	 */
	public function getModuleVersion()
	{
		return self::readVersionFrom($this->getModuleRoot());
	}

	/**
	 * Read the module version from a given module directory.
	 *
	 * @param string $moduleRoot
	 * @return string Empty when it cannot be read
	 */
	public static function readVersionFrom($moduleRoot)
	{
		$source = (string) @file_get_contents(rtrim($moduleRoot, '/\\') . '/cyphtwebmail_version.inc.php');

		if (preg_match("/define\s*\(\s*'CYPHTWEBMAIL_VERSION'\s*,\s*'([^']+)'/", $source, $m)) {
			return $m[1];
		}

		return '';
	}

	/**
	 * Write build.json at the module root.
	 *
	 * @param string|null $cyphtVersion Defaults to what Composer reports
	 * @return void
	 */
	public function writeBuildInfo($cyphtVersion = null)
	{
		$root = $this->getModuleRoot();

		if ($cyphtVersion === null) {
			$cyphtVersion = $this->getInstalledVersion();
		}

		$info = array(
			'module_version' => $this->getModuleVersion(),
			'cypht_version' => $cyphtVersion,
			'built_at' => gmdate('c'),
		);

		@file_put_contents(
			$root . '/build.json',
			json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
		);
	}

	/**
	 * Installed jason-munro/cypht version, from Composer's installed.json.
	 *
	 * @return string|null  Version string (e.g. "2.11.1") or null if unknown
	 */
	public function getInstalledVersion()
	{
		$installedJson = $this->getModuleRoot() . '/vendor/composer/installed.json';
		if (!file_exists($installedJson)) {
			return null;
		}

		$data = json_decode(file_get_contents($installedJson), true);
		if (!is_array($data)) {
			return null;
		}
		// Composer 2 uses a "packages" key, Composer 1 a flat array.
		$packages = isset($data['packages']) ? $data['packages'] : $data;

		foreach ($packages as $pkg) {
			if (isset($pkg['name']) && $pkg['name'] === 'jason-munro/cypht') {
				return ltrim($pkg['version'], 'v');
			}
		}

		return null;
	}

	/**
	 * Cypht version of the last successful build.
	 *
	 * @return string
	 */
	public function getBuiltVersion()
	{
		return getDolGlobalString('CYPHTWEBMAIL_BUILT_VERSION', '');
	}

	/**
	 * Date of the last successful build, or empty if never built.
	 *
	 * @return string
	 */
	public function getLastBuildDate()
	{
		return getDolGlobalString('CYPHTWEBMAIL_LAST_BUILD', '');
	}

	/**
	 * True if the installed Cypht version differs from the last built one.
	 *
	 * @return bool
	 */
	public function needsRebuild()
	{
		$installed = $this->getInstalledVersion();
		if ($installed === null) {
			return false;
		}
		return ($installed !== $this->getBuiltVersion());
	}

	/**
	 * True if a build has been published.
	 *
	 * @return bool
	 */
	public function isPublished()
	{
		return file_exists($this->getPublicPath() . '/index.php');
	}
	/**
	 * Path of a user's Cypht settings file. Must match Custom_User_Config::get_path().
	 *
	 * @param string $login Dolibarr login
	 * @return string
	 */
	public function getUserSettingsPath($login)
	{
		$dir = $this->getDataDir() . '/users';
		$safe = substr(preg_replace('/[^a-zA-Z0-9_.@-]/', '_', (string) $login), 0, 64);
		$fingerprint = substr(hash('sha256', (string) $login), 0, 12);

		return $dir . '/' . $safe . '-' . $fingerprint . '.json';
	}

	/**
	 * Old settings file name, before the hash was added.
	 *
	 * @param string $login Dolibarr login
	 * @return string
	 */
	public function getLegacyUserSettingsPath($login)
	{
		$dir = $this->getDataDir() . '/users';

		return $dir . '/' . preg_replace('/[^a-zA-Z0-9_.@-]/', '_', (string) $login) . '.json';
	}
}
