/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 *
 * Asks before a form changes the shop ("Run again" for a live run); the dialog's button says what happens.
 */
define([
    'Magento_Ui/js/modal/confirm',
    'mage/translate'
], function (confirm, $t) {
    'use strict';

    return function (config, form) {
        var confirmed = false;

        form.addEventListener('submit', function (event) {
            if (confirmed) {
                return;
            }
            event.preventDefault();
            confirm({
                title: config.title,
                content: config.message,
                actions: {
                    /** Sends the form. */
                    confirm: function () {
                        confirmed = true;
                        form.submit();
                    }
                },
                buttons: [{
                    text: config.dismiss || $t('Cancel'),
                    class: 'action-secondary action-dismiss',

                    /** @param {Event} closeEvent */
                    click: function (closeEvent) {
                        this.closeModal(closeEvent);
                    }
                }, {
                    text: config.button,
                    class: 'action-primary action-accept',

                    /** @param {Event} closeEvent */
                    click: function (closeEvent) {
                        this.closeModal(closeEvent, true);
                    }
                }]
            });
        });
    };
});
