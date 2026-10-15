/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 *
 * The last runs of the profile, newest first, with a link to all runs.
 */
define([
    'uiElement'
], function (Element) {
    'use strict';

    return Element.extend({
        defaults: {
            template: 'Iranimij_OpenImport/form/recent-runs',
            runsUrl: '',
            runs: [],
            imports: {
                runs: '${ $.provider }:data.recent_runs'
            },
            tracks: {
                runs: true
            }
        }
    });
});
