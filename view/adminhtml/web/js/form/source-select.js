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
            authField: 'auth',
            sourceType: '',
            auth: '',
            imports: {
                sourceType: '${ $.provider }:data.source_type',
                auth: '${ $.provider }:data.source_config.${ $.authField }'
            },
            listens: {
                sourceType: 'refresh',
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
         * Shows the field for the chosen source type (and authentication, when "forAuth" is set).
         */
        refresh: function () {
            var typeOk = this.forTypes.length === 0 || this.forTypes.indexOf(this.sourceType) !== -1,
                authOk = this.forAuth.length === 0 || this.forAuth.indexOf(this.auth || '') !== -1;

            this.visible(typeOk && authOk);
        }
    });
});
