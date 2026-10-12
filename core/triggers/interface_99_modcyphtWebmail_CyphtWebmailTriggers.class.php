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
 * \file        core/triggers/interface_99_modcyphtWebmail_CyphtWebmailTriggers.class.php
 * \ingroup     cyphtWebmail
 * \brief       Deletes or renames a user's Cypht settings file when the user
 *              is deleted or their login changes.
 */
require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';
require_once __DIR__.'/../../class/webmail.class.php';

class InterfaceCyphtWebmailTriggers extends DolibarrTriggers
{
	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;

		$this->name = preg_replace('/^Interface/i', '', get_class($this));
		$this->family = 'cyphtWebmail';
		$this->description = 'Cleans up webmail data belonging to deleted users';

		require_once __DIR__ . '/../../class/install/paths.class.php';
		$cyphtVersion = (new CyphtPaths())->getModuleVersion();
		$this->version = ($cyphtVersion !== '' ? $cyphtVersion : 'development');
		$this->picto = 'cyphtwebmail@cyphtwebmail';
	}

	/**
	 * @param string $action Event code
	 * @param CommonObject $object Object the event happened to
	 * @param User $user Acting user
	 * @param Translate $langs Language handler
	 * @param Conf $conf Configuration
	 * @return int 0 if unhandled, >0 on success, <0 on error
	 */
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		if (!isModEnabled('cyphtwebmail')) {
			return 0;
		}

		switch ($action) {
			case 'USER_DELETE':
				return $this->onUserDelete($object);
			case 'USER_MODIFY':
				return $this->onUserModify($object);
		}

		return 0;
	}

	/**
	 * Delete the settings file of a deleted user.
	 *
	 * @param User $object The user that was just deleted
	 * @return int
	 */
	private function onUserDelete($object)
	{
		$login = isset($object->login) ? $object->login : '';
		if ($login === '') {
			dol_syslog("cyphtWebmail: USER_DELETE with no login, skipping settings cleanup", LOG_WARNING);
			return 0;
		}

		$manager = new CyphtWebmail($this->db);

		$paths = array(
			$manager->getUserSettingsPath($login),
			$manager->getLegacyUserSettingsPath($login),
		);

		foreach (array_unique($paths) as $path) {
			if (!file_exists($path)) {
				continue;
			}
			if (@unlink($path)) {
				dol_syslog("cyphtWebmail: removed webmail settings for deleted user ".$login." (".$path.")");
			} else {
				dol_syslog("cyphtWebmail: could not remove ".$path." for deleted user ".$login, LOG_ERR);
			}
		}

		return 1;
	}

	/**
	 * Rename the settings file when the login changes, since the file name
	 * is based on the login.
	 *
	 * @param User $object The user that was just updated
	 * @return int
	 */
	private function onUserModify($object)
	{
		if (!is_object($object->oldcopy) || empty($object->oldcopy->login)) {
			return 0;
		}

		$from = $object->oldcopy->login;
		$to = isset($object->login) ? $object->login : '';

		if ($to === '' || $from === $to) {
			return 0;
		}

		$manager = new CyphtWebmail($this->db);
		$target = $manager->getUserSettingsPath($to);

		if (file_exists($target)) {
			dol_syslog("cyphtWebmail: login renamed ".$from." -> ".$to." but ".$target." already exists, not overwriting", LOG_WARNING);
			return 0;
		}

		foreach (array($manager->getUserSettingsPath($from), $manager->getLegacyUserSettingsPath($from)) as $source) {
			if (!file_exists($source)) {
				continue;
			}
			if (@rename($source, $target)) {
				dol_syslog("cyphtWebmail: moved webmail settings ".$from." -> ".$to);
			} else {
				dol_syslog("cyphtWebmail: could not move ".$source." to ".$target." after login rename", LOG_ERR);
			}
			break;
		}

		return 1;
	}
}
