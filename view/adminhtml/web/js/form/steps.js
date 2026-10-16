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
            docsUrl: 'https://iranimij.github.io/openimport/',
            // Docs page per step (exports read about destinations on the exports page).
            docs: {
                what: 'admin/',
                where: 'sources/',
                whereExport: 'exports/',
                file: 'file-formats/',
                map: 'mapping/',
                rules: 'runs/',
                when: 'schedules/'
            },
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
                    : this.value('schedule_preset') === 'daily'
                        ? $t('Every day at %1').replace('%1', this.value('schedule_time') || '02:00')
                        : this.chosen('schedule_preset'),
                parts;

            parts = {
                what: [this.chosen('direction'), this.chosen('entity')],
                where: exporting
                    ? [this.chosen('export_destination'),
                        this.value('export_options.connection_id') ? this.chosen('dest_connection') : '']
                    : [this.chosen('source_type'),
                        this.value('source_config.connection_id') ? this.chosen('source_connection') : '',
                        this.value('source_type') === 'upload'
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
                        header.appendChild(this.helpLink(name));
                    }
                    note.textContent = summaries[name] || '';
                    header.querySelector('.openimport-step-help').href = this.docsFor(name);
                }

                return {
                    name: name,
                    title: fieldset ? String(fieldset.label) : name,
                    summary: summaries[name] || ''
                };
            }, this);
        },

        /**
         * @param {String} name step
         * @returns {String} the docs page for the step
         */
        docsFor: function (name) {
            var exporting = this.value('direction') === 'export',
                page = name === 'where' && exporting ? this.docs.whereExport : this.docs[name];

            return this.docsUrl + (page || '');
        },

        /**
         * A "?" link to the docs in a step header; it opens a new tab and leaves the step as it is.
         *
         * @param {String} name step
         * @returns {HTMLElement}
         */
        helpLink: function (name) {
            var link = document.createElement('a');

            link.className = 'openimport-step-help';
            link.href = this.docsFor(name);
            link.target = '_blank';
            link.rel = 'noopener';
            link.textContent = '?';
            link.title = $t('Help for this step (opens the documentation)');
            link.setAttribute('aria-label', link.title);
            link.addEventListener('click', function (event) {
                event.stopPropagation();
            });

            return link;
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
