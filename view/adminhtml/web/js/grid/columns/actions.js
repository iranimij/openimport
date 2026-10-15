/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 *
 * Grid row actions:
 * - an action with "post" is always sent as a POST form (core only does that after a confirmation dialog, and
 *   sends the others as plain links, which the POST-only controllers answer with 404);
 * - a confirmation may name its button ("button"), instead of "OK".
 */
define([
    'Magento_Ui/js/grid/columns/actions',
    'Magento_Ui/js/modal/confirm',
    'mage/translate'
], function (Actions, confirm, $t) {
    'use strict';

    return Actions.extend({
        /** @inheritdoc */
        isHandlerRequired: function (actionIndex, rowIndex) {
            var action = this.getAction(rowIndex, actionIndex);

            return !!(action && action.post) || this._super(actionIndex, rowIndex);
        },

        /** @inheritdoc */
        _confirm: function (action, callback) {
            var data = action.confirm;

            if (!data.button) {
                return this._super(action, callback);
            }
            confirm({
                title: data.title,
                content: data.message,
                actions: {
                    confirm: callback
                },
                buttons: [{
                    text: $t('Cancel'),
                    class: 'action-secondary action-dismiss',

                    /** @param {Event} event */
                    click: function (event) {
                        this.closeModal(event);
                    }
                }, {
                    text: data.button,
                    class: 'action-primary action-accept',

                    /** @param {Event} event */
                    click: function (event) {
                        this.closeModal(event, true);
                    }
                }]
            });
        }
    });
});
