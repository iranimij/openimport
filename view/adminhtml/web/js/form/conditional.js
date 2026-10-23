/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 *
 * Shows a form field only where it matters. Wraps a Magento form element (input, select, checkbox).
 *
 * Config:
 *  - forDirection: 'import' (default) | 'export' | 'any'
 *  - forTypes / typeField: the field shows for these values of typeField (source type, destination, format)
 *  - forAuth / authScope / authField: the field shows for these authentication types
 *  - showWhen: conditions on other form values, {path: spec} (all must hold) or [{...}, {...}] (one must hold).
 *    A spec is a list of values, {not: [...]}, {match: 'regular expression'} or {filled: '1'|'0'}.
 *  - requiredWhen: conditions (as showWhen) under which the field must be filled.
 *  - options of a select may carry their own showWhen; options whose conditions fail are taken out.
 *
 * Hidden fields are not validated.
 */
define([
    'underscore'
], function (_) {
    'use strict';

    /**
     * @param {*} value
     * @returns {Boolean}
     */
    var filled;

    filled = function (value) {
        if (_.isArray(value) || _.isObject(value)) {
            return _.some(value, filled);
        }

        return value !== undefined && value !== null && String(value) !== '';
    };

    /**
     * @param {*} spec
     * @param {*} value
     * @returns {Boolean}
     */
    var test;

    test = function (spec, value) {
        var text = value === undefined || value === null ? '' : String(value);

        if (_.isArray(spec)) {
            return _.some(spec, function (allowed) {
                return String(allowed) === text;
            });
        }
        if (_.isObject(spec)) {
            if (spec.not !== undefined) {
                return !test(spec.not, value);
            }
            if (spec.match !== undefined) {
                return new RegExp(spec.match, 'i').test(text);
            }
            if (spec.filled !== undefined) {
                return filled(value) === (spec.filled === true || String(spec.filled) === '1');
            }

            return true;
        }

        return String(spec) === text;
    };

    /**
     * @param {Object|Array|null} rules
     * @param {Function} get value of a form path
     * @returns {Boolean}
     */
    var holds;

    holds = function (rules, get) {
        if (!rules || _.isEmpty(rules)) {
            return true;
        }
        if (_.isArray(rules)) {
            return _.some(rules, function (rule) {
                return holds(rule, get);
            });
        }

        return _.every(rules, function (spec, path) {
            return test(spec, get(path));
        });
    };

    /**
     * @param {Object|Array|null} rules
     * @param {String[]} paths collected so far
     * @returns {String[]}
     */
    var paths;

    paths = function (rules, found) {
        if (_.isArray(rules)) {
            _.each(rules, function (rule) {
                paths(rule, found);
            });
        } else if (_.isObject(rules)) {
            _.each(_.keys(rules), function (path) {
                if (found.indexOf(path) === -1) {
                    found.push(path);
                }
            });
        }

        return found;
    };

    /**
     * @param {Function} Base a Magento form element
     * @returns {Function}
     */
    var conditional;

    conditional = function (Base) {
        return Base.extend({
            defaults: {
                forTypes: [],
                forAuth: [],
                forDirection: 'import',
                authField: 'auth',
                authScope: 'source_config',
                typeField: 'source_type',
                showWhen: null,
                requiredWhen: null,
                sourceType: '',
                direction: 'import',
                auth: '',
                imports: {
                    sourceType: '${ $.provider }:data.${ $.typeField }',
                    direction: '${ $.provider }:data.direction',
                    auth: '${ $.provider }:data.${ $.authScope }.${ $.authField }'
                },
                listens: {
                    sourceType: 'refresh',
                    direction: 'refresh',
                    auth: 'refresh'
                }
            },

            /** @inheritdoc */
            initConfig: function () {
                var imports, listens, found;

                this._super();
                // Magento's class extension merges defaults shallowly: a component with its own imports or listens
                // (oauth-signin) would otherwise lose the type, direction and auth links below.
                imports = _.extend({
                    sourceType: this.provider + ':data.' + this.typeField,
                    direction: this.provider + ':data.direction',
                    auth: this.provider + ':data.' + this.authScope + '.' + this.authField
                }, this.imports);
                listens = _.extend({
                    sourceType: 'refresh',
                    direction: 'refresh',
                    auth: 'refresh'
                }, this.listens);
                found = paths(this.requiredWhen, paths(this.showWhen, []));
                // Options from a source class arrive as a list, options written in the form XML as an object.
                _.each(_.isObject(this.options) ? _.values(this.options) : [], function (option) {
                    paths(option && option.showWhen, found);
                });
                this.whenPaths = found;
                _.each(found, function (path, index) {
                    imports['whenValue' + index] = this.provider + ':data.' + path;
                    listens['whenValue' + index] = 'refresh';
                }, this);
                this.imports = imports;
                this.listens = listens;

                return this;
            },

            /** @inheritdoc */
            initialize: function () {
                this._super();
                this.refresh();

                return this;
            },

            /**
             * @param {String} path
             * @returns {*}
             */
            formValue: function (path) {
                var index = (this.whenPaths || []).indexOf(path);

                return index === -1 ? undefined : this['whenValue' + index];
            },

            /**
             * @param {Object|Array|null} rules
             * @returns {Boolean}
             */
            conditionsHold: function (rules) {
                return holds(rules, this.formValue.bind(this));
            },

            /**
             * Shows the field when every condition holds.
             */
            refresh: function () {
                var typeOk = this.forTypes.length === 0 || this.forTypes.indexOf(this.sourceType) !== -1,
                    authOk = this.forAuth.length === 0 || this.forAuth.indexOf(this.auth || '') !== -1,
                    directionOk = !this.forDirection || this.forDirection === 'any'
                        || this.forDirection === (this.direction || 'import');

                if (typeof this.visible !== 'function') {
                    return;
                }
                this.visible(typeOk && authOk && directionOk && this.conditionsHold(this.showWhen));
                if (this.requiredWhen && typeof this.setValidation === 'function') {
                    this.setValidation('required-entry', this.conditionsHold(this.requiredWhen));
                }
            },

            /**
             * Hidden fields are not validated.
             *
             * @returns {Object}
             */
            validate: function () {
                if (!this.visible()) {
                    return {valid: true, target: this};
                }

                return this._super();
            }
        });
    };

    conditional.holds = holds;

    return conditional;
});
