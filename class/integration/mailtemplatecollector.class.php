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
 * \file        class/integration/mailtemplatecollector.class.php
 * \ingroup     cyphtWebmail
 * \brief       Loads email templates from llx_c_email_templates.
 */
class CyphtMailTemplateCollector
{
	/**
	 * Get all active templates the user can see.
	 *
	 * @param DoliDB    $db
	 * @param User      $user
	 * @param Translate $langs
	 * @param string    $error Set when null is returned
	 * @return array<string,mixed>|null Keys: templates, types, count, entity, hint
	 */
	public static function collect($db, $user, $langs, &$error = '')
	{
		global $conf;

		$langs->loadLangs(array('errors', 'admin', 'mails', 'other'));

		$sql = "SELECT rowid, label, type_template, module, lang, position, topic, content,";
		$sql .= " email_from, email_to, email_tocc, email_tobcc";
		$sql .= " FROM ".MAIN_DB_PREFIX."c_email_templates";
		$sql .= " WHERE entity IN (".getEntity('c_email_templates').")";
		$sql .= " AND active = 1";
		// Hide other users' private templates, as FormMail::getEMailTemplate() does.
		$sql .= " AND (private = 0 OR fk_user = ".((int) $user->id).")";
		$sql .= " ORDER BY type_template ASC, position ASC, label ASC";

		$resql = $db->query($sql);
		if (!$resql) {
			$error = 'Template query failed: '.$db->lasterror();
			return null;
		}

		$substitutions = getCommonSubstitutionArray($langs, 0, null, null);

		$templates = array();
		$types = array();
		$rows = array();
		while ($obj = $db->fetch_object($resql)) {
			$rows[] = $obj;
			if (!empty($obj->module)) {
				$langs->load((string) $obj->module);
			}
		}
		$db->free($resql);

		foreach ($rows as $obj) {
			$type = (string) $obj->type_template;
			$subject = make_substitutions((string) $obj->topic, $substitutions, $langs);
			$body = make_substitutions((string) $obj->content, $substitutions, $langs);

			$leftover = array();
			if (preg_match_all('/__[A-Z0-9_]+__/', $subject.' '.$body, $m)) {
				$leftover = array_values(array_unique($m[0]));
			}

			$templates[] = array(
				'id'      => (int) $obj->rowid,
				'label'   => self::label((string) $obj->label, $langs),
				'lang'    => (string) $obj->lang,
				'type'    => $type,
				'type_label' => self::typeLabel($type, $langs),
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
					'label' => self::typeLabel($type, $langs),
					'count' => 0,
				);
			}
			$types[$type]['count']++;
		}

		return array(
			'templates' => $templates,
			'types' => array_values($types),
			'count' => count($templates),
			'entity' => (int) $conf->entity,
			'hint' => (count($templates) === 0
				? 'No email templates yet. Create one in Dolibarr under Home, Setup, Emails, Email templates.'
				: ''),
		);
	}

	/**
	 * Get the display label of a template.
	 *
	 * @param string $label Raw label column
	 * @param Translate $langs
	 * @return string
	 */
	private static function label($label, $langs)
	{
		$label = trim($label);
		if (!preg_match('/\((.*)\)/', $label, $reg)) {
			return $label;
		}

		$key = $reg[1];
		$translated = $langs->trans($key);
		if ($translated !== $key) {
			return $translated;
		}

		$spaced = preg_replace('/(?<!^)([A-Z])/', ' $1', $key);

		return ucfirst(strtolower($spaced));
	}

	/**
	 * Get a readable label for a type_template value.
	 *
	 * @param string $token type_template value
	 * @param Translate $langs
	 * @return string
	 */
	private static function typeLabel($token, $langs)
	{
		$token = (string) $token;
		if ($token === '' || $token === 'all' || $token === 'none') {
			return $langs->trans($token === 'none' ? 'None' : 'All');
		}

		$element = preg_replace('/_send$/', '', $token);

		$candidates = array($element);
		if (function_exists('getElementProperties')) {
			$props = getElementProperties($element);
			foreach (array('element', 'module') as $key) {
				if (!empty($props[$key]) && !in_array($props[$key], $candidates, true)) {
					$candidates[] = $props[$key];
				}
			}
		}

		foreach ($candidates as $key) {
			$translated = $langs->trans(ucfirst($key));
			if ($translated !== ucfirst($key)) {
				return $translated;
			}
		}

		return ucfirst(str_replace('_', ' ', $token));
	}
}
