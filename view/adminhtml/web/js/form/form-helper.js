/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 *
 * - After a failed save: opens every step with a problem, shows each message at its field and scrolls to the first.
 *   The messages come from the server (data "field_errors": field name => message).
 * - Asks before leaving the page with unsaved changes (not when saving).
 */
define([
    'underscore',
    'uiElement',
    'uiRegistry'
], function (_, Element, registry) {
    'use strict';

    return Element.extend({
        defaults: {
            template: 'Iranimij_OpenImport/form/empty',
            settleDelay: 2000
        },

        /** @inheritdoc */
        initialize: function () {
            this._super();
            this.saving = false;
            registry.get(this.provider, function (provider) {
                var errors = provider.get('data.field_errors');

                this.dataSource = provider;
                if (_.isObject(errors) && !_.isEmpty(errors)) {
                    this.show(errors);
                }
                // Fields adjust some values while the form builds; compare against the settled form.
                _.delay(function () {
                    this.saved = this.snapshot();
                }.bind(this), this.settleDelay);
            }.bind(this));
            registry.get(this.parentName, function (form) {
                var submit = form.submit;

                form.submit = function () {
                    this.saving = true;

                    return submit.apply(form, arguments);
                }.bind(this);
            }.bind(this));
            window.addEventListener('beforeunload', this.beforeLeave.bind(this));

            return this;
        },

        /**
         * @returns {String}
         */
        snapshot: function () {
            return JSON.stringify(_.omit(this.dataSource.get('data') || {}, 'field_errors', 'source_test'));
        },

        /**
         * @param {Event} event
         * @returns {String|undefined}
         */
        beforeLeave: function (event) {
            if (this.saving || this.saved === undefined || this.snapshot() === this.saved) {
                return undefined;
            }
            event.preventDefault();
            event.returnValue = '';

            return '';
        },

        /**
         * @param {Object} errors field name => message
         */
        show: function (errors) {
            var first = true;

            _.each(errors, function (message, name) {
                registry.get('index = ' + name + ', ns = ' + this.ns, function (field) {
                    var fieldset = registry.get(field.parentName);

                    if (fieldset && typeof fieldset.opened === 'function') {
                        fieldset.opened(true);
                    }
                    if (typeof field.error === 'function') {
                        field.error(message);
                    }
                    if (first) {
                        first = false;
                        _.delay(function () {
                            var element = document.getElementById(field.uid)
                                || document.querySelector('[data-index="' + name + '"]');

                            if (element) {
                                element.scrollIntoView({block: 'center'});
                            }
                        }, 600);
                    }
                });
            }, this);
        }
    });
});
