/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 *
 * Step navigation above the profile form (sticky while scrolling) and a one-line summary of each step, shown in the
 * navigation and in the step's header, so collapsed steps still say what they hold ("Add/update · stop on errors").
 */
define([
    'jquery',
    'underscore',
    'uiElement',
    'uiRegistry',
    'mage/translate'
], function ($, _, Element, registry, $t) {
    'use strict';

    return Element.extend({
        defaults: {
            template: 'Iranimij_OpenImport/form/steps',
            stepNames: ['what', 'where', 'file', 'map', 'rules', 'when'],
            steps: [],
            data: {},
            imports: {
                data: '${ $.provider }:data'
            },
            listens: {
                data: 'refreshLater'
            },
            tracks: {
                steps: true
            }
        },

        /** @inheritdoc */
        initialize: function () {
            this.refreshLater = _.debounce(this.refresh.bind(this), 150);
            this._super();
            this.steps = _.map(this.stepNames, function (name) {
                return {name: name, title: '', summary: ''};
            });
            // Values change in nested paths that the "data" link does not see: refresh on any change in the form.
            $(document).on('change input', '[data-form-part="' + this.ns + '"], .admin__fieldset', this.refreshLater);
            _.delay(this.refresh.bind(this), 1500);
            // Below the page actions, which stick to the top of the window once the page scrolls.
            $(window).on('scroll resize', _.throttle(this.place.bind(this), 100));

            return this;
        },

        /**
         * Keeps the navigation under the fixed page actions.
         */
        place: function () {
            var nav = document.querySelector('.openimport-steps'),
                actions = document.querySelector('.page-actions._fixed');

            if (nav) {
                nav.style.top = actions ? actions.getBoundingClientRect().bottom + 'px' : '0';
            }
        },

        /**
         * @param {String} index field index in the form
         * @returns {Object|undefined}
         */
        field: function (index) {
            return registry.get('index = ' + index + ', ns = ' + this.ns);
        },

        /**
         * The label of a select's current option.
         *
         * @param {String} index
         * @returns {String}
         */
        chosen: function (index) {
            var field = this.field(index),
                value,
                option;

            if (!field || typeof field.value !== 'function') {
                return '';
            }
            value = field.value();
            option = _.find(typeof field.options === 'function' ? field.options() : [], function (item) {
                return String(item.value) === String(value);
            });

            return option ? String(option.label) : String(value || '');
        },

        /**
         * @param {String} path
         * @returns {*}
         */
        value: function (path) {
            var provider = registry.get(this.provider);

            return provider ? provider.get('data.' + path) : undefined;
        },

        /**
         * @returns {Object} step name => summary
         */
        summaries: function () {
            var exporting = this.value('direction') === 'export',
                mapping = _.filter(_.values(this.value('mapping') || {}), function (row) {
                    return row && (row.target || row.field);
                }).length,
                filters = _.filter(_.values(this.value('row_filters') || {}), function (row) {
                    return row && (row.column || row.value);
                }).length,
                schedule = this.value('schedule_preset') === 'custom'
                    ? String(this.value('schedule_custom') || '')
                    : this.chosen('schedule_preset'),
                parts;

            parts = {
                what: [this.chosen('direction'), this.chosen('entity')],
                where: exporting
                    ? [this.chosen('export_destination')]
                    : [this.chosen('source_type'), this.value('source_type') === 'upload'
                        ? this.value('source_config.file_name') : this.value('source_config.path')
                            || this.value('source_config.host') || this.value('source_config.url')],
                file: [this.chosen('format')],
                map: [mapping ? $t('%1 fields').replace('%1', mapping)
                    : exporting ? $t('every column') : $t('nothing mapped yet')],
                rules: exporting
                    ? [filters ? $t('%1 filters').replace('%1', filters) : $t('no filters')]
                    : [this.chosen('behavior'), this.chosen('on_error'),
                        filters ? $t('%1 filters').replace('%1', filters) : ''],
                when: [schedule || $t('Manual only'), schedule && this.value('schedule_preset')
                    ? (String(this.value('is_active')) === '1' ? $t('switched on') : $t('switched off')) : '']
            };

            return _.mapObject(parts, function (list) {
                return _.filter(list, function (part) {
                    return part !== undefined && part !== null && String(part) !== '';
                }).join(' · ');
            });
        },

        /**
         * Updates the navigation and the step headers.
         */
        refresh: function () {
            var summaries = this.summaries();

            this.steps = _.map(this.stepNames, function (name) {
                var fieldset = registry.get(this.parentName + '.' + name),
                    header = document.querySelector(
                        '.fieldset-wrapper[data-index="' + name + '"] .admin__collapsible-title'
                    ),
                    note;

                if (header) {
                    note = header.querySelector('.openimport-step-summary');
                    if (!note) {
                        note = document.createElement('span');
                        note.className = 'openimport-step-summary';
                        header.appendChild(note);
                    }
                    note.textContent = summaries[name] || '';
                }

                return {
                    name: name,
                    title: fieldset ? String(fieldset.label) : name,
                    summary: summaries[name] || ''
                };
            }, this);
        },

        /**
         * Opens a step and scrolls to it.
         *
         * @param {Object} step
         */
        go: function (step) {
            var fieldset = registry.get(this.parentName + '.' + step.name),
                element = document.querySelector('.fieldset-wrapper[data-index="' + step.name + '"]');

            if (fieldset && typeof fieldset.opened === 'function') {
                fieldset.opened(true);
            }
            if (element) {
                element.scrollIntoView({behavior: 'smooth', block: 'start'});
            }
        }
    });
});
