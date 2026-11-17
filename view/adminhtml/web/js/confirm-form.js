/**
 * Asks before submitting the form: each of these forms sends records to Centraliza.
 */
define(['jquery', 'Magento_Ui/js/modal/confirm'], function ($, confirm) {
    'use strict';

    return function (config, form) {
        $(form).on('submit', function (event) {
            event.preventDefault();
            confirm({
                content: config.message,
                actions: {
                    confirm: function () {
                        // The native submit skips this handler.
                        form.submit();
                    }
                }
            });
        });
    };
});
