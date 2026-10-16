<?php

/**
 * Shows the sender's Dolibarr records under the headers of an open message.
 * @package modules
 * @subpackage dolibarr_context
 */

if (!defined('DEBUG_MODE')) { die(); }

require_once APP_PATH.'modules/dolibarr_context/hm-dolibarr-context.php';
require_once APP_PATH.'modules/dolibarr_context/hm-dolibarr-context-lang.php';

/**
 * Get the records for an address, with a small session cache.
 * @subpackage dolibarr_context/handler
 */
class Hm_Handler_load_dolibarr_context extends Hm_Handler_Module {

    public function process() {
        list($success, $form) = $this->process_form(array('dolibarr_context_email'));
        if (!$success) {
            return;
        }

        $email = trim(mb_strtolower((string) $form['dolibarr_context_email']));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $login = $this->session->get('username', false);
        if (!$login) {
            return;
        }

        $source = new Hm_Dolibarr_Context();
        if (!$source->configured()) {
            $this->out('dolibarr_context_status', 'unconfigured');
            return;
        }

        $cache = $this->session->get('dolibarr_context_cache', array());
        if (!is_array($cache)) {
            $cache = array();
        }

        $now = time();
        $entry = array_key_exists($email, $cache) ? $cache[$email] : false;

        if (is_array($entry) && ($now - (int) $entry['at']) <= $source->ttl()) {
            $this->out('dolibarr_context_status', 'ok');
            $this->out('dolibarr_context_data', json_encode($entry['data']));
            return;
        }

        $fetched = $source->fetch($login, $email);
        if ($fetched === false) {
            if ($source->forbidden()) {
                $this->out('dolibarr_context_status', 'forbidden');
                return;
            }
            if (is_array($entry)) {
                $this->out('dolibarr_context_status', 'stale');
                $this->out('dolibarr_context_data', json_encode($entry['data']));
                return;
            }
            $this->out('dolibarr_context_status', 'error');
            return;
        }

        /* Unset first so the entry moves to the end (LRU). */
        unset($cache[$email]);
        $cache[$email] = array('at' => $now, 'data' => $fetched);
        while (count($cache) > $source->cache_limit()) {
            array_shift($cache);
        }
        $this->session->set('dolibarr_context_cache', $cache);

        $this->out('dolibarr_context_status', 'ok');
        $this->out('dolibarr_context_data', json_encode($fetched));
    }
}

/**
 * Create a Dolibarr prospect from the sender of the open message.
 * @subpackage dolibarr_context/handler
 */
class Hm_Handler_dolibarr_context_create extends Hm_Handler_Module {

    public function process() {
        list($success, $form) = $this->process_form(array('dolibarr_context_email'));
        if (!$success) {
            return;
        }

        $email = trim(mb_strtolower((string) $form['dolibarr_context_email']));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->out('dolibarr_context_create_status', 'error');
            return;
        }

        $name = '';
        if (array_key_exists('dolibarr_context_name', $this->request->post)) {
            $name = trim((string) $this->request->post['dolibarr_context_name']);
        }

        $login = $this->session->get('username', false);
        if (!$login) {
            return;
        }

        $source = new Hm_Dolibarr_Context_Create();
        if (!$source->configured()) {
            $this->out('dolibarr_context_create_status', 'unconfigured');
            return;
        }

        $result = $source->create($login, $email, $name);
        if ($result === false) {
            $this->out('dolibarr_context_create_status', 'error');
            return;
        }

        if (array_key_exists('error', $result)) {
            $this->out('dolibarr_context_create_status', 'refused');
            $this->out('dolibarr_context_create_message', (string) $result['error']);
            return;
        }

        $cache = $this->session->get('dolibarr_context_cache', array());
        if (is_array($cache) && array_key_exists($email, $cache)) {
            unset($cache[$email]);
            $this->session->set('dolibarr_context_cache', $cache);
        }

