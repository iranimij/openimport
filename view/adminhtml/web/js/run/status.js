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
                // The run started writing to Magento: the page shows that (and no Cancel) after a reload.
                if (response.status !== config.status || Boolean(response.writing) !== Boolean(config.writing)) {
                    stopped = true;
                    window.location.reload();

                    return;
                }
                // A queued run has no rows yet: its counter stays empty rather than showing "0".
                if (response.status === 'running') {
                    $(element).find('[data-role="progress"]').text(response.progress);
                }
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
