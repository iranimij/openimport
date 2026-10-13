/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 *
 * Polls a queued or running run every 3 seconds while the tab is visible, and reloads the page when it finishes.
 */
define(['jquery'], function ($) {
    'use strict';

    return function (config, element) {
        var timer = null,
            stopped = false;

        /**
         * One status request.
         */
        function poll() {
            if (stopped || document.hidden) {
                return;
            }
            $.getJSON(config.url).done(function (response) {
                if (response.status !== config.status) {
                    stopped = true;
                    window.location.reload();

                    return;
                }
                $(element).find('[data-role="progress"]').text(response.progress);
            });
        }

        timer = window.setInterval(poll, 3000);
        document.addEventListener('visibilitychange', poll);
        $(window).on('beforeunload', function () {
            stopped = true;
            window.clearInterval(timer);
        });
    };
});
