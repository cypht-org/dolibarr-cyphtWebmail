<?php

/**
 * Saves the user's mail counts for the Webmail box on the Dolibarr home page.
 * @package modules
 * @subpackage dolibarr_dashboard
 */

if (!defined('DEBUG_MODE')) { die(); }

/**
 * Count unread and flagged messages in each inbox and save them.
 * @subpackage dolibarr_dashboard/handler
 */
class Hm_Handler_dolibarr_mail_status extends Hm_Handler_Module {

    /** Minimum seconds between two checks */
    const INTERVAL = 60;

    /** Unread messages read per account to find the ones from contacts */
    const MAX_HEADERS = 50;

    public function process() {
        $login = (string) $this->session->get('username', '');
        if ($login === '') {
            return;
        }

        $store = new Hm_Dolibarr_Mail_Status($this->config);
        $row = $store->load($login);
        if ($row === false || time() - $row['checked'] < self::INTERVAL) {
            return;
        }

        $contacts = $this->contact_addresses($login);
        $unread = 0;
        $flagged = 0;
        $dates = array();

        foreach (array_keys(Hm_IMAP_List::dump()) as $id) {
            $mailbox = Hm_IMAP_List::get_connected_mailbox($id, $this->cache);
            if (!$mailbox || !$mailbox->authed() || !$mailbox->is_imap()) {
                continue;
            }

            $status = $mailbox->get_folder_status('INBOX');
            if (is_array($status) && !empty($status['unseen'])) {
                $unread += (int) $status['unseen'];
            }

            $uids = $mailbox->search('INBOX', 'FLAGGED');
            $flagged += is_array($uids) ? count($uids) : 0;

            if ($contacts) {
                $dates = array_merge($dates, $this->contact_dates($mailbox, $contacts));
            }
        }

        $store->save($row, $unread, $flagged, $dates);
        $this->out('dolibarr_mail_status', 'saved');
    }

    /**
     * Email addresses of the user's Dolibarr contacts, third parties and members.
     *
     * @param string $login
     * @return array<string,bool>
     */
    private function contact_addresses($login) {
        $data = Hm_Dolibarr_Cache::read('contacts', $login, 'contacts');
        if ($data === false) {
            return array();
        }

        $addresses = array();
        foreach ($data['contacts'] as $contact) {
            $type = isset($contact['all_fields']['dol_type']) ? $contact['all_fields']['dol_type'] : '';
            if ($type !== 'user' && !empty($contact['email_address'])) {
                $addresses[mb_strtolower($contact['email_address'])] = true;
            }
        }

        return $addresses;
    }

    /**
     * Dates of the unread messages sent by a contact in the last 30 days.
     *
     * @param Hm_Mailbox $mailbox
     * @param array<string,bool> $contacts
     * @return int[] Unix timestamps
     */
    private function contact_dates($mailbox, $contacts) {
        $uids = $mailbox->search('INBOX', 'UNSEEN', array(array('SINCE', date('j-M-Y', strtotime('-30 days')))));
        if (!is_array($uids) || !$uids) {
            return array();
        }

        $dates = array();
        foreach ((array) $mailbox->get_message_list('INBOX', array_slice($uids, -self::MAX_HEADERS)) as $msg) {
            $from = process_address_fld(isset($msg['from']) ? $msg['from'] : '');
            $sender = reset($from);
            if (!$sender || !isset($contacts[mb_strtolower($sender['email'])])) {
                continue;
            }

            $time = strtotime(isset($msg['internal_date']) ? $msg['internal_date'] : '');
            if (!$time) {
                $time = strtotime(isset($msg['date']) ? $msg['date'] : '');
            }
            if ($time) {
                $dates[] = $time;
            }
        }

        return $dates;
    }
}

/**
 * Reads and writes llx_cyphtwebmail_mailstatus.
 * @subpackage dolibarr_dashboard/lib
 */
class Hm_Dolibarr_Mail_Status {

    /** @var object Site config */
    private $config;

    /** @var object|false|null Database handle */
    private $dbh = null;

    public function __construct($config) {
        $this->config = $config;
    }

    /**
     * Find the Dolibarr user and the time of the last check.
     *
     * @param string $login
     * @return array|false fk_user, entity, rowid and checked (Unix time, 0 if never)
     */
    public function load($login) {
        $dbh = $this->db();
        if (!$dbh) {
            return false;
        }

        $user = Hm_DB::execute($dbh, 'select rowid, entity from '.$this->table('user').' where login = ?', array($login), 'select');
        if (!is_array($user) || empty($user['rowid'])) {
            return false;
        }

        $row = Hm_DB::execute($dbh, 'select rowid, date_check from '.$this->table('cyphtwebmail_mailstatus').' where fk_user = ?',
            array((int) $user['rowid']), 'select');

        return array(
            'fk_user' => (int) $user['rowid'],
            'entity' => empty($user['entity']) ? 1 : (int) $user['entity'],
            'rowid' => is_array($row) && !empty($row['rowid']) ? (int) $row['rowid'] : 0,
            'checked' => is_array($row) && !empty($row['date_check']) ? (int) strtotime($row['date_check'].' UTC') : 0,
        );
    }

    /**
     * @param array $row From load()
     * @param int $unread
     * @param int $flagged
     * @param int[] $dates Unread messages from contacts
     * @return bool
     */
    public function save($row, $unread, $flagged, $dates) {
        $dbh = $this->db();
        if (!$dbh) {
            return false;
        }

        $values = array($unread, $flagged, json_encode(array_values($dates)), gmdate('Y-m-d H:i:s'));

        if ($row['rowid']) {
            $values[] = $row['rowid'];
            $sql = 'update '.$this->table('cyphtwebmail_mailstatus').
                ' set nb_unread = ?, nb_flagged = ?, contact_unread = ?, date_check = ? where rowid = ?';
            return Hm_DB::execute($dbh, $sql, $values, 'modify') !== false;
        }

        array_unshift($values, $row['entity'], $row['fk_user']);
        $sql = 'insert into '.$this->table('cyphtwebmail_mailstatus').
            ' (entity, fk_user, nb_unread, nb_flagged, contact_unread, date_check) values (?, ?, ?, ?, ?, ?)';
        return Hm_DB::execute($dbh, $sql, $values, 'insert') !== false;
    }

    /**
     * @return object|false
     */
    private function db() {
        if ($this->dbh === null) {
            $this->dbh = Hm_DB::connect($this->config);
        }
        return $this->dbh;
    }

    /**
     * @param string $name Table name without prefix
     * @return string
     */
    private function table($name) {
        return preg_replace('/[^a-zA-Z0-9_]/', '', (string) Hm_Environment::get('DOLIBARR_DB_PREFIX', 'llx_')).$name;
    }
}