        $this->out('dolibarr_context_create_status',
            (!empty($result['created'])) ? 'created' : 'existing');
        if (array_key_exists('url', $result)) {
            $this->out('dolibarr_context_create_url', (string) $result['url']);
        }
        if (array_key_exists('name', $result)) {
            $this->out('dolibarr_context_create_name', (string) $result['name']);
        }
    }
}

/**
 * Add the empty panel to the message headers. site.js fills it.
 * @subpackage dolibarr_context/output
 */
class Hm_Output_dolibarr_context_shell extends Hm_Output_Module {

    /**
     * Use this module's translations first, then Cypht's.
     * @param string $string String to translate
     * @return string
     */
    public function trans($string) {
        $strings = Hm_Dolibarr_Context_Lang::get($this->lang);

        if (array_key_exists($string, $strings) && $strings[$string] !== '') {
            return strip_tags($strings[$string]);
        }

        return parent::trans($string);
    }

    protected function output() {
        $headers = $this->get('msg_headers', '');
        if (!is_string($headers) || $headers === '') {
            return '';
        }

        $email = trim((string) $this->get('sender_email', ''));
        if ($email === '') {
            $filtered = $this->get('filter_headers', array());
            if (is_array($filtered) && array_key_exists('from', $filtered)) {
                $email = trim((string) $filtered['from']);
            }
        }

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return '';
        }

        $shell = '<div id="dolibarr_context" class="dolibarr_context border-bottom border-secondary-subtle py-2"'.
            ' data-email="'.$this->html_safe($email).'"'.
            ' data-str-caption="'.$this->html_safe($this->trans('Sender in your records')).'"'.
            ' data-str-empty="'.$this->html_safe($this->trans('Nothing open')).'"'.
            ' data-str-loading="'.$this->html_safe($this->trans('Checking records...')).'"'.
                        ' data-str-failed="'.$this->html_safe($this->trans('Records could not be loaded')).'"'.
            ' data-str-retry="'.$this->html_safe($this->trans('Check records')).'"'.
            ' data-str-stale="'.$this->html_safe($this->trans('Showing the last known state')).'"'.
            ' data-str-open="'.$this->html_safe($this->trans('Open this record')).'"'.
            ' data-str-more="'.$this->html_safe($this->trans('See all')).'"'.
            ' data-str-details="'.$this->html_safe($this->trans('Details')).'"'.
            ' data-str-customer="'.$this->html_safe($this->trans('Customer')).'"'.
            ' data-str-prospect="'.$this->html_safe($this->trans('Prospect')).'"'.
            ' data-str-supplier="'.$this->html_safe($this->trans('Supplier')).'"'.
            ' data-str-add="'.$this->html_safe($this->trans('Add sender as prospect')).'"'.
            ' data-str-add-why="'.$this->html_safe($this->trans('No third party or contact has this email address.')).'"'.
            ' data-str-add-title="'.$this->html_safe($this->trans('Add sender as prospect')).'"'.
            ' data-str-add-name="'.$this->html_safe($this->trans('Name')).'"'.
            ' data-str-add-hint="'.$this->html_safe($this->trans('Creates a prospect. You can turn it into a customer later.')).'"'.
            ' data-str-add-save="'.$this->html_safe($this->trans('Create prospect')).'"'.
            ' data-str-add-working="'.$this->html_safe($this->trans('Creating...')).'"'.
            ' data-str-add-done="'.$this->html_safe($this->trans('Prospect created')).'"'.
            ' data-str-add-existing="'.$this->html_safe($this->trans('This sender already has a record')).'"'.
            ' data-str-add-failed="'.$this->html_safe($this->trans('Could not add this sender')).'"'.
            ' data-str-add-open="'.$this->html_safe($this->trans('Open the record')).'"'.
            '>'.
            '<div class="dolibarr_context_body small text-muted d-flex flex-wrap align-items-baseline gap-2"></div>'.
            '</div>';

        $this->out('msg_headers', $headers.$shell, false);

        return '';
    }
}
