/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 *
 * "Next runs" under the schedule: the next three times in the store time zone, or what is wrong with the cron.
 */
define([
    'jquery',
    'underscore',
    'uiElement'
], function ($, _, Element) {
    'use strict';

    return Element.extend({
        defaults: {
            template: 'Iranimij_OpenImport/form/schedule-preview',
            url: '',
            preset: '',
            custom: '',
            time: '',
            times: [],
            zone: '',
            error: '',
            imports: {
                preset: '${ $.provider }:data.schedule_preset',
                custom: '${ $.provider }:data.schedule_custom',
                time: '${ $.provider }:data.schedule_time'
            },
            listens: {
                preset: 'refresh',
                custom: 'refresh',
                time: 'refresh'
            },
            tracks: {
                times: true,
                zone: true,
                error: true,
                preset: true
            }
        },

        /** @inheritdoc */
        initialize: function () {
            this.refresh = _.debounce(this.load.bind(this), 400);
            this._super();
            this.load();

            return this;
        },

        /**
         * @returns {String} the cron expression the form will save
         */
        schedule: function () {
            var match;

            if (this.preset === 'custom') {
                return String(this.custom || '');
            }
            if (this.preset === 'daily') {
                match = /^(\d{1,2})[:.h]?(\d{2})$/.exec(String(this.time || '02:00').trim());

                return match ? parseInt(match[2], 10) + ' ' + parseInt(match[1], 10) + ' * * *' : '';
            }

            return String(this.preset || '');
        },

        /**
         * Asks the server for the next run times.
         */
        load: function () {
            var schedule = this.schedule(),
                self = this;

            if (!schedule || !this.url) {
                this.times = [];
                this.error = '';

                return;
            }
            $.ajax({
                url: this.url,
                type: 'POST',
                dataType: 'json',
                data: {'form_key': window.FORM_KEY, schedule: schedule}
            }).done(function (response) {
                self.times = response.times || [];
                self.zone = response.zone || '';
                self.error = response.error || '';
            });
        }
    });
});
