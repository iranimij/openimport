/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 *
 * A select shown only where it matters (see conditional.js). Options with their own "showWhen" are offered only
 * while their conditions hold; a value that is no longer offered moves to the first option that is. With
 * "noticeByValue" the note under the field explains the chosen option (followed by the field's own note).
 */
define([
    'underscore',
    'Magento_Ui/js/form/element/select',
    'Iranimij_OpenImport/js/form/conditional'
], function (_, Select, conditional) {
    'use strict';

    /**
     * First real value of a list of options (option groups carry their options in "value").
     *
     * @param {Array} options
     * @returns {*}
     */
    function firstValue(options) {
        var found;

        _.some(options || [], function (option) {
            found = _.isArray(option.value) ? firstValue(option.value) : option.value;

            return !_.isUndefined(found);
        });

        return found;
    }

    return conditional(Select).extend({
        /**
         * Magento's select passes the options observable itself to its findFirst(), which breaks (and silently drops
         * the whole field) for a select without a caption and without a value. Read the observable's list instead.
         *
         * @returns {Object} Chainable
         */
        clear: function () {
            this.value(this.caption() ? '' : firstValue(this.options()));

            return this;
        },

        /** @inheritdoc */
        normalizeData: function (value) {
            var option;

            if (value !== '' && !_.isUndefined(value) && value !== null) {
                option = this.getOption(value);

                return option && option.value;
            }

            return this.caption() ? '' : firstValue(this.options());
        },

        /** @inheritdoc */
        initObservable: function () {
            this._super();
            // Magento may already have made "notice" observable: keep its text, not the observable.
            this.baseNotice = (_.isFunction(this.notice) ? this.notice() : this.notice) || '';
            if (!_.isEmpty(this.noticeByValue) && !_.isFunction(this.notice)) {
                this.observe('notice');
            }

            return this;
        },

        /**
         * The note for the chosen option, then the field's own note.
         *
         * @param {*} value
         */
        explainValue: function (value) {
            var text = (this.noticeByValue || {})[value] || '';

            if (_.isEmpty(this.noticeByValue)) {
                return;
            }
            this.notice(_.compact([text, this.baseNotice]).join(' '));
        },

        /** @inheritdoc */
        initialize: function () {
            var options;

            this.allOptions = null;
            this._super();
            if (!_.isEmpty(this.noticeByValue)) {
                this.value.subscribe(this.explainValue, this);
                this.explainValue(this.value());
            }
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
            // Magento turns an option without a value into the caption, which then stays: follow its conditions
            // (also when the list of real options did not change, e.g. no saved connection of a connection-only type).
            blank = _.find(this.allOptions, function (option) {
                return option.value === '' || option.value === null;
            });
            if (blank && typeof this.caption === 'function') {
                this.caption(_.contains(offered, blank) ? blank.label : '');
            }
            if (this.optionKey(offered) !== this.optionKey(this.options())) {
                value = this.value();
                this.setOptions(offered);
                if (offered.length && !_.findWhere(offered, {value: value})) {
                    this.value(offered[0].value);
                } else {
                    this.value(value);
                }
            }
        }
    });
});
