/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 *
 * The mapping editor: one row per Magento field with its source column (or a fixed value), a store view, transforms
 * as chips (add, move up/down, remove; no drag needed), required flag and value map. Auto-match, required fields
 * and a live preview of the first rows come from the server.
 */
define([
    'jquery',
    'underscore',
    'ko',
    'Magento_Ui/js/form/element/abstract',
    'mage/translate'
], function ($, _, ko, Abstract, $t) {
    'use strict';

    var CATALOGUE = [
        {name: 'trim', label: $t('Trim spaces'), args: []},
        {name: 'lower', label: $t('lower case'), args: []},
        {name: 'upper', label: $t('UPPER CASE'), args: []},
        {name: 'title', label: $t('Title Case'), args: []},
        {name: 'replace', label: $t('Find and replace'), args: [$t('Find'), $t('Replace with')]},
        {name: 'regex', label: $t('Pattern replace'), args: [$t('Pattern'), $t('Replace with')]},
        {name: 'strip_html', label: $t('Remove HTML'), args: []},
        {name: 'prefix', label: $t('Add in front'), args: [$t('Text')]},
        {name: 'suffix', label: $t('Add at the end'), args: [$t('Text')]},
        {name: 'default', label: $t('Value when empty'), args: [$t('Value')]},
        {name: 'decimal_comma', label: $t('Decimal comma → dot'), args: []},
        {name: 'number', label: $t('Number'), args: [$t('Decimals (optional)'), $t('Decimal separator (. or ,)')]},
        {name: 'math', label: $t('Calculate'), args: [$t('Operator + - * /'), $t('Number')]},
        {name: 'round', label: $t('Round'), args: [$t('Decimals'), $t('Mode: half_up, up, down, ending'), $t('Ending, e.g. .99')]},
        {name: 'price_adjust', label: $t('Change price by %'), args: [$t('Percent, e.g. 15 or -10'), $t('Decimals')]},
        {name: 'date', label: $t('Date format'), args: [$t('From format (auto)'), $t('To format (Y-m-d)')]},
        {name: 'split', label: $t('Part of the value'), args: [$t('Separator'), $t('Position (1 = first, -1 = last)')]},
        {name: 'join', label: $t('Join columns'), args: [$t('Separator'), $t('Column'), $t('Column'), $t('Column')]},
        {name: 'map', label: $t('Value map'), args: []},
        {name: 'expr', label: $t('Expression'), args: [$t('Expression, e.g. {Price} * 1.19')]}
    ];

    /**
     * @param {String} target
     * @returns {{field: String, store: String}}
     */
    function splitTarget(target) {
        var parts;

        if (target && target.indexOf('store:') === 0) {
            parts = target.split(':');

            return {store: parts[1] || '', field: parts.slice(2).join(':')};
        }

        return {store: '', field: target || ''};
    }

    return Abstract.extend({
        defaults: {
            template: 'Iranimij_OpenImport/form/mapping-editor',
            fieldsUrl: '',
            previewUrl: '',
            entity: '',
            columns: [],
            catalogue: CATALOGUE,
            fields: [],
            stores: [],
            matches: {},
            loadingFields: false,
            preview: null,
            previewing: false,
            notice: '',
            imports: {
                entity: '${ $.provider }:data.entity',
                columns: 'openimport_profile_form.openimport_profile_form.file.sample:columns'
            },
            listens: {
                entity: 'loadFields',
                columns: 'loadFields'
            },
            tracks: {
                fields: true,
                stores: true,
                matches: true,
                loadingFields: true,
                preview: true,
                previewing: true,
                notice: true,
                columns: true
            }
        },

        /** @inheritdoc */
        initialize: function () {
            // Listeners can fire while the links are set up: the debounced loader must exist before _super().
            this.fetchLater = _.debounce(this.fetchFields.bind(this), 300);
            this._super();
            this.rows = ko.observableArray([]);
            _.each(this.initialValue || this.value() || [], function (row) {
                this.rows.push(this.makeRow(row));
            }, this);
            this.fetchFields();

            return this;
        },

        /**
         * @param {Object} data stored row
         * @returns {Object}
         */
        makeRow: function (data) {
            var target = splitTarget(data.target || ''),
                transforms = data.transforms || [],
                row;

            if (typeof transforms === 'string') {
                try {
                    transforms = JSON.parse(transforms || '[]');
                } catch (e) {
                    transforms = [];
                }
            }
            row = {
                field: ko.observable(target.field),
                store: ko.observable(target.store),
                source: ko.observable(data.source || ''),
                staticValue: ko.observable(data['static'] || ''),
                transforms: ko.observableArray(transforms),
                required: ko.observable(data.required === true || data.required === '1'),
                valueMap: ko.observable(data.value_map || ''),
                caseSensitive: ko.observable(data.value_map_case_sensitive === '1' || data.value_map_case_sensitive === true),
                strict: ko.observable(data.value_map_strict === '1' || data.value_map_strict === true),
                showMap: ko.observable(!!data.value_map),
                addName: ko.observable(''),
                addArgs: ko.observableArray([])
            };
            row.addName.subscribe(function (name) {
                var entry = _.findWhere(CATALOGUE, {name: name});

                row.addArgs(_.map(entry ? entry.args : [], function (label) {
                    return {label: label, value: ko.observable('')};
                }));
            });
            _.each(['field', 'store', 'source', 'staticValue', 'required', 'valueMap', 'caseSensitive', 'strict'],
                function (key) {
                    row[key].subscribe(this.sync, this);
                }, this);
            row.transforms.subscribe(this.sync, this);

            return row;
        },

        /**
         * Writes the rows into the form data (posted as mapping[n][...]).
         */
        sync: function () {
            this.value(_.map(this.rows(), function (row, index) {
                return {
                    'record_id': String(index),
                    target: row.store() ? 'store:' + row.store() + ':' + row.field() : row.field(),
                    source: row.source(),
                    'static': row.source() ? '' : row.staticValue(),
                    transforms: JSON.stringify(row.transforms()),
                    required: row.required() ? '1' : '0',
                    'value_map': row.valueMap(),
                    'value_map_case_sensitive': row.caseSensitive() ? '1' : '0',
                    'value_map_strict': row.strict() ? '1' : '0'
                };
            }));
        },

        /**
         * Entity or columns changed: the old proposal is stale right away (Auto-match waits), the request follows
         * once the changes settle.
         */
        loadFields: function () {
            this.matches = {};
            this.loadingFields = true;
            this.fetchLater();
        },

        /**
         * Loads the entity's fields and an auto-match proposal for the file's columns.
         */
        fetchFields: function () {
            var self = this;

            if (!this.entity) {
                this.fields = [];
                this.loadingFields = false;

                return;
            }
            this.loadingFields = true;
            $.ajax({
                url: this.fieldsUrl,
                type: 'POST',
                dataType: 'json',
                data: {'form_key': window.FORM_KEY, entity: this.entity, columns: this.columns || []}
            }).done(function (response) {
                self.fields = response.fields || [];
                self.stores = response.stores || [];
                self.matches = response.matches || {};
            }).always(function () {
                self.loadingFields = false;
            });
        },

        /**
         * @returns {Array}
         */
        fieldOptions: function () {
            return _.map(this.fields, function (field) {
                return {
                    value: field.code,
                    label: field.code + (field.label !== field.code ? ' – ' + field.label : '') +
                        (field.identifier ? ' (' + $t('identifier') + ')' : field.required ? ' *' : '')
                };
            });
        },

        /**
         * @returns {Array}
         */
        sourceOptions: function () {
            return [{value: '', label: $t('(fixed value)')}].concat(_.map(this.columns || [], function (column) {
                return {value: column, label: column};
            }));
        },

        /**
         * @returns {Array}
         */
        storeOptions: function () {
            return [{value: '', label: $t('All store views')}].concat(_.map(this.stores, function (store) {
                return {value: store.code, label: store.name + ' (' + store.code + ')'};
            }));
        },

        addRow: function () {
            this.rows.push(this.makeRow({}));
            this.sync();
        },

        /**
         * @param {Object} row
         */
        removeRow: function (row) {
            this.rows.remove(row);
            this.sync();
        },

        /**
         * Adds rows for the auto-match proposal (fields that are not mapped yet).
         */
        autoMatch: function () {
            var mapped = _.map(this.value() || [], function (row) {
                    return row.target;
                }),
                added = 0;

            _.each(this.matches, function (source, target) {
                if (mapped.indexOf(target) === -1) {
                    this.rows.push(this.makeRow({target: target, source: source}));
                    added++;
                }
            }, this);
            this.sync();
            this.notice = added
                ? $t('%1 field(s) matched. Check them, then add transforms where needed.').replace('%1', added)
                : $t('No further matches. Map the remaining fields by hand.');
        },

        /**
         * Adds rows for required fields that are not mapped yet.
         */
        addRequired: function () {
            var mapped = _.map(this.rows(), function (row) {
                return row.store() ? '' : row.field();
            });

            _.each(this.fields, function (field) {
                if (field.required && mapped.indexOf(field.code) === -1) {
                    this.rows.push(this.makeRow({target: field.code}));
                }
            }, this);
            this.sync();
        },

        /**
         * @param {Object} row
         */
        addTransform: function (row) {
            var name = row.addName(),
                args = _.map(row.addArgs(), function (arg) {
                    return arg.value();
                });

            if (!name) {
                return;
            }
            while (args.length && args[args.length - 1] === '') {
                args.pop();
            }
            row.transforms.push(args.length ? {name: name, args: args} : name);
            row.addName('');
            if (name === 'map') {
                row.showMap(true);
            }
        },

        /**
         * @param {Object} row
         * @param {Number} index
         * @param {Number} step -1 up, 1 down
         */
        moveTransform: function (row, index, step) {
            var list = row.transforms().slice(),
                target = index + step,
                item;

            if (target < 0 || target >= list.length) {
                return;
            }
            item = list[index];
            list[index] = list[target];
            list[target] = item;
            row.transforms(list);
        },

        /**
         * @param {Object} row
         * @param {Number} index
         */
        removeTransform: function (row, index) {
            var list = row.transforms().slice();

            list.splice(index, 1);
            row.transforms(list);
        },

        /**
         * @param {Object|String} transform
         * @returns {String}
         */
        transformLabel: function (transform) {
            var name = typeof transform === 'string' ? transform : transform.name,
                args = typeof transform === 'string' ? [] : transform.args || [];

            return name + (args.length ? '(' + _.map(args, function (arg) {
                return JSON.stringify(String(arg));
            }).join(', ') + ')' : '');
        },

        /**
         * Shows the first rows as Magento will receive them.
         */
        runPreview: function () {
            var self = this,
                data = $.extend(true, {}, this.source.get('data'));

            this.sync();
            data.mapping = this.value();
            data['form_key'] = window.FORM_KEY;
            this.previewing = true;
            $.ajax({url: this.previewUrl, type: 'POST', dataType: 'json', data: data})
                .done(function (response) {
                    self.preview = response;
                }).always(function () {
                    self.previewing = false;
                });
        },

        /**
         * @param {Object} values
         * @param {String} column
         * @returns {String}
         */
        previewCell: function (values, column) {
            return values && values[column] !== undefined ? String(values[column]) : '';
        }
    });
});
