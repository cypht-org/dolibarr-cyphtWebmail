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

require_once __DIR__ . '/../install/paths.class.php';
require_once __DIR__ . '/../install/environment.class.php';
require_once __DIR__ . '/../install/vendorlayout.class.php';
require_once __DIR__ . '/../auth/login.class.php';
require_once __DIR__ . '/../install/upstreampatches.class.php';
require_once __DIR__ . '/../install/moduleinstaller.class.php';

/**
 * \file        class/install/pipeline.class.php
 * \ingroup     cyphtWebmail
 * \brief       The build: composer install, Cypht's config_gen.php, then
 *              publish to public/.
 */
class CyphtPipeline
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
	 * @param DoliDB $db
	 * @param CyphtPaths $paths
	 * @param CyphtEnvironment $envConfig
	 * @param CyphtVendorLayout $vendorBridge
	 * @param CyphtLogin $login
	 * @param CyphtUpstreamPatches $upstreamPatcher
	 * @param CyphtModuleInstaller $moduleInstaller
	 */
	public function __construct(
		$db,
		CyphtPaths $paths,
		?CyphtEnvironment $envConfig,
		CyphtVendorLayout $vendorBridge,
		?CyphtLogin $login,
		CyphtUpstreamPatches $upstreamPatcher,
		CyphtModuleInstaller $moduleInstaller
	) {
		$this->db = $db;
		$this->paths = $paths;
		$this->envConfig = $envConfig;
		$this->vendorBridge = $vendorBridge;
		$this->login = $login;
		$this->upstreamPatcher = $upstreamPatcher;
		$this->moduleInstaller = $moduleInstaller;
	}

	/**
	 * Debug log path, cleared at the start of each build.
	 *
	 * @return string
	 */
	private function getDebugLogPath()
	{
		return $this->paths->getModuleRoot() . '/debug.log';
	}

	/**
	 * @param string $line
	 * @return void
	 */
	private function debugLog($line)
	{
		$timestamp = date('Y-m-d H:i:s');
		@file_put_contents($this->getDebugLogPath(), "[{$timestamp}] {$line}\n", FILE_APPEND);
	}

	/**
	 * Detect a sudo password prompt or a permission error in the output.
	 * Otherwise a sudo prompt would hang until the timeout.
	 *
	 * @param string $text New output to check
	 * @return string|null Message to show, or null
	 */
	private function detectPrivilegeOrCredentialPrompt($text)
	{
		if ($text === '') {
			return null;
		}

		$checks = array(
			'/\[sudo\]\s*password/i' => 'This command is asking for a sudo password. ' .
				'This web terminal will not accept or cache one. Run this command yourself ' .
				'from a system terminal as a privileged user instead.',
			'/permission denied/i' => 'Permission denied. The webserver user does not have the access ' .
				'this command needs. Fix the file/folder ownership or permissions, or run the ' .
				'command manually as a user that has them; this module will not attempt to ' .
				'elevate privileges itself.',
			'/access is denied/i' => 'Access denied. The webserver user does not have the access this ' .
				'command needs. Fix the file/folder permissions, or run the command manually as a ' .
				'user that has them; this module will not attempt to elevate privileges itself.',
		);

		foreach ($checks as $pattern => $reason) {
			if (preg_match($pattern, $text)) {
				return $reason;
			}
		}

		return null;
	}

	/**
	 * Log of the last build, one {t,c} JSON object per line, so the setup
	 * page can show it again after a reload.
	 *
	 * @return string
	 */
	private function getLastBuildLogPath()
	{
		return $this->paths->getModuleRoot() . '/last_build_log.ndjson';
	}

	/**
	 * @return string Log of the last build, or '' if none
	 */
	public function getLastBuildLog()
	{
		$content = @file_get_contents($this->getLastBuildLogPath());
		return $content === false ? '' : $content;
	}

	/**
	 * Run a command and stream its output.
	 *
	 * @param string[]      $cmd     Command and arguments (escaped here)
	 * @param string        $cwd     Working directory
	 * @param callable|null $onChunk callback(string $chunk, string $type).
	 *                               $type is always 'out', because Composer
	 *                               writes normal output to stderr too.
	 * @return array{success:bool,output:string,error:string,exitcode:int}
	 */
	private function runProcess(array $cmd, $cwd, callable $onChunk = null)
	{
		if (!function_exists('proc_open')) {
			return array(
				'success' => false,
				'output' => '',
				'error' => 'proc_open() is disabled on this server (common on shared hosting). ' .
					'Ask your host to enable proc_open/exec, or run this manually over SSH: ' .
					'cd ' . $cwd . ' && php scripts/config_gen.php',
				'exitcode' => -1,
			);
		}

		$cmdline = implode(' ', array_map('escapeshellarg', $cmd));

		$stdoutFile = $this->paths->getDataDir() . '/build.stdout.tmp';
		$stderrFile = $this->paths->getDataDir() . '/build.stderr.tmp';
		@unlink($stdoutFile);
		@unlink($stderrFile);

		$descriptorspec = array(
			0 => array('pipe', 'r'),
			1 => array('file', $stdoutFile, 'w'),
			2 => array('file', $stderrFile, 'w'),
		);

		$this->debugLog("STARTING: {$cmdline}");
		$this->debugLog("  cwd: {$cwd}");

		$process = proc_open($cmdline, $descriptorspec, $pipes, $cwd);
		if (!is_resource($process)) {
			$this->debugLog("  proc_open() itself returned false/failed; command never started at all.");
			return array(
				'success' => false,
				'output' => '',
				'error' => 'Unable to start process for: ' . $cmdline,
				'exitcode' => -1,
			);
		}

		$startStatus = proc_get_status($process);
		$this->debugLog("  proc_open succeeded, pid={$startStatus['pid']}, running=" . ($startStatus['running'] ? 'yes' : 'no'));

		fclose($pipes[0]);

		$readNew = function ($file, &$pos) {
			$size = @filesize($file);
			if ($size === false || $size <= $pos) {
				clearstatcache(true, $file);
				return '';
			}
			$fh = @fopen($file, 'rb');
			if ($fh === false) {
				return '';
			}
			fseek($fh, $pos);
			$chunk = stream_get_contents($fh);
			fclose($fh);
			$pos = $size;
			return $chunk === false ? '' : $chunk;
		};

		$stdoutPos = 0;
		$stderrPos = 0;
		$stdout = '';
		$stderr = '';
		$start = time();
		$timeoutSeconds = 180;
		$timedOut = false;
		$cancelled = false;
		$privilegePromptReason = null;
		$cancelFlag = $this->getCancelFlagPath();
		$lastHeartbeat = 0;

		while (true) {
			clearstatcache(true, $stdoutFile);
			clearstatcache(true, $stderrFile);
			$newOut = $readNew($stdoutFile, $stdoutPos);
			$newErr = $readNew($stderrFile, $stderrPos);
			$stdout .= $newOut;
			$stderr .= $newErr;

			if ($newOut !== '' || $newErr !== '') {
				$this->debugLog("  output received (" . strlen($newOut) . " stdout / " . strlen($newErr) . " stderr bytes)");
			}

			if ($onChunk !== null) {
				if ($newOut !== '') {
					$onChunk($newOut, 'out');
				}
				if ($newErr !== '') {
					$onChunk($newErr, 'out');
				}
			}

			$status = proc_get_status($process);
			if (!$status['running']) {
				$this->debugLog("  process exited, exitcode={$status['exitcode']}");
				break;
			}

			$elapsed = time() - $start;

			$promptReason = $this->detectPrivilegeOrCredentialPrompt($newOut . $newErr);
			if ($promptReason !== null) {
				$privilegePromptReason = $promptReason;
				$this->debugLog("  privilege/credential prompt detected after {$elapsed}s, killing pid {$status['pid']}: {$promptReason}");
				if (stripos(PHP_OS, 'WIN') === 0 && function_exists('exec')) {
					@exec('taskkill /F /T /PID ' . ((int) $status['pid']) . ' 2>NUL');
				}
				proc_terminate($process, 9);
				break;
			}

			if ($elapsed >= $lastHeartbeat + 5) {
				$lastHeartbeat = $elapsed;
				$this->debugLog("  still running after {$elapsed}s (pid={$status['pid']}), total output so far: " . strlen($stdout) . " stdout / " . strlen($stderr) . " stderr bytes");
			}

			if (file_exists($cancelFlag)) {
				$cancelled = true;
				$this->debugLog("  cancel flag detected after {$elapsed}s, killing pid {$status['pid']}");
				@unlink($cancelFlag);
				if (stripos(PHP_OS, 'WIN') === 0 && function_exists('exec')) {
					@exec('taskkill /F /T /PID ' . ((int) $status['pid']) . ' 2>NUL');
				}
				proc_terminate($process, 9);
				break;
			}

			if ((time() - $start) > $timeoutSeconds) {
				$timedOut = true;
				$this->debugLog("  TIMEOUT after {$timeoutSeconds}s, killing pid {$status['pid']}");
				// On Windows proc_terminate() only kills the cmd.exe wrapper,
				// so use taskkill /T to kill the whole tree.
				if (stripos(PHP_OS, 'WIN') === 0 && function_exists('exec')) {
					$pid = $status['pid'];
					@exec('taskkill /F /T /PID ' . ((int) $pid) . ' 2>NUL');
				}
				proc_terminate($process, 9);
				break;
			}

			usleep(150000);
		}

		clearstatcache(true, $stdoutFile);
		clearstatcache(true, $stderrFile);
		$finalOut = $readNew($stdoutFile, $stdoutPos);
		$finalErr = $readNew($stderrFile, $stderrPos);
		$stdout .= $finalOut;
		$stderr .= $finalErr;
		if ($onChunk !== null) {
			if ($finalOut !== '') {
				$onChunk($finalOut, 'out');
			}
			if ($finalErr !== '') {
				$onChunk($finalErr, 'out');
			}
		}

		$exitCode = proc_close($process);
		@unlink($stdoutFile);
		@unlink($stderrFile);

		if ($cancelled) {
			$this->debugLog("FINISHED (cancelled): {$cmdline}");
			return array(
				'success' => false,
				'output' => $stdout,
				'error' => trim($stderr) . "\n[Cancelled by user]",
				'exitcode' => -1,
				'cancelled' => true,
			);
		}

		if ($privilegePromptReason !== null) {
			$this->debugLog("FINISHED (privilege/credential prompt): {$cmdline}");
			return array(
				'success' => false,
				'output' => $stdout,
				'error' => $privilegePromptReason,
				'exitcode' => -1,
				'privilege_prompt' => true,
			);
		}

		if ($timedOut) {
			$this->debugLog("FINISHED (timed out): {$cmdline}");
			return array(
				'success' => false,
				'output' => $stdout,
				'error' => trim($stderr) . "\n[Timed out after {$timeoutSeconds}s and was terminated]",
				'exitcode' => -1,
			);
		}

		$this->debugLog("FINISHED (exitcode={$exitCode}): {$cmdline}");
		$this->debugLog("  total stdout: " . strlen($stdout) . " bytes, stderr: " . strlen($stderr) . " bytes");

		return array(
			'success' => ($exitCode === 0),
			'output' => (string) $stdout,
			'error' => (string) $stderr,
			'exitcode' => $exitCode,
		);
	}

	/**
	 * Check that this server can run the build from the browser.
	 *
	 * @return array{ok:bool,checks:array<int,array{label:string,ok:bool,detail:string}>}
	 */
	public function checkBuildRequirements()
	{
		$checks = array();

		$checks[] = array(
			'label' => 'proc_open()',
			'ok' => function_exists('proc_open'),
			'detail' => function_exists('proc_open') ? 'available' : 'disabled in php.ini for the web server',
		);

		$php = $this->findPhpBinary();
		$checks[] = array(
			'label' => 'PHP command line binary',
			'ok' => ($php !== null),
			'detail' => ($php !== null) ? $php : 'not found on PATH or in the usual locations',
		);

		$composer = $this->findComposerBinary();
		$hasVendor = is_dir($this->paths->getCyphtPath());
		$checks[] = array(
			'label' => 'Composer',
			'ok' => ($composer !== null || $hasVendor),
			'detail' => ($composer !== null)
				? implode(' ', $composer)
				: ($hasVendor ? 'not found, but vendor/ is already present' : 'not found, and vendor/ is missing'),
		);

		$root = $this->paths->getModuleRoot();
		$writable = is_writable($root) && (!is_dir($root.'/vendor') || is_writable($root.'/vendor'));
		$checks[] = array(
			'label' => 'Write access for the web server user',
			'ok' => $writable,
			'detail' => $writable ? $root : 'cannot write to '.$root,
		);

		$ok = true;
		foreach ($checks as $check) {
			if (!$check['ok']) {
				$ok = false;
			}
		}

		return array('ok' => $ok, 'checks' => $checks);
	}

	private function findPhpBinary()
	{
		$sapi = php_sapi_name();
		if (defined('PHP_BINARY') && PHP_BINARY && in_array($sapi, array('cli', 'cgi-fcgi', 'cgi', 'phpdbg'), true)) {
			return PHP_BINARY;
		}

		if (function_exists('exec')) {
			$isWindows = (stripos(PHP_OS, 'WIN') === 0);
			$checkCmd = $isWindows ? 'where php 2>NUL' : 'command -v php 2>/dev/null';
			$output = array();
			$exitCode = 1;
			@exec($checkCmd, $output, $exitCode);
			if ($exitCode === 0 && !empty($output[0])) {
				return trim($output[0]);
			}
		}

		// XAMPP fallback
		if (!empty($_SERVER['DOCUMENT_ROOT'])) {
			$xamppRoot = dirname(rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '/'));
			$candidate = $xamppRoot . '/php/php.exe';
			if (file_exists($candidate)) {
				return $candidate;
			}
		}

		return null;
	}

	/**
	 * Find Composer: composer.phar in the module root, then "composer" on PATH.
	 *
	 * @return string[]|null Command prefix, or null if not found
	 */
	private function findComposerBinary()
	{
		$pharPath = $this->paths->getModuleRoot() . '/composer.phar';
		if (file_exists($pharPath)) {
			$phpBinary = $this->findPhpBinary();
			if ($phpBinary !== null) {
				return array($phpBinary, $pharPath);
			}
		}

		if (!function_exists('exec')) {
			return null;
		}

		$isWindows = (stripos(PHP_OS, 'WIN') === 0);
		$checkCmd = $isWindows ? 'where composer 2>NUL' : 'command -v composer 2>/dev/null';
		$output = array();
		$exitCode = 1;
		@exec($checkCmd, $output, $exitCode);

		if ($exitCode === 0 && !empty($output[0])) {
			return array(trim($output[0]));
		}

		return null;
	}

	/**
	 * Copy the built site/ from vendor/ to the module's public/ folder.
	 *
	 * @return bool
	 */
	public function publishSite()
	{
		$sitePath = $this->paths->getCyphtSitePath();
		if (!is_dir($sitePath)) {
			$this->error = 'Build succeeded but no site/ directory was produced at ' . $sitePath;
			return false;
		}

		$publicPath = $this->paths->getPublicPath();
		$staging = $publicPath . '.new';
		$previous = $publicPath . '.old';

		foreach (array($staging, $previous) as $leftover) {
			if (is_dir($leftover)) {
				$this->vendorBridge->deleteRecursive($leftover);
			}
		}

		$this->vendorBridge->copyRecursive($sitePath, $staging);

		if (!file_exists($staging . '/index.php')) {
			$this->error = 'Publish copied no index.php into ' . $staging;
			$this->vendorBridge->deleteRecursive($staging);
			return false;
		}

		if (!$this->makeIndexRelocatable($staging . '/index.php')) {
			$this->vendorBridge->deleteRecursive($staging);
			return false;
		}

		$moved = (!is_dir($publicPath) || @rename($publicPath, $previous));
		if ($moved && @rename($staging, $publicPath)) {
			if (is_dir($previous)) {
				$this->vendorBridge->deleteRecursive($previous);
			}

			return true;
		}

		if ($moved && is_dir($previous)) {
			@rename($previous, $publicPath);
		}

		$this->vendorBridge->deleteRecursive($publicPath);
		$this->vendorBridge->copyRecursive($staging, $publicPath);

		if (!file_exists($publicPath . '/index.php')) {
			$this->error = 'Could not replace ' . $publicPath . '. The finished build is in '
				. $staging . '; copy it over ' . $publicPath . ' to recover.';
			return false;
		}

		$this->vendorBridge->deleteRecursive($staging);

		return true;
	}


	/**
	 * Make the published index.php work from any location.
	 *
	 * @param string $indexFile Published public/index.php
	 * @return bool
	 */
	private function makeIndexRelocatable($indexFile)
	{
		$content = file_get_contents($indexFile);
		if ($content === false) {
			$this->error = 'Could not read ' . $indexFile;
			return false;
		}

		/* 1. Relative APP_PATH */
		$content = preg_replace(
			"/define\(\s*'APP_PATH'\s*,\s*'[^']*'\s*\);/",
			"define('APP_PATH', dirname(__DIR__).'/vendor/jason-munro/cypht/');"
				. "\n\nrequire_once dirname(__DIR__).'/class/runtime/envbootstrap.class.php';"
				. "\n\$cyphtEnvBootstrap = new CyphtEnvBootstrap(dirname(__DIR__));"
				. "\n\$cyphtEnvBootstrap->apply();",
			$content,
			1,
			$countPath
		);

		if ($content === null || $countPath !== 1) {
			$this->error = 'Could not rewrite APP_PATH in ' . $indexFile .
				' (matched ' . (int) $countPath . ' times). Cypht\'s entry point template may have changed.';
			return false;
		}

		/* 2. SITE_ID from the environment */
		$content = preg_replace(
			"/define\(\s*'SITE_ID'\s*,\s*'([^']*)'\s*\);/",
			"define('SITE_ID', (isset(\$_ENV['CYPHT_SITE_ID']) && \$_ENV['CYPHT_SITE_ID'] !== '') ? \$_ENV['CYPHT_SITE_ID'] : '$1');",
			$content,
			1,
			$countSite
		);

		if ($content === null || $countSite !== 1) {
			$this->error = 'Could not rewrite SITE_ID in ' . $indexFile .
				' (matched ' . (int) $countSite . ' times).';
			return false;
		}

		if (file_put_contents($indexFile, $content) === false) {
			$this->error = 'Could not write ' . $indexFile;
			return false;
		}

		return true;
	}

	/**
	 * Lock file so only one build runs at a time.
	 *
	 * @return string
	 */
	private function getLockFilePath()
	{
		return $this->paths->getDataDir() . '/build.lock';
	}

	/**
	 * File that tells a running build to stop. Checked by runProcess().
	 *
	 * @return string
	 */
	private function getCancelFlagPath()
	{
		return $this->paths->getDataDir() . '/build.cancel';
	}

	/**
	 * Ask the running build to stop.
	 *
	 * @return array{success:bool,message:string}
	 */
	public function requestCancel()
	{
		if (!file_exists($this->getLockFilePath())) {
			return array('success' => false, 'message' => 'No build appears to be running.');
		}

		file_put_contents($this->getCancelFlagPath(), (string) time());

		return array('success' => true, 'message' => 'Cancel requested. The current step will stop shortly.');
	}

	/**
	 * Run the build, unless one is already running.
	 *
	 * @param callable|null $onProgress callback(string $chunk) for live output
	 * @return array{success:bool,output:string,error:string}
	 */
	public function runConfigGen(callable $onProgress = null)
	{
		@file_put_contents($this->getDebugLogPath(), '');
		$this->debugLog('=== runConfigGen() starting ===');
		$this->debugLog('PHP version: ' . phpversion() . ', OS: ' . PHP_OS . ', SAPI: ' . php_sapi_name());

		@set_time_limit(0);
		@ignore_user_abort(true);

		$lockFile = $this->getLockFilePath();

		if (file_exists($lockFile)) {
			$age = time() - (int) filemtime($lockFile);
			// 420s covers two 180s steps plus the copy. Older locks are ignored.
			if ($age < 420) {
				return array(
					'success' => false,
					'output' => '',
					'error' => 'A build is already running (started ' . $age . 's ago). ' .
						'Wait for it to finish. ' .
						'If no build is actually running, this is a leftover lock from a ' .
						'crashed build; it is ignored automatically after ' . (420 - $age) . 's, ' .
						'or delete: ' . $lockFile,
				);
			}
			$this->debugLog('Ignoring stale build.lock (' . $age . 's old, previous build crashed or was killed)');
		}

		@unlink($this->getCancelFlagPath());

		@file_put_contents($this->getLastBuildLogPath(), '');

		file_put_contents($lockFile, (string) time());
		try {
			return $this->runConfigGenSteps($onProgress);
		} finally {
			@unlink($lockFile);
			@unlink($this->getCancelFlagPath());
		}
	}

	/**
	 * Run composer install, config_gen.php and publish.
	 *
	 * @param callable|null $onProgress callback(string $chunk, string $type),
	 *                                  $type is 'out' (command output),
	 *                                  'info' (step headers) or 'err'.
	 * @return array{success:bool,output:string,error:string}
	 */
	private function runConfigGenSteps(callable $onProgress = null)
	{
		global $conf;

		$log = '';
		$emit = function ($chunk, $type = 'info') use (&$log, $onProgress) {
			$log .= $chunk;
			@file_put_contents($this->getLastBuildLogPath(), json_encode(array('t' => $type, 'c' => $chunk))."\n", FILE_APPEND);
			if ($onProgress !== null) {
				$onProgress($chunk, $type);
			}
		};

		$moduleRoot = $this->paths->getModuleRoot();
		$cyphtPath = $this->paths->getCyphtPath();

		// ---- Step 1/3: composer install ----
		$emit("== Step 1/3: composer install ==\n");
		$composerBinary = $this->findComposerBinary();
		$stepStart = microtime(true);

		if ($composerBinary !== null) {
			$installResult = $this->runProcess(
				array_merge($composerBinary, array('install', '--no-interaction', '--no-progress')),
				$moduleRoot,
				$emit
			);
			$emit(sprintf("\n[composer install finished in %.1fs]\n", microtime(true) - $stepStart));

			if (!empty($installResult['cancelled'])) {
				return array('success' => false, 'output' => $log, 'error' => 'Build cancelled.');
			}
			if (!empty($installResult['privilege_prompt'])) {
				$emit("\n" . $installResult['error'] . "\n", 'err');
				return array('success' => false, 'output' => $log, 'error' => $installResult['error']);
			}
			if (!$installResult['success']) {
				$emit("\ncomposer install failed (exit code " . $installResult['exitcode'] . ").\n", 'err');
				return array('success' => false, 'output' => $log, 'error' => 'composer install failed, see log.');
			}
		} elseif (is_dir($cyphtPath)) {
			$emit("Composer executable not found on this server, skipping, using the vendor/ already on disk as-is.\n");
		} else {
			$emit("Composer executable not found on this server, and Cypht is not present under vendor/.\n", 'err');
			return array(
				'success' => false,
				'output' => $log,
				'error' => 'Cannot install Cypht: no Composer found and vendor/jason-munro/cypht is missing. ' .
					'Install Composer on this server, or run "composer install" manually in: ' . $moduleRoot,
			);
		}

		if (!is_dir($cyphtPath)) {
			$emit("\nvendor/jason-munro/cypht still missing after composer install.\n", 'err');
			return array('success' => false, 'output' => $log, 'error' => 'Cypht did not get installed, see log.');
		}

		// ---- Step 2/3: config_gen.php ----
		$emit("\n== Step 2/3: php scripts/config_gen.php ==\n");

		$envError = '';
		if (!CyphtEnvironment::writeEnvTo($this->paths->getCyphtPath(), CyphtEnvironment::buildTimeDefaults(), $envError, true)) {
			$this->error = $envError;
			$emit($this->error . "\n", 'err');
			return array('success' => false, 'output' => $log, 'error' => $this->error);
		}
		if (!CyphtEnvironment::writeConfigTo($this->paths->getCyphtPath(), $envError)) {
			$this->error = $envError;
			$emit($this->error . "\n", 'err');
			return array('success' => false, 'output' => $log, 'error' => $this->error);
		}
		$emit("wrote build defaults to .env and config/zz_dolibarr.php; this installation's values are read at runtime.\n");

		if (!$this->vendorBridge->ensureCyphtVendorBridge()) {
			$this->error = $this->vendorBridge->error;
			$emit($this->error . "\n", 'err');
			return array('success' => false, 'output' => $log, 'error' => $this->error);
		}
		$emit("vendor/ bridge shim in place (Cypht installed as a flat dependency, see comment in the file).\n");

		// Must run before config_gen.php, which reads each module's setup.php.
		if (!$this->moduleInstaller->installAll()) {
			$this->error = $this->moduleInstaller->error;
			$emit($this->error . "\n", 'err');
			return array('success' => false, 'output' => $log, 'error' => $this->error);
		}
		$emit("Cypht module sets installed: " . implode(', ', $this->moduleInstaller->listModuleSets()) . ".\n");

		if (!$this->upstreamPatcher->patchCoreFunctionsGuard()) {
			$this->error = $this->upstreamPatcher->error;
			$emit($this->error . "\n", 'err');
			return array('success' => false, 'output' => $log, 'error' => $this->error);
		}
		$emit("Patched missing hm_exists() guards in modules/core/functions.php (upstream gap, needed for SSO).\n");

		if (!$this->upstreamPatcher->patchMimeMessage()) {
			$this->error = $this->upstreamPatcher->error;
			$emit($this->error . "\n", 'err');
			return array('success' => false, 'output' => $log, 'error' => $this->error);
		}
		$emit("Patched the HTML message format in modules/smtp/hm-mime-message.php.\n");

		$phpBinary = $this->findPhpBinary();
		if ($phpBinary === null) {
			$emit("No usable PHP CLI executable found. PHP is running as an Apache module here, so PHP_BINARY " .
				"points at httpd.exe, not php.exe, and no 'php' was found on PATH or at the usual XAMPP location " .
				"(<xampp>/php/php.exe). Add php.exe to your system PATH, or drop a composer.phar in the module root.\n", 'err');
			return array('success' => false, 'output' => $log, 'error' => 'No PHP CLI binary found, see log.');
		}
		$emit("Using PHP CLI: " . $phpBinary . "\n");

		$stepStart = microtime(true);
		$result = $this->runProcess(array($phpBinary, 'scripts/config_gen.php'), $cyphtPath, $emit);
		$emit(sprintf("\n[config_gen.php finished in %.1fs]\n", microtime(true) - $stepStart));

		if (!empty($result['cancelled'])) {
			return array('success' => false, 'output' => $log, 'error' => 'Build cancelled.');
		}
		if (!empty($result['privilege_prompt'])) {
			$emit("\n" . $result['error'] . "\n", 'err');
			return array('success' => false, 'output' => $log, 'error' => $result['error']);
		}
		if (!$result['success']) {
			$emit("\nconfig_gen.php failed (exit code " . $result['exitcode'] . ").\n", 'err');
			return array('success' => false, 'output' => $log, 'error' => 'config_gen.php failed, see log.');
		}

		// ---- Step 3/3: publish ----
		$emit("\n== Step 3/3: publishing site/ to public/ ==\n");
		$stepStart = microtime(true);

		if (!$this->publishSite()) {
			$emit($this->error . "\n", 'err');
			return array('success' => false, 'output' => $log, 'error' => $this->error);
		}
		$emit(sprintf("[copy finished in %.1fs]\n", microtime(true) - $stepStart));

		$version = $this->paths->getInstalledVersion();

		$this->paths->writeBuildInfo($version);

		if ($this->db !== null) {
			global $conf;

			require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';

			dolibarr_set_const($this->db, 'CYPHTWEBMAIL_LAST_BUILD', dol_now(), 'chaine', 0, '', $conf->entity);
			dolibarr_set_const($this->db, 'CYPHTWEBMAIL_BUILT_VERSION', $version, 'chaine', 0, '', $conf->entity);
		}

		$emit("Published to " . $this->paths->getPublicPath() . "\nBuild complete. Cypht " . $version . " is live.\n");

		return array('success' => true, 'output' => $log, 'error' => '');
	}
}
