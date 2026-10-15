/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 *
 * Row actions that change something (dry run, duplicate …) are posted with the form key even without a
 * confirmation dialog; Magento's actions column would otherwise follow them as plain links (GET, 404 on POST-only
 * controllers).
 */
define([
    'Magento_Ui/js/grid/columns/actions'
], function (Actions) {
    'use strict';

    return Actions.extend({
        /** @inheritdoc */
        isHandlerRequired: function (actionIndex, rowIndex) {
            var action = this.getAction(rowIndex, actionIndex);

            return !!(action && action.post) || this._super(actionIndex, rowIndex);
        }
    });
});
