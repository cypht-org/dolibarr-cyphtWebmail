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

require_once __DIR__ . '/../install/paths.class.php';

/**
 * \file        class/install/moduleinstaller.class.php
 * \ingroup     cyphtWebmail
 * \brief       Copies cypht/modules/<name> into the vendored Cypht.
 */
class CyphtModuleInstaller
{
	/**
	 * @var string  Last error message
	 */
	public $error = '';

	/**
	 * @var CyphtPaths
	 */
	private $paths;

	/**
	 * @param CyphtPaths $paths
	 */
	public function __construct(CyphtPaths $paths)
	{
		$this->paths = $paths;
	}

	/**
	 * Directory of our Cypht module sets.
	 *
	 * @return string
	 */
	public function getSourceRoot()
	{
		return $this->paths->getModuleRoot() . '/cypht/modules';
	}

	/**
	 * Names of our module sets.
	 *
	 * @return string[]
	 */
	public function listModuleSets()
	{
		$dirs = glob($this->getSourceRoot() . '/*', GLOB_ONLYDIR);
		if (!is_array($dirs)) {
			return array();
		}

		return array_map('basename', $dirs);
	}

	/**
	 * Install one module set.
	 *
	 * @param string $name Module set name, e.g. "dolibarr_contacts"
	 * @return bool
	 */
	public function install($name)
	{
		$name = basename($name);
		$source = $this->getSourceRoot() . '/' . $name;
		$target = $this->paths->getCyphtPath() . '/modules/' . $name;

		if (!is_dir($source)) {
			$this->error = 'No such module set: ' . $source;
			return false;
		}

		if (!is_dir($target) && !@mkdir($target, 0755, true) && !is_dir($target)) {
			$this->error = 'Could not create ' . $target;
			return false;
		}

		$files = glob($source . '/*');
		if (!is_array($files) || count($files) === 0) {
			$this->error = 'Module set is empty: ' . $source;
			return false;
		}

		foreach ($files as $file) {
			if (is_dir($file)) {
				continue;
			}
			if (!@copy($file, $target . '/' . basename($file))) {
				$this->error = 'Could not write ' . $target . '/' . basename($file);
				return false;
			}
		}

		return true;
	}

	/**
	 * Install all our module sets.
	 *
	 * @return bool
	 */
	public function installAll()
	{
		$names = $this->listModuleSets();
		if (count($names) === 0) {
			$this->error = 'No module sets found under ' . $this->getSourceRoot();
			return false;
		}

		foreach ($names as $name) {
			if (!$this->install($name)) {
				return false;
			}
		}

		return true;
	}
}
