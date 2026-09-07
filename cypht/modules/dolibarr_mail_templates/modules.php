<?php

/**
 * Adds a Dolibarr email template picker to the Cypht compose screen.
 *
 * @package modules
 * @subpackage dolibarr_mail_templates
 */

if (!defined('DEBUG_MODE')) { die(); }

require_once APP_PATH.'modules/dolibarr_mail_templates/hm-dolibarr-mail-templates.php';

/**
 * Load the template list for the compose page and the ajax page.
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

        $cache = $this->session->get('dolibarr_mail_templates_cache', false);
        $types = $this->session->get('dolibarr_mail_templates_types', array());
        $hint = (string) $this->session->get('dolibarr_mail_templates_hint', '');
        $stamp = (int) $this->session->get('dolibarr_mail_templates_cache_at', 0);

        if (!is_array($cache) || (time() - $stamp) > $source->ttl()) {
            $fetched = $source->fetch($login);
            if ($fetched === false) {
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
 * Ajax: return the template list, or the text of one template.
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
            return;
        }

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
 * The "Use a template" button on the compose screen.
 *
 * @subpackage dolibarr_mail_templates/output
 */
class Hm_Output_dolibarr_mail_templates_picker extends Hm_Output_Module {

    protected function output() {
        $status = $this->get('dolibarr_mail_templates_status', 'unconfigured');

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

        $res = '<div id="dolibarr_mail_templates_picker" class="dolibarr_mail_templates_picker my-2"'.
            ' data-count="'.$count.'"'.
            ' data-str-loading="'.$this->html_safe($this->trans('Loading...')).'"'.
            ' data-str-empty="'.$this->html_safe($this->trans('Nothing matches that')).'"'.
            ' data-str-failed="'.$this->html_safe($this->trans('Could not load templates')).'"'.
            ' data-str-warn="'.$this->html_safe($this->trans('Contains placeholders to fill in by hand:')).'"'.
            ' data-str-flagged="'.$this->html_safe($this->trans('Contains placeholders')).'"'.
            ' data-str-inserted="'.$this->html_safe($this->trans('Template inserted')).'"'.
            ' data-str-title="'.$this->html_safe($this->trans('Email templates')).'"'.
            ' data-str-search="'.$this->html_safe($this->trans('Search templates')).'"'.
            ' data-str-preview="'.$this->html_safe($this->trans('Preview')).'"'.
            ' data-str-insert="'.$this->html_safe($this->trans('Insert')).'"'.
            '>';

        $res .= '<button type="button" id="dolibarr_mail_template_toggle" '.
            'class="btn btn-sm btn-outline-primary">'.
            '<i class="bi bi-file-earmark-text me-1"></i>'.
            $this->html_safe($this->trans('Use a template')).
            '</button>';
        $res .= '<span class="small text-muted ms-2">'.
            $this->html_safe($this->trans('optional')).' &middot; '.
            $this->html_safe(sprintf($this->trans('%d available'), $count)).
            '</span>';

        $res .= '<div id="dolibarr_mail_template_undo_bar" class="small mt-1" hidden="hidden">'.
            '<span id="dolibarr_mail_template_undo_text" class="text-success"></span> '.
            '<button type="button" id="dolibarr_mail_template_undo" class="btn btn-link btn-sm p-0 align-baseline">'.
            $this->html_safe($this->trans('Undo')).'</button>'.
            '</div>';

        $res .= '</div>';

        return $res;
    }
}
