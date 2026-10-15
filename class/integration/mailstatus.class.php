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
 * \file        class/integration/mailstatus.class.php
 * \ingroup     cyphtWebmail
 * \brief       Mail counts for the Webmail box on the home page.
 */
class CyphtMailStatus
{
	/** Hours after which the counts are too old to show */
	const MAX_AGE = 24;

	/**
	 * @var DoliDB
	 */
	public $db;

	/**
	 * @var string Last error message
	 */
	public $error = '';

	/**
	 * @var object|false|null Row of llx_cyphtwebmail_mailstatus
	 */
	private $row = null;

	/**
	 * @param DoliDB $db
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
	/**
	 * Load the indicator for the home page dashboard.
	 *
	 * @param User   $user
	 * @param string $mode 'unread', 'contacts' or 'flagged'
	 * @return WorkboardResponse|int Response, 0 if there is nothing to show, -1 on error
	 */
	public function load_board($user, $mode)
	{
		// phpcs:enable
		global $langs;

		require_once DOL_DOCUMENT_ROOT.'/core/class/workboardresponse.class.php';

		$row = $this->fetchRow($user);
		if ($row === -1) {
			return -1;
		}
		if (!$row) {
			return 0;
		}

		$now = dol_now();
		$checked = (int) $this->db->jdate($row->date_check, 'gmt');
		if ($checked < $now - self::MAX_AGE * 3600) {
			return 0;
		}

		$langs->load('cyphtwebmail@cyphtwebmail');
		$updated = $langs->trans('CyphtDashboardUpdated', dol_print_date($checked, 'dayhour', 'tzuser'));

		$response = new WorkboardResponse();
		$response->img = img_object('', 'email');
		$response->url = dol_buildpath('/cyphtwebmail/index.php', 1).'?cypht='.urlencode('page=message_list&list_path=unread');

		if ($mode == 'unread') {
			$response->nbtodo = (int) $row->nb_unread;
			$response->label = $langs->trans('CyphtDashboardUnread').' ('.$updated.')';
			$response->labelShort = $langs->trans('CyphtDashboardUnreadShort');
		} elseif ($mode == 'contacts') {
			$delay = getDolGlobalInt('CYPHTWEBMAIL_DELAY_UNREAD', 2);
			$response->warning_delay = $delay;
			$response->label = $langs->trans('CyphtDashboardContacts').' ('.$updated.')';
			$response->labelShort = $langs->trans('CyphtDashboardContactsShort');

			$dates = json_decode((string) $row->contact_unread, true);
			foreach (is_array($dates) ? $dates : array() as $date) {
				$response->nbtodo++;
				if ($delay > 0 && (int) $date < $now - $delay * 86400) {
					$response->nbtodolate++;
				}
			}
		} elseif ($mode == 'flagged') {
			$response->nbtodo = (int) $row->nb_flagged;
			$response->label = $langs->trans('CyphtDashboardFlagged').' ('.$updated.')';
			$response->labelShort = $langs->trans('CyphtDashboardFlaggedShort');
			$response->url = dol_buildpath('/cyphtwebmail/index.php', 1).'?cypht='.urlencode('page=message_list&list_path=flagged');
		} else {
			return 0;
		}

		return $response;
	}

	/**
	 * @param User $user
	 * @return object|false|int Row, false if none, -1 on error
	 */
	private function fetchRow($user)
	{
		if ($this->row !== null) {
			return $this->row;
		}

		$sql = "SELECT nb_unread, nb_flagged, contact_unread, date_check";
		$sql .= " FROM ".MAIN_DB_PREFIX."cyphtwebmail_mailstatus";
		$sql .= " WHERE fk_user = ".((int) $user->id);

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		$this->row = $this->db->fetch_object($resql);
		$this->db->free($resql);

		return $this->row;
	}
}
