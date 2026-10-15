/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 *
 * A date picker shown only where it matters (see conditional.js).
 */
define([
    'Magento_Ui/js/form/element/date',
    'Iranimij_OpenImport/js/form/conditional'
], function (DateElement, conditional) {
    'use strict';

    return conditional(DateElement);
});
