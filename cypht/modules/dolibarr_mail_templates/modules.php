<?php

/**
 * Puts Dolibarr's email templates on the Cypht compose screen: pick a type,
 * pick a template of that type, and the subject and body are filled in.
 *
 * The compose page is served with type names only. Labels and bodies arrive
 * over ajax as they are asked for, so a template the user never opens never
 * reaches the browser.
 *
 * @package modules
 * @subpackage dolibarr_mail_templates
 */

if (!defined('DEBUG_MODE')) { die(); }

require_once APP_PATH.'modules/dolibarr_mail_templates/hm-dolibarr-mail-templates.php';

/**
 * Loads the template list into module output, for the compose page and for the
 * ajax page behind it.
 *
 * The full list is put in output unfiltered, which keeps it available to the
 * request handler below while keeping it out of any response: Cypht emits only
 * keys named in 'allowed_output', and this one is deliberately not among them.
 *
 * @subpackage dolibarr_mail_templates/handler
 */
class Hm_Handler_load_dolibarr_mail_templates extends Hm_Handler_Module {

    public function process() {
        $login = $this->session->get('username', false);
        if (!$login) {
            return;
        }

        $source = new Hm_Dolibarr_Mail_Templates();
        if (!$source->configured()) {
            $this->out('dolibarr_mail_templates_status', 'unconfigured');
            return;
        }

        $status = 'ok';

        /* Same reasoning as dolibarr_contacts: without a session cache every
         * visit to compose is an HTTP round trip to Dolibarr. Templates move
         * even less than contacts, hence the longer default TTL. The cache
         * lives in the session, server side, so caching the bodies here is not
         * the same exposure as putting them in the page. */
        $cache = $this->session->get('dolibarr_mail_templates_cache', false);
        $types = $this->session->get('dolibarr_mail_templates_types', array());
        $hint = (string) $this->session->get('dolibarr_mail_templates_hint', '');
        $stamp = (int) $this->session->get('dolibarr_mail_templates_cache_at', 0);

        if (!is_array($cache) || (time() - $stamp) > $source->ttl()) {
            $fetched = $source->fetch($login);
            if ($fetched === false) {
                /* Keep serving the stale list rather than blanking the picker
                 * mid-session; the reason is on the debug log. */
                $status = 'error';
                if (!is_array($cache)) {
                    $cache = array();
                }
                if (!is_array($types)) {
                    $types = array();
                }
            } else {
                $cache = $fetched['templates'];
                $types = $fetched['types'];
                $hint = $fetched['hint'];
                $this->session->set('dolibarr_mail_templates_cache', $cache);
                $this->session->set('dolibarr_mail_templates_types', $types);
                $this->session->set('dolibarr_mail_templates_cache_at', time());
                $this->session->set('dolibarr_mail_templates_hint', $hint);
            }
        }

        $this->out('dolibarr_mail_templates', $cache, false);
        $this->out('dolibarr_mail_template_types', $types, false);
        $this->out('dolibarr_mail_template_count', count($cache));
        $this->out('dolibarr_mail_templates_status', $status);
        $this->out('dolibarr_mail_templates_hint', $hint);
    }
}

/**
 * Answers one ajax question: the templates of a type, or one template's text.
 *
 * @subpackage dolibarr_mail_templates/handler
 */
class Hm_Handler_dolibarr_mail_templates_request extends Hm_Handler_Module {

    public function process() {
        $templates = $this->get('dolibarr_mail_templates', array());
        if (!is_array($templates)) {
            return;
        }

        list($success, $form) = $this->process_form(array('dolibarr_template_type'));

        /* An id asks for one template's text. Checked before the type branch
         * so a request carrying both is answered with the narrower thing. */
        if (array_key_exists('dolibarr_template_id', $this->request->post)) {
            $id = (int) $this->request->post['dolibarr_template_id'];
            foreach ($templates as $template) {
                if (!is_array($template) || (int) $template['id'] !== $id) {
                    continue;
                }
                $this->out('dolibarr_mail_template_selected', json_encode(array(
                    'subject' => isset($template['subject']) ? (string) $template['subject'] : '',
                    'body' => isset($template['body']) ? (string) $template['body'] : '',
                    'placeholders' => (isset($template['placeholders']) && is_array($template['placeholders']))
                        ? array_values($template['placeholders']) : array(),
                )));
                return;
            }
            /* No match: an id for a template this user may not see, or one
             * deleted since the page loaded. Say nothing rather than confirm
             * which of those it was. */
            return;
        }

        /* The index: everything the picker needs to draw and search its list,
         * and nothing it does not. A row carries no subject and no body, only
         * a flag saying whether choosing it will bring placeholders with it,
         * so the warning can be shown next to the name instead of after the
         * text has already landed in the draft. */
        $wantsIndex = array_key_exists('dolibarr_template_index', $this->request->post);
        if (!$wantsIndex && !$success) {
            return;
        }

        $wanted = $wantsIndex ? null : (string) $form['dolibarr_template_type'];
        $list = array();
        foreach ($templates as $template) {
            if (!is_array($template)) {
                continue;
            }
            if ($wanted !== null && (string) $template['type'] !== $wanted) {
                continue;
            }
            $label = isset($template['label']) ? (string) $template['label'] : '';
            if ($label === '') {
                $label = $this->trans('Untitled template');
            }
            $lang = isset($template['lang']) ? (string) $template['lang'] : '';
            if ($lang !== '') {
                $label .= ' ('.$lang.')';
            }
            $list[] = array(
                'id' => (int) $template['id'],
                'label' => $label,
                'type' => isset($template['type']) ? (string) $template['type'] : '',
                'type_label' => isset($template['type_label']) ? (string) $template['type_label'] : '',
                'flagged' => (isset($template['placeholders']) && is_array($template['placeholders'])
                    && count($template['placeholders']) > 0),
            );
        }

        $this->out('dolibarr_mail_template_list', json_encode($list));
    }
}

