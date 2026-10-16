/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 *
 * "Test connection" on the connection form: posts what the form holds now; nothing is saved.
 */
define([
    'Iranimij_OpenImport/js/form/test-source'
], function (TestSource) {
    'use strict';

    return TestSource.extend({
        /** @inheritdoc */
        onSourceType: function () {
            this.visible(true);
            this.message = '';
            this.ok = null;
        }
    });
});
