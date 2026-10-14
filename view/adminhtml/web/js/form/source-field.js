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
            forDirection: 'import',
            authField: 'auth',
            authScope: 'source_config',
            typeField: 'source_type',
            sourceType: '',
            direction: 'import',
            auth: '',
            secret: false,
            storedNote: '',
            imports: {
                sourceType: '${ $.provider }:data.${ $.typeField }',
                direction: '${ $.provider }:data.direction',
                auth: '${ $.provider }:data.${ $.authScope }.${ $.authField }'
            },
            listens: {
                sourceType: 'refresh',
                direction: 'refresh',
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
         * Shows the field for the chosen direction and source type (and authentication, when "forAuth" is set).
         */
        refresh: function () {
            var typeOk = this.forTypes.length === 0 || this.forTypes.indexOf(this.sourceType) !== -1,
                authOk = this.forAuth.length === 0 || this.forAuth.indexOf(this.auth || '') !== -1,
                directionOk = !this.forDirection || this.forDirection === (this.direction || 'import');

            this.visible(typeOk && authOk && directionOk);
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
