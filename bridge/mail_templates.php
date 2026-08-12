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
 * \file        bridge/mail_templates.php
 * \brief       Dolibarr email templates, for the compose screen in Cypht.
 *
 *              Visibility follows FormMail::getEMailTemplate() in
 *              htdocs/core/class/html.formmail.class.php: entity scoping plus
 *              "private = 0 OR fk_user = me". That clause is the access
 *              control, not a filter.
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

if ($login === '' || $token === '') {
	cyphtMailTemplatesRespond(400, array('error' => 'Missing login or token'));
}

// ---------------------------------------------------------------------

require_once __DIR__.'/../class/install/config.class.php';
// Not llx_const: dolibarr_set_const() encrypts anything whose name ends
// in _SECRET, and the webmail reads its copy over raw PDO before
// Dolibarr is loaded, so the two ends would sign with different values.
$secret = CyphtConfig::get($db, 'SSO_SHARED_SECRET', '');
if ($secret === '') {
	cyphtMailTemplatesRespond(503, array('error' => 'SSO secret not initialised, run the module build first'));
}

if (strpos($token, '.') === false) {
	cyphtMailTemplatesRespond(403, array('error' => 'Malformed token'));
}

list($timestamp, $signature) = explode('.', $token, 2);
if (!ctype_digit($timestamp)) {
	cyphtMailTemplatesRespond(403, array('error' => 'Malformed token'));
}

if (abs(time() - (int) $timestamp) > 60) {
	cyphtMailTemplatesRespond(403, array('error' => 'Token expired'));
}

// Own purpose tag, so a contacts token cannot be replayed against this
// endpoint and vice versa.
$expected = hash_hmac('sha256', $login.'|'.$timestamp.'|templates', $secret);
if (!hash_equals($expected, $signature)) {
	cyphtMailTemplatesRespond(403, array('error' => 'Bad signature'));
}

// ---------------------------------------------------------------------

$bridgeUser = new User($db);
if ($bridgeUser->fetch(0, $login) <= 0) {
	cyphtMailTemplatesRespond(403, array('error' => 'Unknown user'));
}
if (isset($bridgeUser->statut) && $bridgeUser->statut == 0) {
	cyphtMailTemplatesRespond(403, array('error' => 'Disabled user'));
}
$bridgeUser->loadRights();

// NOLOGIN leaves $conf->entity at its default; realign it with the user so
// getEntity() below filters correctly under Multicompany.
$conf->entity = ($bridgeUser->entity > 0 ? $bridgeUser->entity : 1);

// ---------------------------------------------------------------------

// Miss a domain and trans() echoes the key back, so labels come out as raw
// CamelCase. Template labels are themselves keys, living in the lang file of
// whichever module seeded them.
$langsArray = array('errors', 'admin', 'mails', 'other');
foreach (array(
	'recruitment' => 'recruitment',
	'member' => 'members',
	'eventorganization' => 'eventorganization',
	'holiday' => 'holiday',
	'partnership' => 'partnership',
	'ticket' => 'ticket',
	'project' => 'projects',
	'invoice' => 'bills',
	'supplier_invoice' => 'bills',
	'contract' => 'contracts',
	'propal' => 'propal',
	'order' => 'orders',
	'expensereport' => 'trips',
) as $module => $domain) {
	if (isModEnabled($module) && !in_array($domain, $langsArray, true)) {
		$langsArray[] = $domain;
	}
}
$langs->loadLangs($langsArray);

/**
 * Resolve a template label for display.
 *
 * Labels like '(SendingAdminEmailMessage)' are translation keys. Rule copied
 * from FormMail::getEMailTemplate(), html.formmail.class.php:597.
 *
 * Keys with no en_US translation are split on capitals rather than shown raw
 * as core does.
 *
 * @param string $label Raw label column
 * @param Translate $langs
 * @return string
 */
function cyphtMailTemplateLabel($label, $langs)
{
	$label = trim($label);
	if (!preg_match('/\((.*)\)/', $label, $reg)) {
		return $label;
	}

	$key = $reg[1];
	$translated = $langs->trans($key);
	// trans() echoes the key back when there is no translation for it.
	if ($translated !== $key) {
		return $translated;
	}

	$spaced = preg_replace('/(?<!^)([A-Z])/', ' $1', $key);

	return ucfirst(strtolower($spaced));
}

/**
 * Human label for a type_template token.
 *
 * Map and keys copied from the $elementList block in
 * htdocs/admin/mails_templates.php. Unmapped tokens are prettified, not
 * hidden, so a type this map has not caught up with still shows.
 *
 * @param string $token type_template value
 * @param Translate $langs
 * @return string
 */
