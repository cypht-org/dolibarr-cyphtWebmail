#!/usr/bin/env php
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
 * \file        scripts/build.php
 * \ingroup     cyphtWebmail
 * \brief       Command line build.
 */

if (substr(php_sapi_name(), 0, 3) !== 'cli') {
	fwrite(STDERR, "This script must be run from the command line.\n");
	exit(1);
}

error_reporting(E_ALL);
ini_set('display_errors', 'stderr');

register_shutdown_function(function () {
	$fatal = error_get_last();
	if ($fatal !== null && ($fatal['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR))) {
		fwrite(STDERR, "\nFATAL: ".$fatal['message']."\n  at ".$fatal['file'].':'.$fatal['line']."\n");
	}
});

$root = dirname(__DIR__);

$options = array(
	'mode' => 'build',
	'owner' => '',
	'group' => '',
	'quiet' => false,
	'permissions' => true,
	'dolibarr' => '',
);

foreach (array_slice($argv, 1) as $arg) {
	if ($arg === '--help' || $arg === '-h') {
		fwrite(STDOUT, <<<TEXT
Build the embedded Cypht application.

  php scripts/build.php [options]

Modes
  (default)             full build; requires Dolibarr and its database
  --prepare             dependencies and module sets only; no Dolibarr needed.
                        Run this before packaging or zipping the module.

Options
  --dolibarr=PATH       where Dolibarr lives, when this module sits outside
                        its tree (symlinked custom dir, separate checkout).
                        Accepts the htdocs folder, the install root, or
                        master.inc.php itself. Found automatically otherwise.
  --owner=USER          chown writable paths afterwards (POSIX only)
  --group=GROUP         chgrp writable paths afterwards (POSIX only)
  --skip-permissions    do not touch ownership or modes
  --quiet               errors and the final result only
  --help                show this

A compiled app is not portable: config_gen.php bakes absolute paths into
public/index.php. Package with --prepare, then run a full build on the
target machine, or press Generate on its module setup page.

TEXT
		);
		exit(0);
	}

	if ($arg === '--prepare') {
		$options['mode'] = 'prepare';
	} elseif ($arg === '--quiet') {
		$options['quiet'] = true;
	} elseif ($arg === '--skip-permissions') {
		$options['permissions'] = false;
	} elseif (strpos($arg, '--owner=') === 0) {
		$options['owner'] = substr($arg, 8);
	} elseif (strpos($arg, '--group=') === 0) {
		$options['group'] = substr($arg, 8);
	} elseif (strpos($arg, '--dolibarr=') === 0) {
		$options['dolibarr'] = substr($arg, 11);
	} else {
		fwrite(STDERR, "Unknown option: ".$arg."\nTry --help\n");
		exit(1);
	}
}

/**
 * @param string $line
 * @param bool $quiet
 * @return void
 */
function cyphtSay($line, $quiet = false)
{
	if (!$quiet) {
		fwrite(STDOUT, $line);
	}
}

/**
 * Find Composer: composer.phar in the module root, then on PATH.
 *
 * @param string $root Module root
 * @return string[]|null Command prefix, or null if none found
 */
function cyphtFindComposer($root)
{
	if (is_file($root.'/composer.phar')) {
		return array(PHP_BINARY, $root.'/composer.phar');
	}

	$names = (DIRECTORY_SEPARATOR === '\\')
		? array('composer.bat', 'composer.cmd', 'composer.phar', 'composer')
		: array('composer', 'composer.phar');

	$paths = explode(PATH_SEPARATOR, (string) getenv('PATH'));
	foreach ($names as $name) {
		foreach ($paths as $dir) {
			$candidate = rtrim($dir, '/\\').DIRECTORY_SEPARATOR.$name;
			if (is_file($candidate)) {
				return (substr($name, -5) === '.phar')
					? array(PHP_BINARY, $candidate)
					: array($candidate);
			}
		}
	}

	return null;
}

/**
 * Find master.inc.php from the --dolibarr path. Accepts the file itself,
 * the htdocs folder or the install root.
 *
 * @param string $path
 * @return string|null Absolute path to master.inc.php, or null if not there
 */
function cyphtResolveDolibarr($path)
{
	$path = rtrim($path, "/\\");
	if ($path === '') {
		return null;
	}

	$candidates = array(
		$path,
		$path.'/master.inc.php',
		$path.'/htdocs/master.inc.php',
	);

	foreach ($candidates as $candidate) {
		if (is_file($candidate) && basename($candidate) === 'master.inc.php') {
			$real = realpath($candidate);
			return ($real === false) ? $candidate : $real;
		}
	}

	return null;
}

/**
 * @param string[] $cmd
 * @param string $cwd
 * @param bool $quiet
 * @return int Exit code
 */
function cyphtRun(array $cmd, $cwd, $quiet)
{
	$line = implode(' ', array_map('escapeshellarg', $cmd));
	$descriptors = array(0 => array('pipe', 'r'), 1 => STDOUT, 2 => STDERR);

	if ($quiet) {
		$descriptors[1] = array('pipe', 'w');
	}

	$pipes = array();
	$proc = @proc_open($line, $descriptors, $pipes, $cwd);
	if (!is_resource($proc)) {
		fwrite(STDERR, "Could not start: ".$line."\n");
		return 1;
	}

	if (isset($pipes[0])) {
		fclose($pipes[0]);
	}
	if ($quiet && isset($pipes[1])) {
		stream_get_contents($pipes[1]);
		fclose($pipes[1]);
	}

	return proc_close($proc);
}

$masterIncPath = '';

if ($options['dolibarr'] !== '') {
	$masterIncPath = cyphtResolveDolibarr($options['dolibarr']);
	if ($masterIncPath === null) {
		fwrite(STDERR, "No Dolibarr at: ".$options['dolibarr']."\n");
		fwrite(STDERR, "Expected master.inc.php there, in htdocs/ below it, or that file itself.\n");
		exit(1);
	}
	if ($options['mode'] === 'prepare') {
		fwrite(STDERR, "--dolibarr is ignored with --prepare, which needs no Dolibarr.\n");
	}
}

require_once $root.'/class/install/paths.class.php';
require_once $root.'/class/install/vendorlayout.class.php';
require_once $root.'/class/install/moduleinstaller.class.php';
require_once $root.'/class/install/upstreampatches.class.php';

$paths = new CyphtPaths();
$vendorLayout = new CyphtVendorLayout($paths);
$installer = new CyphtModuleInstaller($paths);
$patches = new CyphtUpstreamPatches($paths);

if ($options['mode'] === 'prepare') {
	cyphtPrepare($root, $options, $vendorLayout, $installer, $patches);

	$paths->writeBuildInfo();

	cyphtSay("\nPrepared. Dependencies and module sets are in place.\n", $options['quiet']);
	cyphtSay("The app itself is not compiled yet. Run a full build to produce public/.\n", $options['quiet']);
	exit(0);
}

/**
 * Look for Dolibarr's master.inc.php in each parent directory of the module
 * (and in their htdocs/ folder).
 *
 * @param string $start Module root
 * @return string Path to master.inc.php, or '' if not found
 */
function cyphtFindDolibarr($start)
{
	$dir = $start;

	while (true) {
		foreach (array($dir.'/master.inc.php', $dir.'/htdocs/master.inc.php') as $candidate) {
			if (is_file($candidate)) {
				return $candidate;
			}
		}

		$parent = dirname($dir);
		if ($parent === $dir) {
			return '';
		}
		$dir = $parent;
	}
}

/**
 * Install dependencies and our Cypht module sets. Exits on failure.
 *
 * @param string $root Module root
 * @param array<string,mixed> $options Parsed command line
 * @param CyphtVendorLayout $vendorLayout
 * @param CyphtModuleInstaller $installer
 * @param CyphtUpstreamPatches $patches
 * @return void
 */
function cyphtPrepare($root, array $options, $vendorLayout, $installer, $patches)
{
	$composer = cyphtFindComposer($root);

	if ($composer === null) {
		if (!is_dir($root.'/vendor/jason-munro/cypht')) {
			fwrite(STDERR, "Composer not found and vendor/jason-munro/cypht is missing.\n");
			fwrite(STDERR, "Install Composer, or drop a composer.phar in ".$root."\n");
			exit(1);
		}
		cyphtSay("Composer not found; using the vendor/ already on disk.\n", $options['quiet']);
	} else {
		cyphtSay("== Dependencies ==\n", $options['quiet']);
		$composerArgs = array('install', '--no-interaction', '--no-progress');
		if ($options['quiet']) {
			$composerArgs[] = '--quiet';
		}
		$code = cyphtRun(array_merge($composer, $composerArgs), $root, $options['quiet']);
		if ($code !== 0) {
			fwrite(STDERR, "composer install failed (exit ".$code.").\n");
			exit(1);
		}
	}

	if (!is_dir($root.'/vendor/jason-munro/cypht')) {
		fwrite(STDERR, "vendor/jason-munro/cypht is still missing after install.\n");
		exit(1);
	}

	cyphtSay("\n== Cypht module sets ==\n", $options['quiet']);

	if (!$vendorLayout->ensureCyphtVendorBridge()) {
		fwrite(STDERR, $vendorLayout->error."\n");
		exit(1);
	}
	if (!$installer->installAll()) {
		fwrite(STDERR, $installer->error."\n");
		exit(1);
	}
	cyphtSay("installed: ".implode(', ', $installer->listModuleSets())."\n", $options['quiet']);

	if (!$patches->patchCoreFunctionsGuard()) {
		fwrite(STDERR, $patches->error."\n");
		exit(1);
	}
}

if (!is_dir($root.'/vendor/jason-munro/cypht')) {
	cyphtPrepare($root, $options, $vendorLayout, $installer, $patches);
}

if ($masterIncPath === '') {
	$masterIncPath = cyphtFindDolibarr($root);
}

if ($masterIncPath === '') {
	cyphtSay("\n== Offline build ==\n", $options['quiet']);
	cyphtSay("No Dolibarr found, compiling with build defaults only.\n", $options['quiet']);

	require_once $root.'/class/install/environment.class.php';
	require_once $root.'/class/install/pipeline.class.php';

	$envError = '';
	if (!CyphtEnvironment::writeEnvTo($paths->getCyphtPath(), CyphtEnvironment::buildTimeDefaults(), $envError)) {
		fwrite(STDERR, $envError."\n");
		exit(1);
	}
	if (!CyphtEnvironment::writeConfigTo($paths->getCyphtPath(), $envError)) {
		fwrite(STDERR, $envError."\n");
		exit(1);
	}
	cyphtSay("wrote build defaults to vendor/jason-munro/cypht/.env and config/zz_dolibarr.php\n", $options['quiet']);

	$offline = new CyphtPipeline(null, $paths, null, $vendorLayout, null, $patches, $installer);

	$genResult = $offline->runConfigGen(function ($chunk) use ($options) {
		cyphtSay($chunk, $options['quiet']);
	});
	if (empty($genResult['success'])) {
		fwrite(STDERR, "\n".(isset($genResult['error']) ? $genResult['error'] : 'config_gen failed')."\n");
		exit(1);
	}

	exit(0);
}

require_once $masterIncPath;

require_once $root.'/class/webmail.class.php';

global $db, $conf;

if (!isModEnabled('cyphtwebmail')) {
	fwrite(STDERR, "warning: the cyphtWebmail module is not enabled yet in Dolibarr.\n");
}

$webmail = new CyphtWebmail($db);

cyphtSay("\n== Build ==\n", $options['quiet']);

$result = $webmail->runConfigGen(function ($chunk, $type) use ($options) {
	if ($options['quiet'] && $type !== 'err') {
		return;
	}
	fwrite($type === 'err' ? STDERR : STDOUT, $chunk);
});

if (empty($result['success'])) {
	fwrite(STDERR, "\nBuild failed: ".$result['error']."\n");
	exit(1);
}

/* Make sure the web server can write what the build created. */
if ($options['permissions']) {
	$writableDirs = array(
		$webmail->getPublicPath(),
		$webmail->getDataDir(),
		$webmail->getDataDir().'/users',
		$webmail->getDataDir().'/attachments',
		$webmail->getDataDir().'/sso_sessions',
	);
	$writableFiles = array(
		$webmail->getCyphtPath().'/.env',
	);

	$posix = (DIRECTORY_SEPARATOR === '/');

	if (!$posix && ($options['owner'] !== '' || $options['group'] !== '')) {
		cyphtSay("\n--owner/--group ignored: this platform uses ACLs, not POSIX ownership.\n", $options['quiet']);
	}

	$warnings = array();
	$targets = array();

	foreach ($writableDirs as $target) {
		if (!is_dir($target) && !@mkdir($target, 0770, true) && !is_dir($target)) {
			$warnings[] = 'could not create '.$target;
			continue;
		}
		$targets[] = $target;
	}

	foreach ($writableFiles as $target) {
		if (!is_file($target)) {
			$warnings[] = 'missing, skipped: '.$target;
			continue;
		}
		$targets[] = $target;
	}

	foreach ($targets as $target) {
		if (!@chmod($target, is_dir($target) ? 0770 : 0660)) {
			$warnings[] = 'could not chmod '.$target;
		}
		if ($posix && $options['owner'] !== '' && !@chown($target, $options['owner'])) {
			$warnings[] = 'could not chown '.$target;
		}
		if ($posix && $options['group'] !== '' && !@chgrp($target, $options['group'])) {
			$warnings[] = 'could not chgrp '.$target;
		}
	}

	cyphtSay("\nPermissions checked on ".count($targets)." paths.\n", $options['quiet']);

	foreach ($warnings as $message) {
		fwrite(STDERR, "  warning: ".$message."\n");
	}

	if ($posix && $options['owner'] === '' && function_exists('posix_geteuid') && posix_geteuid() === 0) {
		fwrite(STDERR, "\n  warning: running as root without --owner. Everything just written is\n");
		fwrite(STDERR, "           owned by root; the webserver will not be able to write to it.\n");
	}
}

$db->close();
exit(0);
