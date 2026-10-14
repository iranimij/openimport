/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 *
 * A source setting that is only shown for some source types (config "forTypes") and, for a secret, says whether
 * one is stored already (data "<name>_stored" next to the field; the value itself never reaches the form).
 */
define([
    'Magento_Ui/js/form/element/abstract',
    'mage/translate'
], function (Abstract, $t) {
    'use strict';

    return Abstract.extend({
        defaults: {
            forTypes: [],
            forAuth: [],
            authField: 'auth',
            sourceType: '',
            auth: '',
            secret: false,
            storedNote: '',
            imports: {
                sourceType: '${ $.provider }:data.source_type',
                auth: '${ $.provider }:data.source_config.${ $.authField }'
            },
            listens: {
                sourceType: 'refresh',
                auth: 'refresh'
            },
            tracks: {
                storedNote: true
            }
        },

        /** @inheritdoc */
        initialize: function () {
            this._super();
            if (this.secret) {
                this.storedNote = this.source.get(this.dataScope + '_stored') === '1'
                    ? $t('A value is stored. Leave the field empty to keep it.')
                    : '';
            }
            this.refresh();

            return this;
        },

        /**
         * Shows the field for the chosen source type (and authentication, when "forAuth" is set).
         */
        refresh: function () {
            var typeOk = this.forTypes.length === 0 || this.forTypes.indexOf(this.sourceType) !== -1,
                authOk = this.forAuth.length === 0 || this.forAuth.indexOf(this.auth || '') !== -1;

            this.visible(typeOk && authOk);
        },

        /**
         * Hidden fields are not validated.
         *
         * @returns {Object}
         */
        validate: function () {
            if (!this.visible()) {
                return {valid: true, target: this};
            }

            return this._super();
        }
    });
});
