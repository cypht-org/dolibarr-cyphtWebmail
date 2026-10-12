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
 * \file        class/integration/contactcollector.class.php
 * \ingroup     cyphtWebmail
 * \brief       Loads email addresses from Dolibarr contacts, third parties,
 *              users and members.
 */
class CyphtContactCollector
{
	/**
	 * Get all addresses the user can see, without duplicates.
	 *
	 * @param DoliDB $db
	 * @param User   $user    Must have had loadRights() called
	 * @param int    $limit   Cap per source, 0 for the configured default
	 * @param string $search  Substring filter, empty for everything
	 * @param string $error   Set when null is returned
	 * @return array<string,mixed>|null Keys: contacts, count, entity, truncated
	 */
	public static function collect($db, $user, $limit = 0, $search = '', &$error = '')
	{
		global $conf;

		$maxRows = getDolGlobalInt('CYPHTWEBMAIL_CONTACTS_MAX', 2000);
		if ($limit > 0 && $limit < $maxRows) {
			$maxRows = $limit;
		}

		$like = '';
		if ($search !== '') {
			$like = "'%".$db->escape($db->escapeforlike($search))."%'";
		}

		$contacts = array();
		$includeUsers = (getDolGlobalString('CYPHTWEBMAIL_CONTACTS_INCLUDE_USERS', 'true') === 'true');
		$includeMembers = (isModEnabled('member') && $user->hasRight('adherent', 'lire'));

		if (!self::addContacts($db, $contacts, $maxRows, $like, $error)) {
			return null;
		}
		if (!self::addThirdParties($db, $contacts, $maxRows, $like, $error)) {
			return null;
		}
		if ($includeUsers && !self::addUsers($db, $contacts, $maxRows, $like, $error)) {
			return null;
		}
		if ($includeMembers && !self::addMembers($db, $contacts, $maxRows, $like, $error)) {
			return null;
		}

		/* Contacts were added first, so they win over a company with the same address. */
		$seen = array();
		$unique = array();
		foreach ($contacts as $contact) {
			$key = strtolower($contact['email_address']);
			if (isset($seen[$key])) {
				continue;
			}
			$seen[$key] = true;
			$unique[] = $contact;
		}

		$sourcesQueried = 2 + ($includeUsers ? 1 : 0) + ($includeMembers ? 1 : 0);

		return array(
			'contacts' => $unique,
			'count' => count($unique),
			'entity' => (int) $conf->entity,
			'truncated' => (count($contacts) >= ($maxRows * $sourcesQueried)),
		);
	}

	/**
	 * Build a contact array in the format Hm_Contact expects.
	 *
	 * @param string $email
	 * @param string $name
	 * @param string $group
	 * @param array<string,mixed> $extra
	 * @return array<string,mixed>
	 */
	public static function shape($email, $name, $group, $extra)
	{
		if (isset($extra['dol_type'], $extra['dol_id'])) {
			$id = 'dolibarr-'.$extra['dol_type'].'-'.$extra['dol_id'];
		} else {
			$id = 'dolibarr-'.md5(strtolower($email));
		}

		return array(
			'id' => $id,
			'email_address' => $email,
			'display_name' => ($name !== '' ? $name : $email),
			'group' => $group,
			'source' => 'dolibarr',
			'type' => 'dolibarr',
			'external' => true,
			'phone_number' => isset($extra['phone']) ? $extra['phone'] : '',
			'company' => isset($extra['dol_company']) ? $extra['dol_company'] : '',
			'all_fields' => $extra,
		);
	}

	/**
	 * Add contacts (llx_socpeople).
	 *
	 * @param DoliDB $db
	 * @param array<int,array<string,mixed>> $contacts
	 * @param int $maxRows
	 * @param string $like
	 * @param string $error
	 * @return bool
	 */
	private static function addContacts($db, array &$contacts, $maxRows, $like, &$error)
	{
		$sql = "SELECT sp.rowid, sp.lastname, sp.firstname, sp.email, sp.phone as phone_pro, sp.phone_mobile,";
		$sql .= " sp.poste, sp.fk_soc, s.nom as company";
		$sql .= " FROM ".MAIN_DB_PREFIX."socpeople as sp";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."societe as s ON s.rowid = sp.fk_soc";
		$sql .= " WHERE sp.entity IN (".getEntity('contact').")";
		$sql .= " AND sp.email IS NOT NULL AND sp.email <> ''";
		$sql .= " AND sp.statut = 1";
		if ($like !== '') {
			$sql .= " AND (sp.email LIKE ".$like." OR sp.lastname LIKE ".$like;
			$sql .= " OR sp.firstname LIKE ".$like." OR s.nom LIKE ".$like.")";
		}
		$sql .= " ORDER BY sp.lastname, sp.firstname";
		$sql .= $db->plimit($maxRows, 0);

		$resql = $db->query($sql);
		if (!$resql) {
			$error = 'Contact query failed: '.$db->lasterror();
			return false;
		}
		while ($obj = $db->fetch_object($resql)) {
			$contacts[] = self::shape(
				$obj->email,
				trim($obj->firstname.' '.$obj->lastname),
				($obj->company ? $obj->company : 'Dolibarr contacts'),
				array(
					'dol_type' => 'contact',
					'dol_id' => (int) $obj->rowid,
					'dol_socid' => (int) $obj->fk_soc,
					'dol_company' => (string) $obj->company,
					'dol_job' => (string) $obj->poste,
					'phone' => ($obj->phone_pro ? $obj->phone_pro : (string) $obj->phone_mobile),
				)
			);
		}
		$db->free($resql);

		return true;
	}