/**
 * Renders the type and template selects above the compose fields.
 *
 * @subpackage dolibarr_mail_templates/output
 */
class Hm_Output_dolibarr_mail_templates_picker extends Hm_Output_Module {

    protected function output() {
        $status = $this->get('dolibarr_mail_templates_status', 'unconfigured');

        /* Nothing was wired up. Compose is the wrong place to explain a build
         * problem, and a broken looking control is worse than none, so stay
         * out of the way entirely. The debug log carries the detail. */
        if ($status === 'unconfigured') {
            return '';
        }

        if ($status === 'error') {
            return '<div class="dolibarr_mail_templates_picker my-2">'.
                '<p class="small text-danger mb-0">'.
                $this->html_safe($this->trans('Dolibarr templates could not be loaded. This is a connection problem, not an empty template list.')).
                '</p></div>';
        }

        $types = $this->get('dolibarr_mail_template_types', array());
        if (!is_array($types)) {
            $types = array();
        }

        if (count($types) === 0) {
            $hint = (string) $this->get('dolibarr_mail_templates_hint', '');
            if ($hint === '') {
                $hint = $this->trans('No Dolibarr email templates available');
            }
            return '<div class="dolibarr_mail_templates_picker my-2">'.
                '<select class="form-select form-select-sm" disabled="disabled">'.
                '<option>'.$this->html_safe($hint).'</option>'.
                '</select></div>';
        }

        $count = (int) $this->get('dolibarr_mail_template_count', 0);

        /* Nothing about any individual template is rendered server side, not
         * even a name: the markup is an empty shell plus a count. site.js asks
         * for the index when the user opens the panel, and for one template's
         * text when they choose it. */
        $res = '<div id="dolibarr_mail_templates_picker" class="dolibarr_mail_templates_picker my-2"'.
            ' data-count="'.$count.'"'.
            /* Translated here, not in site.js, which has no access to trans(). */
            ' data-str-loading="'.$this->html_safe($this->trans('Loading...')).'"'.
            ' data-str-empty="'.$this->html_safe($this->trans('Nothing matches that')).'"'.
            ' data-str-failed="'.$this->html_safe($this->trans('Could not load templates')).'"'.
            ' data-str-warn="'.$this->html_safe($this->trans('Contains placeholders to fill in by hand:')).'"'.
            ' data-str-flagged="'.$this->html_safe($this->trans('Contains placeholders')).'"'.
            ' data-str-inserted="'.$this->html_safe($this->trans('Template inserted')).'"'.
            /* The dialog is built by site.js through core's Hm_Modal, so the
             * strings it needs have to travel with the trigger. */
            /* The dialog is a catalogue, so it is named for what it shows. The
             * button is named for what the user wants to do. */
            ' data-str-title="'.$this->html_safe($this->trans('Email templates')).'"'.
            ' data-str-search="'.$this->html_safe($this->trans('Search templates')).'"'.
            ' data-str-preview="'.$this->html_safe($this->trans('Preview')).'"'.
            ' data-str-insert="'.$this->html_safe($this->trans('Insert')).'"'.
            '>';

        /* The whole point of the collapsed state: one line that says the
         * feature exists, says it is optional, and says how much is behind it.
         * A user who has never heard of Dolibarr templates can read it and
         * decide; a user who has can ignore it. */
        /* Outlined, not solid: it has to be findable without competing with
         * Send, which is the only filled button on this screen and should
         * stay that way. */
        $res .= '<button type="button" id="dolibarr_mail_template_toggle" '.
            'class="btn btn-sm btn-outline-primary">'.
            '<i class="bi bi-file-earmark-text me-1"></i>'.
            $this->html_safe($this->trans('Use a template')).
            '</button>';
        $res .= '<span class="small text-muted ms-2">'.
            $this->html_safe($this->trans('optional')).' &middot; '.
            $this->html_safe(sprintf($this->trans('%d available'), $count)).
            '</span>';

        /* The picker itself is an Hm_Modal, created on first open. Nothing for
         * it is rendered here: core appends the dialog to <body> and site.js
         * fills the body with a static shell it then populates. */

        /* Stays on the page rather than in the dialog: the dialog closes on
         * insert, and the offer to undo has to outlive it. */
        $res .= '<div id="dolibarr_mail_template_undo_bar" class="small mt-1" hidden="hidden">'.
            '<span id="dolibarr_mail_template_undo_text" class="text-success"></span> '.
            '<button type="button" id="dolibarr_mail_template_undo" class="btn btn-link btn-sm p-0 align-baseline">'.
            $this->html_safe($this->trans('Undo')).'</button>'.
            '</div>';

        $res .= '</div>';

        return $res;
    }
}
