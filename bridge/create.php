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
 * \file        bridge/create.php
 * \brief       Turn the sender of an email into a Dolibarr prospect.
 *              THE FIRST WRITE ENDPOINT. Everything under bridge/ before this
 */

// This is a machine-to-machine JSON endpoint: no session, no menus, no CSRF.
if (!defined('NOLOGIN')) {
	define('NOLOGIN', '1');
}
if (!defined('NOCSRFCHECK')) {
	define('NOCSRFCHECK', '1');
}
if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', '1');
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', '1');
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', '1');
}
if (!defined('NOIPCHECK')) {
	define('NOIPCHECK', '1');
}
if (!defined('NOBROWSERNOTIF')) {
	define('NOBROWSERNOTIF', '1');
}

// Load Dolibarr environment (module lives at htdocs/custom/cyphtWebmail/bridge).
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res && file_exists("../../../../main.inc.php")) {
	$res = @include "../../../../main.inc.php";
}
if (!$res) {
	http_response_code(500);
	header('Content-Type: application/json');
	print json_encode(array('error' => 'Include of main fails'));
	exit;
}

/**
 * Emit a JSON response and stop.
 * @param int   $status HTTP status code
 * @param array $body   Payload to encode
 * @return void
 */
function cyphtCreateRespond($status, $body)
{
	global $db;

	http_response_code($status);
	header('Content-Type: application/json; charset=utf-8');
	header('Cache-Control: no-store, no-cache, must-revalidate');
	header('X-Content-Type-Options: nosniff');
	print json_encode($body);

	if (is_object($db)) {
		$db->close();
	}
	exit;
}

/**
 * Absolute URL of a Dolibarr page. Absolute, not root relative, because the
 * @param string $path  Path below htdocs, leading slash included
 * @param string $query Query string without the leading '?'
 * @return string
 */
function cyphtCreateUrl($path, $query = '')
{
	$url = dol_buildpath($path, 2);
	if ($query !== '') {
		$url .= (strpos($url, '?') === false ? '?' : '&').$query;
	}
	return $url;
}

global $conf, $db, $langs;

if (!isModEnabled('cyphtwebmail')) {
	cyphtCreateRespond(403, array('error' => 'Module not enabled'));
}

// POST only, checked before any input is read.
if (empty($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
	header('Allow: POST');
	cyphtCreateRespond(405, array('error' => 'POST only'));
}

$login = GETPOST('login', 'aZ09arobase');
$token = GETPOST('token', 'aZ09');
$email = trim(GETPOST('email', 'nohtml'));
$name = trim(GETPOST('name', 'alphanohtml'));

if ($login === '' || $token === '') {
	cyphtCreateRespond(400, array('error' => 'Missing login or token'));
}

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
	cyphtCreateRespond(400, array('error' => 'Missing or malformed email'));
}

// --- 1. Token ---------------------------------------------------------

require_once __DIR__.'/../class/install/config.class.php';
$secret = CyphtConfig::get($db, 'SSO_SHARED_SECRET', '');
if ($secret === '') {
	cyphtCreateRespond(503, array('error' => 'SSO secret not initialised, run the module build first'));
}

if (strpos($token, '.') === false) {
	cyphtCreateRespond(403, array('error' => 'Malformed token'));
}

list($timestamp, $signature) = explode('.', $token, 2);
if (!ctype_digit($timestamp)) {
	cyphtCreateRespond(403, array('error' => 'Malformed token'));
}

if (abs(time() - (int) $timestamp) > 60) {
	cyphtCreateRespond(403, array('error' => 'Token expired'));
}

// Purpose tag stops a read token being replayed against this write.
$expected = hash_hmac('sha256', $login.'|'.$timestamp.'|create', $secret);
if (!hash_equals($expected, $signature)) {
	cyphtCreateRespond(403, array('error' => 'Bad signature'));
}

// --- 2. User ----------------------------------------------------------

