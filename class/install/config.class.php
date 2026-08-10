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
 * \file        class/install/config.class.php
 * \ingroup     cyphtwebmail
 * \brief       Read and write llx_cyphtwebmail_config from the Dolibarr side.
 *
 *              This table exists because llx_const cannot be used for the
 *              module's secrets. dolibarr_set_const() encrypts the value of
 *              any constant whose name ends in _SECRET, _KEY, _PASS and a
 *              few others, and decrypts it again when building $conf->global.
 *              Dolibarr's own code therefore sees plaintext and never
 *              notices, but the webmail reads its configuration before
 *              Dolibarr is loaded and gets the raw column: ciphertext, and a
 *              shared secret that no longer matches at the two ends.
 *
 *              The runtime side reads the same table over PDO in
 *              CyphtEnvBootstrap. Keys are named as Cypht names them, so
 *              neither side needs a translation table.
 */
class CyphtConfig
{
	/**
	 * @param DoliDB $db
	 * @param string $name  Cypht side name, for example SSO_SHARED_SECRET
	 * @param string $default
	 * @param int|null $entity Defaults to the current entity
	 * @return string
	 */
	public static function get($db, $name, $default = '', $entity = null)
	{
		global $conf;

		if ($entity === null) {
			$entity = isset($conf->entity) ? (int) $conf->entity : 1;
		}

		$sql = "SELECT value FROM ".MAIN_DB_PREFIX."cyphtwebmail_config";
		$sql .= " WHERE name = '".$db->escape($name)."'";
		$sql .= " AND entity = ".((int) $entity);

		$resql = $db->query($sql);
		if (!$resql) {
			return $default;
		}

		$obj = $db->fetch_object($resql);
		$db->free($resql);

		if (!$obj || $obj->value === null || $obj->value === '') {
			return $default;
		}

		return (string) $obj->value;
	}

	/**
	 * Upsert. The unique index on (entity, name) is what makes the delete
	 * then insert safe to repeat.
	 *
	 * @param DoliDB $db
	 * @param string $name
	 * @param string $value
	 * @param int|null $entity
	 * @return bool
	 */
	public static function set($db, $name, $value, $entity = null)
	{
		global $conf;

		if ($entity === null) {
			$entity = isset($conf->entity) ? (int) $conf->entity : 1;
		}

		$db->begin();

		$sql = "DELETE FROM ".MAIN_DB_PREFIX."cyphtwebmail_config";
		$sql .= " WHERE name = '".$db->escape($name)."' AND entity = ".((int) $entity);
		if (!$db->query($sql)) {
			$db->rollback();
			return false;
		}

		$sql = "INSERT INTO ".MAIN_DB_PREFIX."cyphtwebmail_config(entity, name, value, date_creation)";
		$sql .= " VALUES (".((int) $entity).", '".$db->escape($name)."', '".$db->escape($value)."', '".$db->idate(dol_now())."')";
		if (!$db->query($sql)) {
			$db->rollback();
			return false;
		}

		$db->commit();

		return true;
	}

	/**
	 * Fetch or mint. Used for the three generated secrets, where re-running
	 * activation must never roll a new value.
	 *
	 * There is deliberately no counterpart that overwrites an existing secret.
	 * Rotating USER_CONFIG_SECRET without re-encrypting every stored config
	 * empties every mailbox account in the installation, silently, so the only
	 * way to do it is to write that migration first.
	 *
	 * @param DoliDB $db
	 * @param string $name
	 * @param int $bytes Raw bytes of entropy, hex encoded on the way in
	 * @return string
	 */
	public static function getOrCreateSecret($db, $name, $bytes = 32)
	{
		$existing = self::get($db, $name, '');
		if ($existing !== '') {
			return $existing;
		}

		$secret = bin2hex(random_bytes($bytes));
		self::set($db, $name, $secret);

		return $secret;
	}
}
