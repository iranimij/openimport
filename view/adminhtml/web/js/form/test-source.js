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
            sample: null,
            direction: 'import',
            imports: {
                sourceType: '${ $.provider }:data.source_type',
                direction: '${ $.provider }:data.direction'
            },
            listens: {
                sourceType: 'onSourceType',
                direction: 'onSourceType'
            },
            tracks: {
                busy: true,
                ok: true,
                message: true,
                sample: true
            }
        },

        /** @inheritdoc */
        initialize: function () {
            this._super();
            this.onSourceType();

            return this;
        },

        /**
         * Uploads are checked by uploading; every other source has a test. Export destinations get theirs later.
         */
        onSourceType: function () {
            var type = this.sourceType;

            this.visible(!!type && type !== 'upload' && (this.direction || 'import') !== 'export');
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
                self.busy = false;
                self.ok = !!response.ok;
                self.message = response.message || '';
                if (response.sample) {
                    self.sample = response.sample;
                }
            }).fail(function () {
                self.busy = false;
                self.ok = false;
                self.message = $t('The test could not be run. Try again.');
            });
        }
    });
});
