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
 * \file        bridge/create.php
 * \brief       Creates a Dolibarr prospect from an email sender.
 */

// Token auth for server-to-server calls
if (!empty($_GET['token']) || !empty($_POST['token'])) {
	if (!defined('NOLOGIN')) {
		define('NOLOGIN', '1');
	}
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

// Load Dolibarr environment
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
 * Send a JSON response and exit.
 *
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
 * Absolute URL of a Dolibarr page.
 *
 * @param string $path  Path below htdocs, starting with a slash
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

if (empty($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
	header('Allow: POST');
	cyphtCreateRespond(405, array('error' => 'POST only'));
}

$login = GETPOST('login', 'aZ09arobase');
$token = GETPOST('token', 'aZ09');
$email = trim(GETPOST('email', 'nohtml'));
$name = trim(GETPOST('name', 'alphanohtml'));


if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
	cyphtCreateRespond(400, array('error' => 'Missing or malformed email'));
}

// --- 1. Authentication ------------------------------------------------

require_once __DIR__.'/../class/integration/bridgeauth.class.php';

if ($token === '') {
	/* Session cookie */
	global $user;
	if (empty($user->id)) {
		cyphtCreateRespond(403, array('error' => 'Not signed in'));
	}
	$bridgeUser = $user;
} else {
	$authStatus = 403;
	$authError = '';
	$bridgeUser = CyphtBridgeAuth::userFromToken($db, $login, $token, 'create', $authStatus, $authError);
	if ($bridgeUser === null) {
		cyphtCreateRespond($authStatus, array('error' => $authError));
	}
}

$bridgeUser->loadRights();

$conf->entity = ($bridgeUser->entity > 0 ? $bridgeUser->entity : 1);

// --- 2. Permissions (module and third party) --------------------------
if (!$bridgeUser->hasRight('cyphtwebmail', 'context', 'create')) {
	cyphtCreateRespond(403, array('error' => 'User is not allowed to create records from the webmail'));
}

if (!$bridgeUser->hasRight('societe', 'creer')) {
	cyphtCreateRespond(403, array('error' => 'User cannot create third parties'));
}

// --- 3. Already exists? -----------------------------------------------

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

if ($name === '') {
	$name = substr($email, 0, strpos($email, '@'));
}
// llx_societe.nom is varchar(128).
$name = substr($name, 0, 128);

$soc = new Societe($db);
$soc->name = $name;
$soc->email = $email;
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

cyphtCreateRespond(200, array(
	'created' => true,
	'existing' => false,
	'type' => 'thirdparty',
	'id' => (int) $id,
	'name' => $name,
	'url' => cyphtCreateUrl('/societe/card.php', 'socid='.((int) $id)),
));
