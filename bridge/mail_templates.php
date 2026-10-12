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
 * \file        bridge/mail_templates.php
 * \brief       Returns the email templates the user can see, as JSON, for
 *              the Cypht compose screen.
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
function cyphtMailTemplatesRespond($status, $body)
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

global $conf, $db;

if (!isModEnabled('cyphtwebmail')) {
	cyphtMailTemplatesRespond(403, array('error' => 'Module not enabled'));
}

$login = GETPOST('login', 'aZ09arobase');
$token = GETPOST('token', 'aZ09');


// ---------------------------------------------------------------------

require_once __DIR__.'/../class/integration/bridgeauth.class.php';

if ($token === '') {
	/* Session cookie */
	global $user;
	if (empty($user->id)) {
		cyphtMailTemplatesRespond(403, array('error' => 'Not signed in'));
	}
	$bridgeUser = $user;
} else {
	$authStatus = 403;
	$authError = '';
	$bridgeUser = CyphtBridgeAuth::userFromToken($db, $login, $token, 'templates', $authStatus, $authError);
	if ($bridgeUser === null) {
		cyphtMailTemplatesRespond($authStatus, array('error' => $authError));
	}
}

$bridgeUser->loadRights();

$conf->entity = ($bridgeUser->entity > 0 ? $bridgeUser->entity : 1);

// ---------------------------------------------------------------------

require_once __DIR__.'/../class/integration/mailtemplatecollector.class.php';

$collectError = '';
$result = CyphtMailTemplateCollector::collect($db, $bridgeUser, $langs, $collectError);
if ($result === null) {
	cyphtMailTemplatesRespond(500, array('error' => $collectError));
}

cyphtMailTemplatesRespond(200, $result);
