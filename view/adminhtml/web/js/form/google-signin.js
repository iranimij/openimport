/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 *
 * "Sign in with Google" on a saved Google connection that logs in as a Google account: shows the account and the
 * button (a link to Google's consent page, through openimport/connection/oauthStart).
 */
define([
    'Magento_Ui/js/form/element/abstract',
    'Iranimij_OpenImport/js/form/conditional'
], function (Abstract, conditional) {
    'use strict';

    return conditional(Abstract).extend({
        defaults: {
            template: 'Iranimij_OpenImport/form/google-signin',
            signInUrl: '',
            account: '',
            imports: {
                signInUrl: '${ $.provider }:data.google_signin_url',
                account: '${ $.provider }:data.settings.account'
            },
            tracks: {
                signInUrl: true,
                account: true
            }
        }
    });
});
