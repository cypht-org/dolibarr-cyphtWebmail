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
 * \file        scripts/bridge_probe.php
 * \ingroup     cyphtWebmail
 * \brief       Calls a bridge endpoint the way Cypht calls it, from the
 *              command line.
 *
 *              A bridge fault and a Cypht fault look identical from the
 *              webmail: an empty panel. This mints the same signed token the
 *              module set mints, calls the same URL, and prints what came
 *              back, so the two can be told apart before any of the browser
 *              is involved.
 *
 *              Run it against the *deployed* copy under Dolibarr's custom
 *              directory, not the source tree: the source tree has no .env,
 *              because the secret and the URLs are written at build time.
 *
 * Usage:
 *   php scripts/bridge_probe.php --endpoint=context --login=admin --email=a@b.com
 *   php scripts/bridge_probe.php --endpoint=contacts --login=admin
 *   php scripts/bridge_probe.php --endpoint=templates --login=admin
 *
 *   --url-only   print the signed URL and stop, to paste into a browser
 *   --raw        print the response body exactly as it arrived
 *
 * A token is good for 60 seconds. A URL printed with --url-only and pasted a
 * minute later is expected to answer "Token expired"; that is the anti-replay
 * window working, not a fault.
 */

if (PHP_SAPI !== 'cli') {
	print "This script is for the command line only.\n";
	exit(1);
}

/**
 * Endpoints, each with the .env key holding its URL and the purpose tag that
 * has to be inside its signature. The tag is what stops a token minted for one
 * endpoint being replayed against another, so it is per endpoint by design.
 */
$endpoints = array(
	'contacts' => array(
		'url_key' => 'DOLIBARR_CONTACTS_URL',
		'purpose' => 'contacts',
		'insecure_key' => 'DOLIBARR_CONTACTS_INSECURE',
		'timeout_key' => 'DOLIBARR_CONTACTS_TIMEOUT',
	),
	'templates' => array(
		'url_key' => 'DOLIBARR_MAIL_TEMPLATES_URL',
		'purpose' => 'templates',
		'insecure_key' => 'DOLIBARR_MAIL_TEMPLATES_INSECURE',
		'timeout_key' => 'DOLIBARR_MAIL_TEMPLATES_TIMEOUT',
	),
	'context' => array(
		'url_key' => 'DOLIBARR_CONTEXT_URL',
		'purpose' => 'context',
		'insecure_key' => 'DOLIBARR_CONTEXT_INSECURE',
		'timeout_key' => 'DOLIBARR_CONTEXT_TIMEOUT',
	),
);

/**
 * Read --key=value arguments into an array.
 *
 * @param array $argv Raw arguments
 * @return array<string,string>
 */
function probeArgs($argv)
{
	$out = array();
	foreach (array_slice($argv, 1) as $arg) {
		if (strpos($arg, '--') !== 0) {
			continue;
		}
		$arg = substr($arg, 2);
		if (strpos($arg, '=') === false) {
			$out[$arg] = '1';
			continue;
		}
		list($key, $value) = explode('=', $arg, 2);
		$out[$key] = $value;
	}
	return $out;
}

/**
 * Parse a .env file into key/value pairs. Deliberately small: this only has to
 * read the file this module writes, which never quotes or continues lines.
 *
 * @param string $path Path to the .env
 * @return array<string,string>|null Null when unreadable
 */
function probeEnv($path)
{
	if (!is_readable($path)) {
		return null;
	}

	$lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
	if ($lines === false) {
		return null;
	}

	$env = array();
	foreach ($lines as $line) {
		$line = trim($line);
		if ($line === '' || $line[0] === '#') {
			continue;
		}
		if (!preg_match('/^([A-Z0-9_]+)=(.*)$/', $line, $m)) {
			continue;
		}
		$env[$m[1]] = trim($m[2], "\"'");
	}

	return $env;
}

$args = probeArgs($argv);

$name = isset($args['endpoint']) ? $args['endpoint'] : 'context';
if (!isset($endpoints[$name])) {
	print "Unknown endpoint '".$name."'. Known: ".implode(', ', array_keys($endpoints))."\n";
	exit(1);
}
$endpoint = $endpoints[$name];

$login = isset($args['login']) ? $args['login'] : '';
if ($login === '') {
	print "Missing --login. This is the Dolibarr username the token is minted for.\n";
	exit(1);
}

