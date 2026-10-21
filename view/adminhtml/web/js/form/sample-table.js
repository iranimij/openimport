/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 *
 * The first rows of the file, read on the server; read again when the format options change.
 */
define([
    'jquery',
    'underscore',
    'uiElement',
    'mage/translate'
], function ($, _, Element, $t) {
    'use strict';

    return Element.extend({
        defaults: {
            template: 'Iranimij_OpenImport/form/sample-table',
            sampleUrl: '',
            columns: [],
            rows: [],
            detected: {},
            sheets: [],
            error: '',
            loading: false,
            visible: true,
            direction: 'import',
            imports: {
                direction: '${ $.provider }:data.direction',
                uploaded: 'openimport_profile_form.openimport_profile_form.where.upload:sample',
                tested: 'openimport_profile_form.openimport_profile_form.where.source_test:sample',
                format: '${ $.provider }:data.format',
                options: '${ $.provider }:data.format_options'
            },
            listens: {
                direction: 'toggle',
                uploaded: 'apply',
                tested: 'apply',
                format: 'reload',
                options: 'reload'
            },
            tracks: {
                columns: true,
                rows: true,
                detected: true,
                sheets: true,
                error: true,
                loading: true,
                visible: true
            }
        },

        /** @inheritdoc */
        initialize: function () {
            this._super();
            this.toggle();
            this.reload = _.debounce(this.load.bind(this), 400);
            this.load();

            return this;
        },

        /**
         * Exports write a file: there is nothing to show.
         */
        toggle: function () {
            this.visible = (this.direction || 'import') === 'import';
        },

        /**
         * @param {Object} sample
         */
        apply: function (sample) {
            if (!sample) {
                return;
            }
            this.error = sample.error || '';
            this.columns = sample.columns || [];
            this.rows = sample.rows || [];
            this.detected = sample.detected || {};
            if (sample.inner) {
                this.detected = _.extend({'file in the archive': sample.inner}, this.detected);
            }
            this.sheets = sample.sheets || [];
        },

        /**
         * Reads the sample of the stored upload with the current options.
         */
        load: function () {
            var file = this.source && this.source.get('data.source_config.file'),
                self = this;

            if (!file) {
                return;
            }
            this.loading = true;
            $.ajax({
                url: this.sampleUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    'form_key': window.FORM_KEY,
                    file: file,
                    'file_name': this.source.get('data.source_config.file_name') || '',
                    format: this.source.get('data.format') || 'auto',
                    'format_options': this.source.get('data.format_options') || {}
                }
            }).done(function (response) {
                self.apply(response);
            }).fail(function () {
                self.error = $t('The file could not be read.');
            }).always(function () {
                self.loading = false;
            });
        },

        /**
         * @returns {String}
         */
        detectedText: function () {
            var parts = [];

            _.each(this.detected || {}, function (value, key) {
                parts.push(key.replace('_', ' ') + ': ' + value);
            });

            return parts.join(' · ');
        },

        /**
         * @param {Object} row
         * @param {String} column
         * @returns {String}
         */
        cell: function (row, column) {
            var value = row[column];

            return value === undefined || value === null ? '' : String(value);
        }
    });
});
