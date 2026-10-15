'use strict';

/* Updates the mail counts shown on the Dolibarr home page. */
var dolibarr_mail_status_refresh = function() {
    Hm_Ajax.request([{'name': 'hm_ajax_hook', 'value': 'ajax_dolibarr_mail_status'}], false, [], true);
};

$(document).ready(function() {
    if (typeof hm_is_logged !== 'function' || !hm_is_logged()) {
        return;
    }
    setTimeout(dolibarr_mail_status_refresh, 5000);
    setInterval(dolibarr_mail_status_refresh, 120000);
});
