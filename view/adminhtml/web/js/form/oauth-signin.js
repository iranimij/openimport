/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 *
 * "Sign in with Google / Dropbox / Microsoft" on a saved connection that signs in: shows the account and the button
 * (a link to the service's consent page, through openimport/connection/oauthStart).
 */
define([
    'Magento_Ui/js/form/element/abstract',
    'Iranimij_OpenImport/js/form/conditional'
], function (Abstract, conditional) {
    'use strict';

    return conditional(Abstract).extend({
        defaults: {
            template: 'Iranimij_OpenImport/form/oauth-signin',
            signInUrl: '',
            account: '',
            services: {
                'google_drive': 'Google',
                dropbox: 'Dropbox',
                onedrive: 'Microsoft'
            },
            imports: {
                signInUrl: '${ $.provider }:data.signin_url',
                account: '${ $.provider }:data.settings.account'
            },
            tracks: {
                signInUrl: true,
                account: true
            }
        },

        /**
         * @returns {String} the service of the chosen connection type
         */
        service: function () {
            return this.services[this.sourceType] || '';
        }
    });
});