$email = isset($args['email']) ? $args['email'] : '';
if ($name === 'context' && $email === '') {
	print "Missing --email. The context endpoint answers questions about one address.\n";
	exit(1);
}

// The .env is written into the vendored Cypht at build time, which is the only
// place both the secret and the resolved URLs exist together.
$moduleRoot = dirname(__DIR__);
$envPath = $moduleRoot.'/vendor/jason-munro/cypht/.env';
$env = probeEnv($envPath);

if ($env === null) {
	print "Could not read ".$envPath."\n\n";
	print "Either this is the source tree rather than the deployed module, or\n";
	print "the build has not run yet, or the file is owned by the web server\n";
	print "user and this shell is not it.\n";
	exit(1);
}

$secret = isset($env['SSO_SHARED_SECRET']) ? $env['SSO_SHARED_SECRET'] : '';
if ($secret === '') {
	print "SSO_SHARED_SECRET is missing from ".$envPath.". Run the build.\n";
	exit(1);
}

$url = isset($env[$endpoint['url_key']]) ? $env[$endpoint['url_key']] : '';
if ($url === '') {
	print $endpoint['url_key']." is missing from ".$envPath.".\n\n";
	print "For a module set added since the last build, this is the expected\n";
	print "symptom: the key is written by CyphtEnvironment, so it appears only\n";
	print "after the build has run again.\n";
	exit(1);
}

$timestamp = time();
$signature = hash_hmac('sha256', $login.'|'.$timestamp.'|'.$endpoint['purpose'], $secret);

$query = array(
	'login' => $login,
	'token' => $timestamp.'.'.$signature,
);
if ($email !== '') {
	$query['email'] = $email;
}

$signed = $url.(strpos($url, '?') === false ? '?' : '&').http_build_query($query);

print "endpoint : ".$name." (purpose tag '".$endpoint['purpose']."')\n";
print "url      : ".$url."\n";
print "login    : ".$login."\n";
if ($email !== '') {
	print "email    : ".$email."\n";
}
print "\n";

if (isset($args['url-only'])) {
	print $signed."\n\n";
	print "Valid for 60 seconds from now.\n";
	exit(0);
}

if (!function_exists('curl_init')) {
	print "No curl in this PHP. Re-run with --url-only and paste the URL into a browser.\n";
	exit(1);
}

$timeout = isset($env[$endpoint['timeout_key']]) ? (int) $env[$endpoint['timeout_key']] : 5;

$ch = curl_init($signed);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, $timeout > 0 ? $timeout : 5);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
if (isset($env[$endpoint['insecure_key']]) && $env[$endpoint['insecure_key']] === 'true') {
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
}

$body = curl_exec($ch);
$status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

if ($body === false) {
	print "transport failed: ".$err."\n\n";
	print "Dolibarr could not be reached at that URL from this machine. If the\n";
	print "webmail shows the same, set CYPHTWEBMAIL_BRIDGE_URL (or the endpoint's\n";
	print "own override) to a URL the webserver can reach itself on.\n";
	exit(1);
}

print "HTTP ".$status."\n\n";

if (isset($args['raw'])) {
	print $body."\n";
	exit($status === 200 ? 0 : 1);
}

$decoded = json_decode($body, true);
if ($decoded === null) {
	print "Response was not JSON. First 500 bytes:\n\n";
	print substr($body, 0, 500)."\n\n";
	print "A PHP notice or a Dolibarr error page ahead of the JSON is the usual\n";
	print "cause. Check the web server error log.\n";
	exit(1);
}

print json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";

if ($status !== 200) {
	exit(1);
}

// A short reading of the answer, since the interesting failures are all 200s:
// the endpoint worked and still found nothing.
if ($name === 'context') {
	if (empty($decoded['match'])) {
		print "\nNo Dolibarr record owns that address. The panel will say so.\n";
	} else {
		print "\nMatched a ".$decoded['match']['type'].", with ".count($decoded['blocks'])." block(s).\n";
		if (count($decoded['blocks']) === 0 && !empty($decoded['thirdparty'])) {
			print "No blocks means nothing is open against them, or this user\n";
			print "cannot read the modules that would have filled them.\n";
		}
	}
}

exit(0);
