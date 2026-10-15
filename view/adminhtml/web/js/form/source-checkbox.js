/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 *
 * A switch shown only where it matters (see conditional.js).
 */
define([
    'Magento_Ui/js/form/element/single-checkbox',
    'Iranimij_OpenImport/js/form/conditional'
], function (Checkbox, conditional) {
    'use strict';

    return conditional(Checkbox);
});
