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
 * \file        index.php
 * \ingroup     cyphtWebmail
 * \brief       Webmail page. Logs the user into Cypht (SSO) and shows it in
 *              an iframe.
 */

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;

if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once __DIR__.'/class/webmail.class.php';

global $conf, $db, $langs, $user;

$langs->loadLangs(array("cyphtwebmail@cyphtwebmail"));

if (!isModEnabled('cyphtwebmail')) {
	accessforbidden();
}

$manager = new CyphtWebmail($db);

require_once __DIR__.'/class/install/upgrade.class.php';
$cyphtUpgrade = new CyphtUpgrade($db);
if (!$cyphtUpgrade->run()) {
	dol_syslog('CyphtWebmail upgrade check: '.$cyphtUpgrade->error, LOG_WARNING);
}

require_once __DIR__.'/class/install/paths.class.php';
require_once __DIR__.'/class/integration/contactcache.class.php';
require_once __DIR__.'/class/integration/mailtemplatecache.class.php';
$cyphtPaths = new CyphtPaths();

$cyphtContactCache = new CyphtContactCache($cyphtPaths);
if (!$cyphtContactCache->refresh($db, $user)) {
	dol_syslog('CyphtWebmail contact cache: '.$cyphtContactCache->error, LOG_WARNING);
}

$cyphtTemplateCache = new CyphtMailTemplateCache($cyphtPaths);
if (!$cyphtTemplateCache->refresh($db, $user, $langs)) {
	dol_syslog('CyphtWebmail mail template cache: '.$cyphtTemplateCache->error, LOG_WARNING);
}

$cyphtQuery = GETPOST('cypht', 'none');
if (!is_string($cyphtQuery) || !preg_match('/^[A-Za-z0-9_\-\.=&%+]{0,300}$/', $cyphtQuery)) {
	$cyphtQuery = '';
}

// Must run before llxHeader(), since SSO login sets cookies.
$ssoOk = false;
$publicUrl = '';
if ($manager->isPublished()) {
	$publicUrl = dol_buildpath('/cyphtwebmail/public/index.php', 1);
	$ssoOk = $manager->performSsoLogin($user->login, $publicUrl, $langs->defaultlang);
}

llxHeader('', $langs->trans("CyphtWebmailArea"), '', '', 0, 0, '', '', '', 'mod-cyphtwebmail page-index');

if (!$manager->isPublished()) {
	print '<div class="warning" style="padding: 15px;">';
	print $langs->trans("CyphtWebmailNotYetBuilt");
	print ' <a href="'.dol_buildpath('/cyphtwebmail/admin/setup.php', 1).'">';
	print $langs->trans("CyphtWebmailGoToSetup");
	print '</a>';
	print '</div>';
} else {
	if (!$ssoOk && $manager->error) {
		print '<div class="warning" style="padding: 15px;">'.dol_escape_htmltag($manager->error).'</div>';
	}
	$frameUrl = $publicUrl.($cyphtQuery !== '' ? '?'.$cyphtQuery : '');

	print '<iframe id="cyphtwebmail-frame" src="'.dol_escape_htmltag($frameUrl).'" '.
		'style="display: block; width: 100%; height: calc(100vh - 220px); min-height: 400px; border: none;" '.
		'title="Cypht Webmail"></iframe>';

	foreach (array('cypht-frame-size.js', 'cypht-url-sync.js') as $script) {
		$version = @filemtime(__DIR__.'/js/'.$script);
		print '<script src="'.dol_escape_htmltag(dol_buildpath('/cyphtwebmail/js/'.$script, 1).($version ? '?v='.$version : '')).'"></script>';
	}
}

llxFooter();
$db->close();
