/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 *
 * A select shown only where it matters (see conditional.js). Options with their own "showWhen" are offered only
 * while their conditions hold; a value that is no longer offered moves to the first option that is.
 */
define([
    'underscore',
    'Magento_Ui/js/form/element/select',
    'Iranimij_OpenImport/js/form/conditional'
], function (_, Select, conditional) {
    'use strict';

    return conditional(Select).extend({
        /** @inheritdoc */
        initialize: function () {
            var options;

            this.allOptions = null;
            this._super();
            options = _.isArray(this.initialOptions) ? this.initialOptions : this.options();
            this.allOptions = _.some(options, function (option) {
                return !!option.showWhen;
            }) ? options : null;
            this.refresh();

            return this;
        },

        /**
         * @param {Array} options
         * @returns {String}
         */
        optionKey: function (options) {
            return _.map(options || [], function (option) {
                return option.value + '=' + option.label;
            }).join('\n');
        },

        /** @inheritdoc */
        refresh: function () {
            var offered, value, blank;

            this._super();
            if (!this.allOptions) {
                return;
            }
            offered = _.filter(this.allOptions, function (option) {
                return this.conditionsHold(option.showWhen);
            }, this);
            if (this.optionKey(offered) !== this.optionKey(this.options())) {
                value = this.value();
                this.setOptions(offered);
                // Magento turns an option without a value into the caption, which then stays: follow its conditions.
                blank = _.find(this.allOptions, function (option) {
                    return option.value === '' || option.value === null;
                });
                if (blank && typeof this.caption === 'function') {
                    this.caption(_.contains(offered, blank) ? blank.label : '');
                }
                if (offered.length && !_.findWhere(offered, {value: value})) {
                    this.value(offered[0].value);
                } else {
                    this.value(value);
                }
            }
        }
    });
});
