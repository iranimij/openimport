/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 *
 * A text field shown only where it matters (see conditional.js) and, for a secret, saying whether one is stored
 * already (data "<name>_stored" next to the field; the value itself never reaches the form).
 */
define([
    'Magento_Ui/js/form/element/abstract',
    'Iranimij_OpenImport/js/form/conditional',
    'mage/translate'
], function (Abstract, conditional, $t) {
    'use strict';

    return conditional(Abstract).extend({
        defaults: {
            secret: false,
            storedNote: '',
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

            return this;
        }
    });
});