$bridgeUser = new User($db);
if ($bridgeUser->fetch(0, $login) <= 0) {
	cyphtCreateRespond(403, array('error' => 'Unknown user'));
}
if (isset($bridgeUser->statut) && $bridgeUser->statut == 0) {
	cyphtCreateRespond(403, array('error' => 'Disabled user'));
}
$bridgeUser->loadRights();

$conf->entity = ($bridgeUser->entity > 0 ? $bridgeUser->entity : 1);

// Module permission, then Dolibarr's own right. Both must pass.
if (!$bridgeUser->hasRight('cyphtwebmail', 'context', 'create')) {
	cyphtCreateRespond(403, array('error' => 'User is not allowed to create records from the webmail'));
}

// The acting user's right, not the web server's.
if (!$bridgeUser->hasRight('societe', 'creer')) {
	cyphtCreateRespond(403, array('error' => 'User cannot create third parties'));
}

// --- 3. Already there? ------------------------------------------------

// Idempotent on the address: checked before the insert.
$escaped = "'".$db->escape($email)."'";

$sql = "SELECT sp.rowid FROM ".MAIN_DB_PREFIX."socpeople as sp";
$sql .= " WHERE sp.entity IN (".getEntity('contact').")";
$sql .= " AND sp.email = ".$escaped;
$sql .= $db->plimit(1, 0);

$resql = $db->query($sql);
if ($resql && ($obj = $db->fetch_object($resql))) {
	$db->free($resql);
	cyphtCreateRespond(200, array(
		'created' => false,
		'existing' => true,
		'type' => 'contact',
		'id' => (int) $obj->rowid,
		'url' => cyphtCreateUrl('/contact/card.php', 'id='.((int) $obj->rowid)),
	));
}
if ($resql) {
	$db->free($resql);
}

$sql = "SELECT s.rowid FROM ".MAIN_DB_PREFIX."societe as s";
$sql .= " WHERE s.entity IN (".getEntity('societe').")";
$sql .= " AND s.email = ".$escaped;
$sql .= $db->plimit(1, 0);

$resql = $db->query($sql);
if ($resql && ($obj = $db->fetch_object($resql))) {
	$db->free($resql);
	cyphtCreateRespond(200, array(
		'created' => false,
		'existing' => true,
		'type' => 'thirdparty',
		'id' => (int) $obj->rowid,
		'url' => cyphtCreateUrl('/societe/card.php', 'socid='.((int) $obj->rowid)),
	));
}
if ($resql) {
	$db->free($resql);
}

// --- 4. Create --------------------------------------------------------

$langs->loadLangs(array('companies', 'errors'));

// No display name on the From header: fall back to the local part.
if ($name === '') {
	$name = substr($email, 0, strpos($email, '@'));
}
// llx_societe.nom is varchar(128).
$name = substr($name, 0, 128);

$soc = new Societe($db);
$soc->name = $name;
$soc->email = $email;
// Prospect, not customer: they have only emailed once.
$soc->client = Societe::PROSPECT;
$soc->fournisseur = 0;
$soc->status = 1;
$soc->entity = $conf->entity;

$db->begin();

$id = $soc->create($bridgeUser);

if ($id <= 0) {
	$db->rollback();

	$message = $soc->error;
	if ($message === '' && !empty($soc->errors)) {
		$message = implode(', ', $soc->errors);
	}
	dol_syslog("cyphtWebmail create: Societe::create failed for ".$email.": ".$message, LOG_ERR);

	cyphtCreateRespond(500, array(
		'error' => ($message !== '' ? $langs->trans($message) : 'Could not create the third party'),
	));
}

$db->commit();

dol_syslog("cyphtWebmail create: third party ".$id." created from webmail for ".$email." by ".$login);

// URL travels back so the dialog can link to the record.
cyphtCreateRespond(200, array(
	'created' => true,
	'existing' => false,
	'type' => 'thirdparty',
	'id' => (int) $id,
	'name' => $name,
	'url' => cyphtCreateUrl('/societe/card.php', 'socid='.((int) $id)),
));
