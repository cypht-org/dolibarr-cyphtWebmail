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
 */

if (PHP_SAPI !== 'cli') {
	print "This script is for the command line only.\n";
	exit(1);
}

/**
 * Endpoints, each with the environment key holding its URL and the purpose tag
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
 * One environment value, however CyphtEnvBootstrap happened to publish it.
 * @param string $key Environment key
 * @return string Empty when unset
 */
function probeEnv($key)
{
	if (array_key_exists($key, $_ENV) && $_ENV[$key] !== '') {
		return (string) $_ENV[$key];
	}

	$value = getenv($key);

	return ($value === false) ? '' : (string) $value;
}

$args = probeArgs($argv);

$moduleRoot = dirname(__DIR__);

$bootstrapPath = $moduleRoot.'/class/runtime/envbootstrap.class.php';
if (!is_readable($bootstrapPath)) {
	print "Could not read ".$bootstrapPath."\n";
	print "Run this from inside the module, deployed under Dolibarr's custom directory.\n";
	exit(1);
}

require_once $bootstrapPath;

$bootstrap = new CyphtEnvBootstrap($moduleRoot);
$ready = $bootstrap->apply();

if (!$ready) {
	print "CyphtEnvBootstrap could not finish.\n\n";
	print "  ".($bootstrap->error !== '' ? $bootstrap->error : 'No detail reported.')."\n\n";
	print "Everything per installation, the shared secret included, is read\n";
	print "from Dolibarr at request time rather than written into the built\n";
	print ".env. If this cannot reach conf.php or the database, neither can\n";
	print "the webmail, and that is the fault to fix first.\n";
	exit(1);
}

if (isset($args['env'])) {
	$secret = probeEnv('SSO_SHARED_SECRET');
	print "resolved configuration\n\n";
	print "  SSO_SHARED_SECRET  ".($secret !== '' ? strlen($secret)." chars" : "MISSING")."\n";
	foreach ($endpoints as $name => $spec) {
		$url = probeEnv($spec['url_key']);
		printf("  %-18s %s\n", $name, ($url !== '' ? $url : 'MISSING ('.$spec['url_key'].')'));
	}
	exit(0);
}

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

$secret = probeEnv('SSO_SHARED_SECRET');
if ($secret === '') {
	print "SSO_SHARED_SECRET is not set after bootstrap.\n\n";
	print "It lives in the module's own config table, written when the module\n";
	print "was activated. Switch the module off and on again if it is missing.\n";
	exit(1);
}

$url = probeEnv($endpoint['url_key']);
if ($url === '') {
	print $endpoint['url_key']." is not set after bootstrap.\n\n";
	print "For an endpoint added since the module was last deployed, this is\n";
	print "the expected symptom: the key is published by CyphtEnvBootstrap, so\n";
	print "it appears only once the updated class/runtime/envbootstrap.class.php\n";
	print "is the one being read.\n";
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

$timeout = (int) probeEnv($endpoint['timeout_key']);

$ch = curl_init($signed);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, $timeout > 0 ? $timeout : 5);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
if (probeEnv($endpoint['insecure_key']) === 'true') {
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

// The interesting failures are 200s, so read the answer out.
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
