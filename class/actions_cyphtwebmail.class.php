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
 * \file        class/actions_cyphtwebmail.class.php
 * \ingroup     cyphtWebmail
 * \brief       Hooks of the module.
 */
require_once DOL_DOCUMENT_ROOT.'/core/class/commonhookactions.class.php';

class ActionsCyphtwebmail extends CommonHookActions
{
	/** Cards that get the "New message" button */
	const MESSAGE_CONTEXTS = array('thirdpartycard', 'contactcard', 'propalcard', 'ordercard', 'invoicecard', 'ordersuppliercard', 'invoicesuppliercard');

	/** Documents: contact types to write to, in order, and the subject Dolibarr uses */
	const DOCUMENTS = array(
		'propal' => array('contacts' => array('CUSTOMER', 'BILLING'), 'topic' => 'SendPropalRef'),
		'commande' => array('contacts' => array('CUSTOMER', 'BILLING'), 'topic' => 'SendOrderRef'),
		'facture' => array('contacts' => array('BILLING'), 'topic' => 'SendBillRef'),
		'order_supplier' => array('contacts' => array('CUSTOMER', 'BILLING'), 'topic' => 'SendOrderRef'),
		'invoice_supplier' => array('contacts' => array('BILLING'), 'topic' => 'SendBillRef'),
	);

	/**
	 * @var DoliDB
	 */
	public $db;

	/**
	 * @param DoliDB $db
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Add the Webmail lines to the home page dashboard.
	 *
	 * @param array<string,mixed> $parameters Hook parameters
	 * @param object $object Object
	 * @param string $action Action
	 * @param HookManager $hookmanager Hook manager
	 * @return int 0
	 */
	public function addOpenElementsDashboardLine($parameters, &$object, &$action, $hookmanager)
	{
		global $user;

		$this->results = array();
		if (!$this->dashboardEnabled()) {
			return 0;
		}

		require_once __DIR__.'/integration/mailstatus.class.php';

		$status = new CyphtMailStatus($this->db);
		foreach (array('unread', 'contacts', 'flagged') as $mode) {
			$board = $status->load_board($user, $mode);
			if (is_object($board)) {
				$this->results['cyphtwebmail_'.$mode] = $board;
			}
		}

		return 0;
	}

	/**
	 * Add the Webmail box to the home page dashboard.
	 *
	 * @param array<string,mixed> $parameters Hook parameters
	 * @param object $object Object
	 * @param string $action Action
	 * @param HookManager $hookmanager Hook manager
	 * @return int 0
	 */
	public function addOpenElementsDashboardGroup($parameters, &$object, &$action, $hookmanager)
	{
		$this->results = array();
		if (!$this->dashboardEnabled()) {
			return 0;
		}

		$this->results['cyphtwebmail'] = array(
			'groupName' => 'CyphtDashboardTitle',
			'lang' => 'cyphtwebmail@cyphtwebmail',
			'stats' => array('cyphtwebmail_unread', 'cyphtwebmail_contacts', 'cyphtwebmail_flagged'),
		);

		return 0;
	}

	/**
	 * Add a "New message" button to third party, contact and document cards.
	 *
	 * @param array<string,mixed> $parameters Hook parameters
	 * @param CommonObject $object Object shown on the card
	 * @param string $action Action
	 * @param HookManager $hookmanager Hook manager
	 * @return int 0
	 */
	public function addMoreActionsButtons($parameters, &$object, &$action, $hookmanager)
	{
		global $langs;

		if (!isModEnabled('cyphtwebmail') || !$this->isContext($parameters, self::MESSAGE_CONTEXTS)) {
			return 0;
		}

		$subject = '';
		if (isset(self::DOCUMENTS[$object->element])) {
			$document = self::DOCUMENTS[$object->element];
			$email = $this->documentEmail($object, $document['contacts']);

			// Same subject as Dolibarr's own "Send email" form
			$langs->loadLangs(array('bills', 'commercial'));
			$ref = $object->ref.(empty($object->ref_client) ? '' : ' ('.$object->ref_client.')');
			$subject = $langs->transnoentitiesnoconv($document['topic'], $ref);
		} else {
			$email = empty($object->email) ? '' : $object->email;
		}

		if ($email === '') {
			return 0;
		}

		$cypht = 'page=compose&compose_to='.rawurlencode('mailto:'.$email.'?subject='.rawurlencode($subject));
		if ($subject === '' || strlen($cypht) > 300) {
			$cypht = 'page=compose&compose_to='.rawurlencode('mailto:'.$email);
		}
		$url = dol_buildpath('/cyphtwebmail/index.php', 1).'?cypht='.urlencode($cypht);

		$langs->load('cyphtwebmail@cyphtwebmail');
		$text = img_picto('', 'fa-envelope-open-text', 'class="pictofixedwidth"').$langs->trans('CyphtNewMessage');
		print dolGetButtonAction($langs->trans('CyphtNewMessageHelp', $email), $text, 'default', $url);

		return 0;
	}

	/**
	 * Email of the document's first contact of the given types, or of its third party.
	 *
	 * @param CommonObject $object Document
	 * @param string[] $codes Contact types, in order of preference
	 * @return string
	 */
	private function documentEmail($object, $codes)
	{
		foreach ($codes as $code) {
			$contacts = $object->liste_contact(-1, 'external', 0, $code);
			foreach (is_array($contacts) ? $contacts : array() as $contact) {
				if (!empty($contact['email'])) {
					return $contact['email'];
				}
			}
		}

		if (empty($object->thirdparty)) {
			$object->fetch_thirdparty();
		}

		return empty($object->thirdparty->email) ? '' : $object->thirdparty->email;
	}

	/**
	 * @return bool
	 */
	private function dashboardEnabled()
	{
		return isModEnabled('cyphtwebmail') && getDolGlobalString('CYPHTWEBMAIL_DASHBOARD', 'true') === 'true';
	}
}
