define(['jquery', 'mage/translate'], function ($, $t) {
    'use strict';

    /**
     * Magento renders this placeholder for an obscure field when the stored value
     * was not edited. Sending it to the server would validate the asterisks and
     * fail every time, so the request asks for the stored key instead.
     */
    var OBSCURED_VALUE = '******';

    /**
     * Applies the message/colour pair the server resolved through
     * ConnectionPresenter — never derived here, so a click and a reload of the
     * same state always render identically.
     *
     * @param {jQuery} $status
     * @param {string} message
     * @param {?string} color
     */
    function applyPresentation($status, message, color) {
        // .text(), never .html(): the message can carry the upstream API's own
        // error body, which is third-party content.
        $status.css('color', color || '').text(message);
    }

    return function (config, element) {
        var $root = $(element),
            $button = $root.find('[data-role="centraliza-authenticate"]'),
            $status = $root.find('[data-role="centraliza-connection-status"]'),
            $apiKey = $('#' + config.apiKeyFieldId),
            $environment = $('#' + config.environmentFieldId);

        /**
         * @param {Object} payload
         */
        function validate(payload) {
            $button.prop('disabled', true);
            applyPresentation($status, $t('Validating...'), null);

            $.post(config.validateUrl, payload)
                .done(function (response) {
                    // A non-JSON reply (an invalid form key renders an HTML page) has no
                    // message; without the fallback the label stays on "Validating...".
                    if (!response || typeof response.message !== 'string') {
                        applyPresentation($status, $t('Could not reach the server. Try again.'), null);

                        return;
                    }

                    applyPresentation($status, response.message, response.color);
                })
                .fail(function () {
                    applyPresentation($status, $t('Could not reach the server. Try again.'), null);
                })
                .always(function () {
                    // Leaving the button disabled after a failure forces a page reload
                    // and loses whatever the administrator had typed.
                    $button.prop('disabled', false);
                });
        }

        $button.on('click', function () {
            // Only the saved environment is checked: its key field is the one read here, and the server reads
            // the stored key and URL of the saved environment too.
            if ($environment.length && $environment.val() !== config.environment) {
                applyPresentation(
                    $status,
                    $t('Save the configuration before checking the key of another environment.'),
                    null
                );

                return;
            }

            var typedKey = $.trim($apiKey.val() || ''),
                useStored = typedKey === '' || typedKey === OBSCURED_VALUE,
                payload = {
                    form_key: window.FORM_KEY,
                    use_stored: useStored ? 'true' : 'false'
                };

            if (!useStored) {
                payload.api_key = typedKey;
            }

            if (config.websiteId !== null && config.websiteId !== undefined) {
                payload.website = config.websiteId;
            }

            validate(payload);
        });

        // Runs after the page is rendered, so an unreachable Centraliza never holds the
        // screen; the server skips the API call when the scope was checked recently.
        if (config.autoCheck) {
            var autoPayload = {form_key: window.FORM_KEY, use_stored: 'true', auto: 'true'};

            if (config.websiteId !== null && config.websiteId !== undefined) {
                autoPayload.website = config.websiteId;
            }

            validate(autoPayload);
        }
    };
});
