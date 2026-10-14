/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 *
 * "Test connection": posts the form's current source settings and shows what the server found.
 */
define([
    'jquery',
    'Magento_Ui/js/form/element/abstract',
    'mage/translate'
], function ($, Abstract, $t) {
    'use strict';

    return Abstract.extend({
        defaults: {
            template: 'Iranimij_OpenImport/form/test-source',
            testUrl: '',
            sourceType: '',
            busy: false,
            ok: null,
            message: '',
            imports: {
                sourceType: '${ $.provider }:data.source_type'
            },
            listens: {
                sourceType: 'onSourceType'
            },
            tracks: {
                busy: true,
                ok: true,
                message: true
            }
        },

        /** @inheritdoc */
        initialize: function () {
            this._super();
            this.onSourceType(this.sourceType);

            return this;
        },

        /**
         * Uploads are checked by uploading; every other source has a test.
         *
         * @param {String} type
         */
        onSourceType: function (type) {
            this.visible(type !== 'upload' && !!type);
            this.message = '';
            this.ok = null;
        },

        /**
         * Runs the test.
         */
        test: function () {
            var self = this,
                data = $.extend(true, {}, this.source.get('data'));

            data['form_key'] = window.FORM_KEY;
            this.busy = true;
            this.message = '';
            this.ok = null;
            $.ajax({
                url: this.testUrl,
                type: 'POST',
                data: data,
                dataType: 'json'
            }).done(function (response) {
                self.ok = !!response.ok;
                self.message = response.message || '';
            }).fail(function () {
                self.ok = false;
                self.message = $t('The test could not be run. Try again.');
            }).always(function () {
                self.busy = false;
            });
        }
    });
});
