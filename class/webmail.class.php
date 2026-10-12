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
 * \file        class/webmail.class.php
 * \ingroup     cyphtWebmail
 * \brief       Main entry point for the Dolibarr/Cypht code. Forwards calls
 *              to the classes in class/install, class/auth and
 *              class/integration.
 */

require_once __DIR__ . '/install/paths.class.php';
require_once __DIR__ . '/install/environment.class.php';
require_once __DIR__ . '/install/vendorlayout.class.php';
require_once __DIR__ . '/auth/token.class.php';
require_once __DIR__ . '/auth/login.class.php';
require_once __DIR__ . '/install/upstreampatches.class.php';
require_once __DIR__ . '/integration/contactsource.class.php';
require_once __DIR__ . '/install/moduleinstaller.class.php';
require_once __DIR__ . '/install/pipeline.class.php';

class CyphtWebmail
{
	/**
	 * @var DoliDB
	 */
	public $db;

	/**
	 * @var string  Last error message
	 */
	public $error = '';

	/**
	 * @var CyphtPaths
	 */
	private $paths;

	/**
	 * @var CyphtEnvironment
	 */
	private $envConfig;

	/**
	 * @var CyphtVendorLayout
	 */
	private $vendorBridge;

	/**
	 * @var CyphtToken
	 */
	private $token;

	/**
	 * @var CyphtLogin
	 */
	private $login;

	/**
	 * @var CyphtUpstreamPatches
	 */
	private $upstreamPatcher;

	/**
	 * @var CyphtModuleInstaller
	 */
	private $moduleInstaller;

	/**
	 * @var CyphtPipeline
	 */
	private $buildPipeline;

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;

		$this->paths = new CyphtPaths();
		$this->token = new CyphtToken($db);
		$this->login = new CyphtLogin($this->paths, $this->token);
		$this->envConfig = new CyphtEnvironment($this->paths, $this->token);
		$this->vendorBridge = new CyphtVendorLayout($this->paths);
		$this->upstreamPatcher = new CyphtUpstreamPatches($this->paths);
		$this->moduleInstaller = new CyphtModuleInstaller($this->paths);
		$this->buildPipeline = new CyphtPipeline(
			$db,
			$this->paths,
			$this->envConfig,
			$this->vendorBridge,
			$this->login,
			$this->upstreamPatcher,
			$this->moduleInstaller
		);
	}

	/**
	 * Path of a user's settings file (used by the USER_DELETE trigger).
	 *
	 * @param string $login Dolibarr login
	 * @return string
	 */
	public function getUserSettingsPath($login)
	{
		return $this->paths->getUserSettingsPath($login);
	}

	/**
	 * @param string $login Dolibarr login
	 * @return string
	 */
	public function getLegacyUserSettingsPath($login)
	{
		return $this->paths->getLegacyUserSettingsPath($login);
	}

	// ---- CyphtPaths ----

	/** @return string */
	public function getModuleRoot()
	{
		return $this->paths->getModuleRoot();
	}

	/** @return string */
	public function getCyphtPath()
	{
		return $this->paths->getCyphtPath();
	}

	/** @return string */
	public function getCyphtSitePath()
	{
		return $this->paths->getCyphtSitePath();
	}

	/** @return string */
	public function getPublicPath()
	{
		return $this->paths->getPublicPath();
	}

	/** @return string */
	public function getDataDir()
	{
		return $this->paths->getDataDir();
	}

	/** @return string|null */
	public function getInstalledVersion()
	{
		return $this->paths->getInstalledVersion();
	}

	/** @return array<string,string>|null */
	public function getModuleVersion()
	{
		return $this->paths->getModuleVersion();
	}

	/** @return array<string,string>|null */
	public function getBuildInfo()
	{
		return $this->paths->getBuildInfo();
	}

	/** @return string */
	public function getBuiltVersion()
	{
		return $this->paths->getBuiltVersion();
	}

	/** @return string */
	public function getLastBuildDate()
	{
		return $this->paths->getLastBuildDate();
	}

	/** @return bool */
	public function needsRebuild()
	{
		return $this->paths->needsRebuild();
	}

	/** @return bool */
	public function isPublished()
	{
		return $this->paths->isPublished();
	}

	// ---- CyphtEnvironment ----

	/** @return array<string,string> */
	public function buildEnvOverrides()
	{
		return $this->envConfig->buildEnvOverrides();
	}

	/**
	 * @param array<string,string> $overrides
	 * @return bool
	 */
	public function writeEnvFile(array $overrides)
	{
		$result = $this->envConfig->writeEnvFile($overrides);
		$this->error = $this->envConfig->error;
		return $result;
	}

	// ---- Auth ----

	/** @return string */
	public function getOrCreateSsoSecret()
	{
		return $this->token->getOrCreateSsoSecret();
	}

	/**
	 * @param string $login
	 * @return string
	 */
	public function generateSsoLoginToken($login)
	{
		return $this->token->generateSsoLoginToken($login);
	}

	/**
	 * @param string $login
	 * @param string $cyphtUrl
	 * @return bool
	 */
	public function performSsoLogin($login, $cyphtUrl, $userLang = '')
	{
		$result = $this->login->performSsoLogin($login, $cyphtUrl, $userLang);
		$this->error = $this->login->error;
		return $result;
	}

	// ---- CyphtPipeline ----

	/** @return bool */
	public function publishSite()
	{
		$result = $this->buildPipeline->publishSite();
		$this->error = $this->buildPipeline->error;
		return $result;
	}

	/**
	 * @return array{ok:bool,checks:array}
	 */
	public function checkBuildRequirements()
	{
		return $this->buildPipeline->checkBuildRequirements();
	}

	/** @return array{success:bool,message:string} */
	public function requestCancel()
	{
		return $this->buildPipeline->requestCancel();
	}

	/**
	 * @param callable|null $onProgress
	 * @return array{success:bool,output:string,error:string}
	 */
	public function runConfigGen(callable $onProgress = null)
	{
		$result = $this->buildPipeline->runConfigGen($onProgress);
		if (empty($result['success']) && !empty($result['error'])) {
			$this->error = $result['error'];
		}
		return $result;
	}

	/**
	 * Log of the last build (one JSON object per line).
	 *
	 * @return string
	 */
	public function getLastBuildLog()
	{
		return $this->buildPipeline->getLastBuildLog();
	}

	/**
	 * Send all buffered output to the browser now.
	 *
	 * @return void
	 */
	public function cyphtwebmail_flush_now()
	{
		if (ini_get('zlib.output_compression')) {
			ini_set('zlib.output_compression', 'Off');
		}

		while (ob_get_level() > 0) {
			$status = ob_get_status();
			if ($status && isset($status['name']) && $status['name'] === 'ob_gzhandler') {
				// Flushing the gzip handler breaks the output
				break;
			}
			ob_end_flush();
		}

		flush();
	}
}
