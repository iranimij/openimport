/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 *
 * "What": the note under the select explains the chosen entity (each option carries its own hint).
 */
define([
    'underscore',
    'Magento_Ui/js/form/element/select'
], function (_, Select) {
    'use strict';

    return Select.extend({
        /** @inheritdoc */
        initialize: function () {
            this._super();
            this.defaultNotice = this.notice();
            this.showHint(this.value());

            return this;
        },

        /** @inheritdoc */
        onUpdate: function (value) {
            this.showHint(value);

            return this._super();
        },

        /**
         * @param {String} value
         */
        showHint: function (value) {
            var option = _.findWhere(this.initialOptions || this.options() || [], {value: value});

            this.notice(option && option.hint ? option.hint : this.defaultNotice);
        }
    });
});
