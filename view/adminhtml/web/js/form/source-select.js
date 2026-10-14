/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 *
 * A select among the source settings, shown for some source types only (see source-field.js).
 */
define([
    'Magento_Ui/js/form/element/select'
], function (Select) {
    'use strict';

    return Select.extend({
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
            imports: {
                sourceType: '${ $.provider }:data.${ $.typeField }',
                direction: '${ $.provider }:data.direction',
                auth: '${ $.provider }:data.${ $.authScope }.${ $.authField }'
            },
            listens: {
                sourceType: 'refresh',
                direction: 'refresh',
                auth: 'refresh'
            }
        },

        /** @inheritdoc */
        initialize: function () {
            this._super();
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
        }
    });
});
