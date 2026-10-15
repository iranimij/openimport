/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 *
 * File upload of an upload profile: sends the file to the server, keeps its token in source_config.file and shares
 * the server's sample (columns, first rows) with the sample table and the mapping editor.
 */
define([
    'jquery',
    'Magento_Ui/js/form/element/abstract',
    'mage/translate'
], function ($, Abstract, $t) {
    'use strict';

    return Abstract.extend({
        defaults: {
            template: 'Iranimij_OpenImport/form/upload',
            uploadUrl: '',
            sourceType: 'upload',
            fileName: '',
            uploading: false,
            message: '',
            detected: '',
            dragging: false,
            sample: null,
            direction: 'import',
            imports: {
                direction: '${ $.provider }:data.direction'
            },
            listens: {
                sourceType: 'onSourceType',
                direction: 'onSourceType'
            },
            tracks: {
                fileName: true,
                uploading: true,
                message: true,
                detected: true,
                dragging: true,
                sample: true
            }
        },

        /** @inheritdoc */
        initialize: function () {
            var config;

            this._super();
            config = this.source.get(this.dataScope) || {};
            this.fileName = config.file_name || '';
            this.onSourceType();

            return this;
        },

        /**
         * Only upload profiles (imports) show the upload field.
         */
        onSourceType: function () {
            var type = this.sourceType;

            this.visible((type === 'upload' || !type) && (this.direction || 'import') !== 'export');
        },

        /**
         * Sends the chosen file.
         *
         * @param {Object} component
         * @param {Event} event
         */
        upload: function (component, event) {
            this.send(event.target.files && event.target.files[0], event.target);
        },

        /**
         * A file dropped on the drop zone.
         *
         * @param {Object} component
         * @param {Event} event
         */
        drop: function (component, event) {
            var files = event.dataTransfer && event.dataTransfer.files;

            this.dragging = false;
            if (!this.uploading) {
                this.send(files && files[0], null);
            }
        },

        /**
         * Keeps the browser from opening the dragged file.
         */
        dragOver: function () {
            this.dragging = true;
        },

        /**
         * The dragged file left the drop zone.
         */
        dragLeave: function () {
            this.dragging = false;
        },

        /**
         * @param {File|undefined} file
         * @param {HTMLInputElement|null} input cleared after the upload, so the same file can be chosen again
         */
        send: function (file, input) {
            var data = new FormData(),
                self = this;

            if (!file) {
                return;
            }
            data.append('file', file);
            data.append('form_key', window.FORM_KEY);
            data.append('format', this.source.get('data.format') || 'auto');
            this.uploading = true;
            this.message = '';
            this.detected = '';
            $.ajax({
                url: this.uploadUrl,
                type: 'POST',
                data: data,
                processData: false,
                contentType: false,
                dataType: 'json'
            }).done(function (response) {
                if (response.error) {
                    self.message = response.error;

                    return;
                }
                self.source.set(self.dataScope + '.file', response.file.token);
                self.source.set(self.dataScope + '.file_name', response.file.name);
                self.fileName = response.file.name;
                if (response.format && self.source.get('data.format') === 'auto') {
                    self.detected = $t('Detected: %1').replace('%1', response.format.toUpperCase());
                }
                self.sample = response;
            }).fail(function () {
                self.message = $t('The file could not be uploaded. Try again.');
            }).always(function () {
                self.uploading = false;
                if (input) {
                    input.value = '';
                }
            });
        }
    });
});
