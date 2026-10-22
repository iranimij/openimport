/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 *
 * A text field shown only where it matters (see conditional.js) and, for a secret, saying whether one is stored
 * already (data "<name>_stored" next to the field; the value itself never reaches the form).
 *
 * typeGroups {type: group}: the field keeps one value per group, so a local folder does not turn up as the SFTP
 * folder after switching the source type (and comes back when switching back).
 * noticeByType {type: text}: help text for the chosen type (the field's own notice otherwise).
 * labelByType {type: text}: label for the chosen type (the field's own label otherwise).
 * placeholderByType {type: text}: placeholder for the chosen type (the field's own placeholder otherwise).
 */
define([
    'Magento_Ui/js/form/element/abstract',
    'Iranimij_OpenImport/js/form/conditional',
    'mage/translate'
], function (Abstract, conditional, $t) {
    'use strict';

    return conditional(Abstract).extend({
        defaults: {
            secret: false,
            storedNote: '',
            typeGroups: null,
            noticeByType: null,
            labelByType: null,
            placeholderByType: null,
            tracks: {
                storedNote: true,
                label: true,
                placeholder: true
            }
        },

        /** @inheritdoc */
        initialize: function () {
            this._super();
            if (this.secret) {
                this.storedNote = this.source.get(this.dataScope + '_stored') === '1'
                    ? $t('A value is stored. Leave the field empty to keep it.')
                    : '';
            }
            this.defaultNotice = this.notice ? this.notice() : '';
            this.defaultLabel = this.label;
            this.defaultPlaceholder = this.placeholder;
            this.lastGroup = this.group(this.sourceType);
            this.groupValues = {};
            this.refresh();

            return this;
        },

        /**
         * @param {String} type
         * @returns {String}
         */
        group: function (type) {
            return this.typeGroups ? String(this.typeGroups[type || ''] || type || '') : '';
        },

        /** @inheritdoc */
        refresh: function () {
            var group;

            this._super();
            if (this.noticeByType && typeof this.notice === 'function' && this.defaultNotice !== undefined) {
                this.notice(this.noticeByType[this.sourceType] || this.defaultNotice);
            }
            if (this.labelByType && this.defaultLabel !== undefined) {
                this.label = this.labelByType[this.sourceType] || this.defaultLabel;
            }
            if (this.placeholderByType && this.defaultPlaceholder !== undefined) {
                this.placeholder = this.placeholderByType[this.sourceType] || this.defaultPlaceholder;
            }
            if (!this.typeGroups || this.lastGroup === undefined) {
                return;
            }
            group = this.group(this.sourceType);
            if (group !== this.lastGroup) {
                this.groupValues[this.lastGroup] = this.value();
                this.lastGroup = group;
                this.value(this.groupValues[group] !== undefined ? this.groupValues[group] : '');
            }
        }
    });
});