	/**
	 * Add third parties (llx_societe).
	 *
	 * @param DoliDB $db
	 * @param array<int,array<string,mixed>> $contacts
	 * @param int $maxRows
	 * @param string $like
	 * @param string $error
	 * @return bool
	 */
	private static function addThirdParties($db, array &$contacts, $maxRows, $like, &$error)
	{
		$sql = "SELECT s.rowid, s.nom, s.email, s.phone";
		$sql .= " FROM ".MAIN_DB_PREFIX."societe as s";
		$sql .= " WHERE s.entity IN (".getEntity('societe').")";
		$sql .= " AND s.email IS NOT NULL AND s.email <> ''";
		$sql .= " AND s.status = 1";
		if ($like !== '') {
			$sql .= " AND (s.email LIKE ".$like." OR s.nom LIKE ".$like.")";
		}
		$sql .= " ORDER BY s.nom";
		$sql .= $db->plimit($maxRows, 0);

		$resql = $db->query($sql);
		if (!$resql) {
			$error = 'Third party query failed: '.$db->lasterror();
			return false;
		}
		while ($obj = $db->fetch_object($resql)) {
			$contacts[] = self::shape(
				$obj->email,
				(string) $obj->nom,
				'Dolibarr third parties',
				array(
					'dol_type' => 'thirdparty',
					'dol_id' => (int) $obj->rowid,
					'dol_socid' => (int) $obj->rowid,
					'dol_company' => (string) $obj->nom,
					'phone' => (string) $obj->phone,
				)
			);
		}
		$db->free($resql);

		return true;
	}

	/**
	 * Add users (llx_user). Only name, job and email.
	 *
	 * @param DoliDB $db
	 * @param array<int,array<string,mixed>> $contacts
	 * @param int $maxRows
	 * @param string $like
	 * @param string $error
	 * @return bool
	 */
	private static function addUsers($db, array &$contacts, $maxRows, $like, &$error)
	{
		$sql = "SELECT u.rowid, u.lastname, u.firstname, u.email, u.job";
		$sql .= " FROM ".MAIN_DB_PREFIX."user as u";
		$sql .= " WHERE u.entity IN (".getEntity('user').")";
		$sql .= " AND u.email IS NOT NULL AND u.email <> ''";
		$sql .= " AND u.statut = 1";
		if ($like !== '') {
			$sql .= " AND (u.email LIKE ".$like." OR u.lastname LIKE ".$like;
			$sql .= " OR u.firstname LIKE ".$like.")";
		}
		$sql .= " ORDER BY u.lastname, u.firstname";
		$sql .= $db->plimit($maxRows, 0);

		$resql = $db->query($sql);
		if (!$resql) {
			$error = 'User query failed: '.$db->lasterror();
			return false;
		}
		while ($obj = $db->fetch_object($resql)) {
			$contacts[] = self::shape(
				$obj->email,
				trim($obj->firstname.' '.$obj->lastname),
				'Dolibarr users',
				array(
					'dol_type' => 'user',
					'dol_id' => (int) $obj->rowid,
					'dol_job' => (string) $obj->job,
				)
			);
		}
		$db->free($resql);

		return true;
	}

	/**
	 * Add members (llx_adherent).
	 *
	 * @param DoliDB $db
	 * @param array<int,array<string,mixed>> $contacts
	 * @param int $maxRows
	 * @param string $like
	 * @param string $error
	 * @return bool
	 */
	private static function addMembers($db, array &$contacts, $maxRows, $like, &$error)
	{
		$sql = "SELECT a.rowid, a.lastname, a.firstname, a.email, a.societe";
		$sql .= " FROM ".MAIN_DB_PREFIX."adherent as a";
		$sql .= " WHERE a.entity IN (".getEntity('adherent').")";
		$sql .= " AND a.email IS NOT NULL AND a.email <> ''";
		/* Adherent::STATUS_VALIDATED */
		$sql .= " AND a.statut = 1";
		if ($like !== '') {
			$sql .= " AND (a.email LIKE ".$like." OR a.lastname LIKE ".$like;
			$sql .= " OR a.firstname LIKE ".$like." OR a.societe LIKE ".$like.")";
		}
		$sql .= " ORDER BY a.lastname, a.firstname";
		$sql .= $db->plimit($maxRows, 0);

		$resql = $db->query($sql);
		if (!$resql) {
			$error = 'Member query failed: '.$db->lasterror();
			return false;
		}
		while ($obj = $db->fetch_object($resql)) {
			$contacts[] = self::shape(
				$obj->email,
				trim($obj->firstname.' '.$obj->lastname),
				'Dolibarr members',
				array(
					'dol_type' => 'member',
					'dol_id' => (int) $obj->rowid,
					'dol_company' => (string) $obj->societe,
				)
			);
		}
		$db->free($resql);

		return true;
	}
}
