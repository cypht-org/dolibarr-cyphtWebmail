<?php

/**
 * Puts a Dolibarr card under the headers of an open message: who the sender is,
 * the third party behind them, and what is still open against that third party.
 *
 * The message response carries an empty shell and the sender's address. The
 * card itself arrives over a second request, so a slow or unreachable Dolibarr
 * delays the panel and never the message body.
 *
 * @package modules
 * @subpackage dolibarr_context
 */

if (!defined('DEBUG_MODE')) { die(); }

require_once APP_PATH.'modules/dolibarr_context/hm-dolibarr-context.php';

/**
 * Fetches one address's card, through a small per-address session cache.
 *
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
            /* Not an error worth reporting: plenty of From headers are not
             * addresses at all. The panel stays hidden. */
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
            /* Keep serving the stale card rather than blanking a panel the
             * user is looking at; the reason is on the debug log. */
            if (is_array($entry)) {
                $this->out('dolibarr_context_status', 'stale');
                $this->out('dolibarr_context_data', json_encode($entry['data']));
                return;
            }
            $this->out('dolibarr_context_status', 'error');
            return;
        }

        /* Least recently used out first, so reading down a long folder cannot
         * grow the session file without bound. Unset before set, or refreshing
         * an address would keep its original position and the eviction below
         * would be first-ever-seen rather than least-recently-used. */
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
 * Appends the empty panel to the message headers.
 *
 * Appended to 'msg_headers' rather than emitted under a key of its own,
 * because the message view assembles its DOM from three named pieces in
 * imap/site.js and a fourth would have nowhere to land. The key is written
 * unprotected upstream, so extending it is supported rather than a squeeze.
 *
 * @subpackage dolibarr_context/output
 */
class Hm_Output_dolibarr_context_shell extends Hm_Output_Module {

    protected function output() {
        $headers = $this->get('msg_headers', '');
        if (!is_string($headers) || $headers === '') {
            return ''; /* not a rendered message, or headers failed to build */
        }

        $email = trim((string) $this->get('sender_email', ''));
        if ($email === '') {
            /* filter_message_headers only sets sender_email when it recognises
             * the From line. filter_headers carries the parsed address for the
             * shapes it does not. */
            $filtered = $this->get('filter_headers', array());
            if (is_array($filtered) && array_key_exists('from', $filtered)) {
                $email = trim((string) $filtered['from']);
            }
        }

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return '';
        }

        /* Every string the panel can show is translated here and carried on
         * the shell: site.js has no access to trans(). */
        $shell = '<div id="dolibarr_context" class="dolibarr_context border-bottom border-secondary-subtle py-2"'.
            ' data-email="'.$this->html_safe($email).'"'.
            ' data-str-loading="'.$this->html_safe($this->trans('Checking Dolibarr...')).'"'.
            ' data-str-unknown="'.$this->html_safe($this->trans('Not in Dolibarr')).'"'.
            ' data-str-failed="'.$this->html_safe($this->trans('Dolibarr could not be reached')).'"'.
            ' data-str-stale="'.$this->html_safe($this->trans('Showing the last known state')).'"'.
            ' data-str-open="'.$this->html_safe($this->trans('Open in Dolibarr')).'"'.
            ' data-str-more="'.$this->html_safe($this->trans('See all')).'"'.
            ' data-str-details="'.$this->html_safe($this->trans('Details')).'"'.
            ' data-str-customer="'.$this->html_safe($this->trans('Customer')).'"'.
            ' data-str-supplier="'.$this->html_safe($this->trans('Supplier')).'"'.
            '>'.
            '<div class="dolibarr_context_body small text-muted"></div>'.
            '</div>';

        /* Third argument false: msg_headers was written unprotected by
         * filter_message_headers, which is what makes this append legal. */
        $this->out('msg_headers', $headers.$shell, false);

        return '';
    }
}