function cyphtMailTemplateTypeLabel($token, $langs)
{
	static $map = array(
		'all' => 'All',
		'none' => 'None',
		'user' => 'MailToUser',
		'member' => 'MailToMember',
		'thirdparty' => 'MailToThirdparty',
		'contact' => 'MailToContact',
		'project' => 'MailToProject',
		'contract' => 'MailToSendContract',
		'holiday' => 'MailToSendLeaves',
		'ticket_send' => 'MailToTicket',
		'propal_send' => 'MailToSendProposal',
		'order_send' => 'MailToSendOrder',
		'facture_send' => 'MailToSendInvoice',
		'shipping_send' => 'MailToSendShipment',
		'delivery_send' => 'MailToSendDelivery',
		'reception_send' => 'MailToSendReception',
		'fichinter_send' => 'MailToSendIntervention',
		'actioncomm_send' => 'MailToSendEventPush',
		'expensereport_send' => 'MailToSendExpenseReport',
		'supplier_proposal_send' => 'MailToSendSupplierRequestForQuotation',
		'order_supplier_send' => 'MailToSendSupplierOrder',
		'invoice_supplier_send' => 'MailToSendSupplierInvoice',
		'supplier_payment_send' => 'SuppliersPayment',
		'recruitmentcandidature_send' => 'RecruitmentCandidatures',
		'conferenceorbooth' => 'MailToSendEventOrganization',
	);

	if (isset($map[$token])) {
		$translated = $langs->trans($map[$token]);
		// trans() echoes the key back when there is no translation.
		if ($translated !== $map[$token]) {
			return $translated;
		}
	}

	return ucfirst(str_replace('_', ' ', $token));
}

// Every active template this user may see, of every type. Ordered by type so
// the grouping in the picker follows the query rather than being re-sorted.
$sql = "SELECT rowid, label, type_template, lang, position, topic, content,";
$sql .= " email_from, email_to, email_tocc, email_tobcc";
$sql .= " FROM ".MAIN_DB_PREFIX."c_email_templates";
$sql .= " WHERE entity IN (".getEntity('c_email_templates').")";
$sql .= " AND active = 1";
// The access control. Copied from FormMail::getEMailTemplate().
$sql .= " AND (private = 0 OR fk_user = ".((int) $bridgeUser->id).")";
$sql .= " ORDER BY type_template ASC, position ASC, label ASC";

$resql = $db->query($sql);
if (!$resql) {
	cyphtMailTemplatesRespond(500, array('error' => 'Template query failed: '.$db->lasterror()));
}

// Resolves only object-free markers. Anything written around an object, such
// as __TICKET_URL__, cannot resolve here because compose has no such object;
// those are reported in 'placeholders' rather than stripped.
$substitutions = getCommonSubstitutionArray($langs, 0, null, null);

$templates = array();
$types = array();
while ($obj = $db->fetch_object($resql)) {
	$type = (string) $obj->type_template;
	$subject = make_substitutions((string) $obj->topic, $substitutions, $langs);
	$body = make_substitutions((string) $obj->content, $substitutions, $langs);

	// What survived substitution, so the compose screen can warn before the
	// user sends an email containing a literal __SOMETHING__.
	$leftover = array();
	if (preg_match_all('/__[A-Z0-9_]+__/', $subject.' '.$body, $m)) {
		$leftover = array_values(array_unique($m[0]));
	}

	$templates[] = array(
		'id'      => (int) $obj->rowid,
		'label'   => cyphtMailTemplateLabel((string) $obj->label, $langs),
		'lang'    => (string) $obj->lang,
		'type'    => $type,
		'type_label' => cyphtMailTemplateTypeLabel($type, $langs),
		'subject' => $subject,
		'body'    => $body,
		'placeholders' => $leftover,
		'from'    => (string) $obj->email_from,
		'to'      => (string) $obj->email_to,
		'cc'      => (string) $obj->email_tocc,
		'bcc'     => (string) $obj->email_tobcc,
	);

	if (!isset($types[$type])) {
		$types[$type] = array(
			'type'  => $type,
			'label' => cyphtMailTemplateTypeLabel($type, $langs),
			'count' => 0,
		);
	}
	$types[$type]['count']++;
}
$db->free($resql);

cyphtMailTemplatesRespond(200, array(
	'templates' => $templates,
	'types'     => array_values($types),
	'count'     => count($templates),
	'entity'    => (int) $conf->entity,
	// The list is legitimately empty on a fresh install, which looks like a
	// fault from the Cypht side. Say so instead.
	'hint'      => (count($templates) === 0
		? 'No email templates yet. Create one in Dolibarr under Home, Setup, Emails, Email templates.'
		: ''),
));
