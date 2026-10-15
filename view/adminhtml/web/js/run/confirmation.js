/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 *
 * Typed confirmation: the button works once the word is typed (upper or lower case).
 */
define([], function () {
    'use strict';

    return function (config, form) {
        var input = form.querySelector('input[name="confirmation"]'),
            button = form.querySelector('button[type="submit"]'),
            word = String(config.word || '').toLowerCase(),
            check = function () {
                button.disabled = input.value.trim().toLowerCase() !== word;
            };

        if (!input || !button) {
            return;
        }
        input.addEventListener('input', check);
        check();
    };
});
