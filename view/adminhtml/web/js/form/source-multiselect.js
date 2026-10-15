/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 *
 * A multiselect shown only where it matters (see conditional.js).
 */
define([
    'Magento_Ui/js/form/element/multiselect',
    'Iranimij_OpenImport/js/form/conditional'
], function (Multiselect, conditional) {
    'use strict';

    return conditional(Multiselect);
});
