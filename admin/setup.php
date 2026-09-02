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
 * \file        admin/setup.php
 * \ingroup     cyphtWebmail
 * \brief       Setup page: webmail settings, build status and the
 *              "Generate / Rebuild" button that runs Cypht's config_gen.php.
 */

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res && file_exists("../../../../main.inc.php")) {
	$res = @include "../../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once __DIR__.'/../class/webmail.class.php';

global $conf, $db, $langs, $user;

if (!$user->admin) {
	accessforbidden();
}

$langs->loadLangs(array("admin", "cyphtwebmail@cyphtwebmail"));

/**
 * The record kinds the sender panel can show, in display order.
 *
 * @return string[]
 */
function cyphtContextBlockKeys()
{
	return array('propal', 'order', 'invoice', 'ticket', 'project');
}

$action = GETPOST('action', 'aZ09');
// Two tabs, so the settings are not buried under the build report.
$tab = GETPOST('tab', 'aZ09');
if ($tab !== 'build') {
	$tab = 'settings';
}
$manager = new CyphtWebmail($db);
$buildResult = null;

// Same self-triggering upgrade check as the webmail entry point: an admin
// opening this page after replacing the files should not have to know that
// disabling and re-enabling the module is what applies them.
require_once __DIR__.'/../class/install/upgrade.class.php';
$cyphtUpgrade = new CyphtUpgrade($db);
if (!$cyphtUpgrade->run()) {
	setEventMessages($cyphtUpgrade->error, null, 'warnings');
}

if ($action == 'update_settings') {
	// Constant => [posted field, min, max]. A value outside the range is
	// clamped rather than refused: these are number boxes, not free text.
	$numbers = array(
		'CYPHTWEBMAIL_CONTACTS_MAX' => array('contacts_max', 1, 100000),
		// 10 is the ceiling bridge/context.php enforces; do not offer more here.
		'CYPHTWEBMAIL_CONTEXT_ROWS' => array('context_rows', 1, 10),
	);

	$clamped = 0;
	foreach ($numbers as $constName => $spec) {
		$posted = GETPOSTINT($spec[0]);
		$value = max($spec[1], min($spec[2], $posted));
		if ($value != $posted) {
			$clamped++;
		}
		dolibarr_set_const($db, $constName, $value, 'chaine', 0, '', $conf->entity);
	}

	dolibarr_set_const($db, 'CYPHTWEBMAIL_CONTACTS_INCLUDE_USERS',
		(GETPOST('contacts_include_users', 'alpha') ? 'true' : 'false'), 'chaine', 0, '', $conf->entity);

	$chosen = array();
	foreach (cyphtContextBlockKeys() as $key) {
		if (GETPOST('block_'.$key, 'alpha')) {
			$chosen[] = $key;
		}
	}
	// 'none' rather than '', which the bridge cannot tell from "never saved".
	dolibarr_set_const($db, 'CYPHTWEBMAIL_CONTEXT_BLOCKS',
		(empty($chosen) ? 'none' : implode(',', $chosen)), 'chaine', 0, '', $conf->entity);

	dolibarr_set_const($db, 'CYPHTWEBMAIL_CONTEXT_INVOICES',
		(GETPOST('context_invoices', 'aZ09') === 'open' ? 'open' : 'unpaid'), 'chaine', 0, '', $conf->entity);

	dolibarr_set_const($db, 'CYPHTWEBMAIL_LANG_MODE',
		(GETPOST('lang_mode', 'aZ09') === 'user' ? 'user' : 'follow'), 'chaine', 0, '', $conf->entity);

	dolibarr_set_const($db, 'CYPHTWEBMAIL_THEME_MODE',
		(GETPOST('theme_mode', 'aZ09') === 'user' ? 'user' : 'follow'), 'chaine', 0, '', $conf->entity);

	if ($clamped > 0) {
		setEventMessages($langs->trans("CyphtWebmailValueOutOfRange"), null, 'warnings');
	}
	setEventMessages($langs->trans("SetupSaved"), null);
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

$form = new Form($db);

// Computed once and reused for every form/JS call on this page.
$formToken = newToken();

llxHeader('', $langs->trans("CyphtWebmailSetup"));

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($langs->trans("CyphtWebmailSetup"), $linkback, 'title_setup');

$head = array();
$head[0][0] = $_SERVER["PHP_SELF"];
$head[0][1] = $langs->trans("Settings");
$head[0][2] = 'settings';
$head[1][0] = $_SERVER["PHP_SELF"].'?tab=build';
$head[1][1] = $langs->trans("CyphtWebmailBuildTab");
$head[1][2] = 'build';

print dol_get_fiche_head($head, $tab, '', -1);

if ($tab == 'settings') {
	print '<div class="opacitymedium" style="margin-bottom: 10px;">'.$langs->trans("CyphtWebmailSettingsHint").'</div>';

	print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
	print '<input type="hidden" name="token" value="'.$formToken.'">';
	print '<input type="hidden" name="action" value="update_settings">';

	// Every label carries its own explanation, so the page needs no manual.
	$label = function ($key) use ($langs, $form) {
		return $form->textwithpicto($langs->trans($key), $langs->trans($key.'Help'));
	};

	$sectionStart = function ($title) use ($langs) {
		print load_fiche_titre($langs->trans($title), '', '');
		print '<table class="noborder centpercent">';
		print '<tr class="liste_titre"><td class="titlefield">'.$langs->trans("Parameter").'</td><td>'.$langs->trans("Value").'</td></tr>';
	};

	$numberRow = function ($key, $field, $constName, $default, $unit = '') use ($langs, $label) {
		print '<tr class="oddeven"><td class="titlefield">'.$label($key).'</td><td>';
		print '<input type="number" class="flat width100" name="'.$field.'" value="'.dol_escape_htmltag(getDolGlobalString($constName, (string) $default)).'">';
		if ($unit !== '') {
			print ' <span class="opacitymedium">'.$langs->trans($unit).'</span>';
		}
		print '</td></tr>';
	};

	$switchRow = function ($key, $field, $constName, $default = 'true') use ($label) {
		print '<tr class="oddeven"><td class="titlefield">'.$label($key).'</td><td>';
		print '<input type="checkbox" name="'.$field.'" value="1"'.(getDolGlobalString($constName, $default) === 'true' ? ' checked' : '').'>';
		print '</td></tr>';
	};

	$sectionStart("CyphtWebmailAddressBookSettings");
	$switchRow("CyphtWebmailContactsIncludeUsers", 'contacts_include_users', 'CYPHTWEBMAIL_CONTACTS_INCLUDE_USERS');
	$numberRow("CyphtWebmailContactsMax", 'contacts_max', 'CYPHTWEBMAIL_CONTACTS_MAX', 2000);
	print '</table>';

	$sectionStart("CyphtWebmailContextSettings");

	$blockSetting = getDolGlobalString('CYPHTWEBMAIL_CONTEXT_BLOCKS', 'propal,order,invoice,ticket,project');
	$activeBlocks = ($blockSetting === 'none') ? array() : explode(',', $blockSetting);
	print '<tr class="oddeven"><td class="titlefield">'.$label("CyphtWebmailContextBlocks").'</td><td>';
	foreach (cyphtContextBlockKeys() as $key) {
		print '<label class="marginrightonly"><input type="checkbox" name="block_'.$key.'" value="1"'.
			(in_array($key, $activeBlocks, true) ? ' checked' : '').'> '.
			$langs->trans('CyphtWebmailBlock'.ucfirst($key)).'</label> ';
	}
	print '</td></tr>';

	print '<tr class="oddeven"><td class="titlefield">'.$label("CyphtWebmailContextInvoices").'</td><td>';
	print $form->selectarray('context_invoices', array(
		'unpaid' => $langs->trans("CyphtWebmailInvoicesUnpaid"),
		'open' => $langs->trans("CyphtWebmailInvoicesOpen"),
	), getDolGlobalString('CYPHTWEBMAIL_CONTEXT_INVOICES', 'unpaid'), 0);
	print '</td></tr>';

	$numberRow("CyphtWebmailContextRows", 'context_rows', 'CYPHTWEBMAIL_CONTEXT_ROWS', 3);
	print '</table>';

	$sectionStart("CyphtWebmailInterfaceSettings");
	print '<tr class="oddeven"><td class="titlefield">'.$label("CyphtWebmailLangMode").'</td><td>';
	print $form->selectarray('lang_mode', array(
		'follow' => $langs->trans("CyphtWebmailLangFollow"),
		'user' => $langs->trans("CyphtWebmailLangUser"),
	), getDolGlobalString('CYPHTWEBMAIL_LANG_MODE', 'follow'), 0);
	print '</td></tr>';

	print '<tr class="oddeven"><td class="titlefield">'.$label("CyphtWebmailThemeMode").'</td><td>';
	print $form->selectarray('theme_mode', array(
		'follow' => $langs->trans("CyphtWebmailThemeFollow"),
		'user' => $langs->trans("CyphtWebmailThemeUser"),
	), getDolGlobalString('CYPHTWEBMAIL_THEME_MODE', 'follow'), 0);
	print '</td></tr>';
	print '</table>';

	print '<div class="center" style="margin-top: 10px;">';
	print '<input type="submit" class="button" value="'.$langs->trans("Save").'">';
	print '</div>';

	print '</form>';
}

if ($tab == 'build') {
	$manager->cyphtwebmail_flush_now();

	print load_fiche_titre($langs->trans("CyphtWebmailBuildStatus"), '', '');

	print '<table class="noborder centpercent">';

	print '<tr class="oddeven"><td class="titlefield">'.$langs->trans("CyphtWebmailInstalledVersion").'</td><td>';
	$installedVersion = $manager->getInstalledVersion();
	print $installedVersion ? dol_escape_htmltag($installedVersion) : '<span class="error">'.$langs->trans("CyphtWebmailNotInstalled").'</span>';
	print '</td></tr>';

	$buildInfo = $manager->getBuildInfo();

	$moduleVersion = (!empty($buildInfo['module_version']))
		? $buildInfo['module_version']
		: $manager->getModuleVersion();

	print '<tr class="oddeven"><td>'.$langs->trans("CyphtWebmailModuleVersion").'</td><td>';
	print ($moduleVersion !== '')
		? dol_escape_htmltag($moduleVersion)
		: '<span class="opacitymedium">-</span>';
	print '</td></tr>';

	print '<tr class="oddeven"><td>'.$langs->trans("CyphtWebmailBuiltVersion").'</td><td>';
	$builtVersion = (!empty($buildInfo['cypht_version'])) ? $buildInfo['cypht_version'] : $manager->getBuiltVersion();
	if ($builtVersion) {
		print dol_escape_htmltag($builtVersion);
		// Catches running composer update underneath a compiled build:
		// public/ was generated against one Cypht and vendor/ now holds another.
		if ($installedVersion && $installedVersion !== $builtVersion) {
			print ' <span class="warning">'.$langs->trans("CyphtWebmailVersionMismatch", $builtVersion, $installedVersion).'</span>';
		}
	} else {
		print $langs->trans("CyphtWebmailNeverBuilt");
	}
	print '</td></tr>';

	print '<tr class="oddeven"><td>'.$langs->trans("CyphtWebmailLastBuild").'</td><td>';
	$lastBuild = $manager->getLastBuildDate();
	print $lastBuild ? dol_print_date($lastBuild, 'dayhour') : '-';
	print '</td></tr>';

	print '<tr class="oddeven"><td>'.$langs->trans("Status").'</td><td>';
	if (!$installedVersion) {
		print '<span class="error">'.$langs->trans("CyphtWebmailNotInstalled").'</span>';
	} elseif ($manager->needsRebuild()) {
		print '<span class="warning">'.$langs->trans("CyphtWebmailUpdateAvailable", $installedVersion).'</span>';
	} elseif ($manager->isPublished()) {
		print '<span class="ok">'.$langs->trans("CyphtWebmailUpToDate").'</span>';
	} else {
		print $langs->trans("CyphtWebmailNeverBuilt");
	}
	print '</td></tr>';

	print '</table>';

	global $dolibarr_main_prod;

	$requirements = $manager->checkBuildRequirements();
	$canBuildHere = !empty($requirements['ok']);
	$published = $manager->isPublished();
	$devMode = empty($dolibarr_main_prod) || getDolGlobalInt('CYPHTWEBMAIL_ENABLE_BUILD');
	$showBuildControls = $canBuildHere && $devMode;

	// Nothing published means the webmail cannot run at all. That is worth saying
	// in any mode, so this warning is deliberately not behind $devMode:
	// a production install that has never been built is broken, and the admin needs to know why.
	if (!$canBuildHere && !$published) {
		print '<div class="warning" style="padding: 12px; margin-top: 10px;">';
		print '<strong>'.$langs->trans("CyphtWebmailCannotBuildHere").'</strong><br><br>';

		print '<table class="nobordernopadding">';
		foreach ($requirements['checks'] as $check) {
			print '<tr><td style="padding-right: 10px;">';
			print $check['ok'] ? img_picto('', 'tick') : img_picto('', 'error');
			print '</td><td style="padding-right: 10px;">'.dol_escape_htmltag($check['label']).'</td>';
			print '<td class="opacitymedium">'.dol_escape_htmltag($check['detail']).'</td></tr>';
		}
		print '</table><br>';

		print $langs->trans("CyphtWebmailBuildFromShell").'<br>';
		print '<pre style="margin-top: 8px; padding: 8px; background: #f4f4f4; overflow-x: auto;">';
		print 'cd '.dol_escape_htmltag($manager->getModuleRoot())."\n";
		print 'php scripts/build.php';
		print '</pre>';
		print '</div>';
	} elseif (!$canBuildHere && $devMode) {
		print load_fiche_titre($langs->trans("CyphtWebmailMaintenance"), '', '');
		print '<div class="center opacitymedium" style="margin-top: 10px;">';
		print $langs->trans("CyphtWebmailRebuildFromShell");
		print ' <code>php scripts/build.php</code>';
		print '</div>';
	} elseif ($showBuildControls) {
		print load_fiche_titre($langs->trans("CyphtWebmailMaintenance"), '', '');
		print '<div class="center opacitymedium" style="margin-bottom: 8px;">';
		print $langs->trans("CyphtWebmailMaintenanceHint");
		print '</div>';
		print '<div class="center" style="margin-top: 10px;">';
		print '<form id="cypht-build-form">';
		print '<input type="hidden" name="token" value="'.$formToken.'">';
		print '<input type="hidden" name="action" value="build">';
		print '<button type="submit" class="button" data-loading-text="'.$langs->trans("CyphtWebmailBuilding").'">'.$langs->trans("CyphtWebmailGenerateButton").'</button>';
		print '</form>';
		print '</div>';
	} elseif (!$published) {
		print '<div class="warning" style="padding: 12px; margin-top: 10px;">';
		print '<strong>'.$langs->trans("CyphtWebmailNeverBuilt").'</strong><br><br>';
		print $langs->trans("CyphtWebmailBuildFromShell").'<br>';
		print '<pre style="margin-top: 8px; padding: 8px; background: #f4f4f4; overflow-x: auto;">';
		print 'cd '.dol_escape_htmltag($manager->getModuleRoot())."\n";
		print 'php scripts/build.php';
		print '</pre>';
		print '</div>';
	}

	if ($showBuildControls) {
		// 'out' = real child-process output, 'err' = our own failure messages,
		// 'info' = our own step/status lines. Stderr is not colored red just for
		// being stderr; see CyphtPipeline::runProcess().
		print '<style>
#cyphtwebmail-log .log-out { color: #d4d4d4; }
#cyphtwebmail-log .log-err { color: #f14c4c; }
#cyphtwebmail-log .log-info { color: #4fc1ff; font-weight: bold; }
</style>';
		$lastBuildLog = $manager->getLastBuildLog();

		// Hidden if no build has ever run; setup.js un-hides it once one starts.
		print '<div class="center" style="margin-top: 10px;">';
		print '<button type="button" id="cyphtwebmail-log-toggle" class="button" data-show-text="'.$langs->trans("CyphtWebmailShowLog").'" data-hide-text="'.$langs->trans("CyphtWebmailHideLog").'" style="'.($lastBuildLog === '' ? 'display:none;' : '').'">'.$langs->trans("CyphtWebmailShowLog").'</button>';
		print '</div>';
		print '<div id="cyphtwebmail-log-wrap" style="display:none;">';
		print '<pre id="cyphtwebmail-log" style="background:#1e1e1e; color:#d4d4d4; font-family:Consolas,\'Courier New\',monospace; font-size:12px; line-height:1.5; padding:12px 14px; max-height:500px; overflow:auto; border-radius:6px; border:1px solid #333; white-space:pre-wrap; word-break:break-all; margin-top:10px;"></pre>';
		print '</div>';

		// json_encode() escapes "/" to "\/", preventing a literal "</script>"
		// in the log from closing this tag early.
		print '<script type="application/json" id="cyphtwebmail-last-log">'.json_encode($lastBuildLog).'</script>';

		print '<script src="'.dol_buildpath('/cyphtwebmail/js/admin/setup.js', 1).'"></script>';
	}
}

print dol_get_fiche_end();

llxFooter();
$db->close();
