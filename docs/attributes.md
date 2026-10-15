# Product attributes and attribute sets

Since 1.1. Entity **Product attributes and attribute sets** (`openimport_attribute`), identifier `attribute_code`.

## Import

Add/Update creates attributes that do not exist and updates the given columns of existing ones; it also assigns the
attribute to attribute sets. Delete takes the attribute **out of the listed attribute sets** only: OpenImport never
deletes an attribute (its values on every product would go with it). Replace is refused.

| Column | Meaning |
|---|---|
| `attribute_code` | identifier; lower-case letters, digits, underscores |
| `label` | default label; required for a new attribute |
| `frontend_input` | `text`, `textarea`, `texteditor`, `select`, `multiselect`, `boolean`, `date`, `datetime`, `price`, `weight`; required for a new attribute, cannot change later |
| `options` | `Cotton|Wool|Silk` for select and multiselect; options the attribute lacks are added, existing ones keep their ids and order |
| `scope` | `global`, `website` or `store` |
| `is_required`, `is_unique`, `is_searchable`, `is_filterable_in_search`, `is_comparable`, `is_visible_on_front`, `used_in_product_listing`, `is_html_allowed_on_front`, `is_used_for_promo_rules`, `used_for_sort_by` | `1`/`0` (also `yes`/`no`, `true`/`false`, `ja`/`nein`) |
| `is_filterable` | `0`, `1` (filterable with results) or `2` (filterable, no results) |
| `default_value` | text |
| `attribute_set` | `Default, Bags`: the sets to assign the attribute to (they must exist) |
| `attribute_group` | the group inside those sets; created when missing; default `General` |

Store-view labels work like everywhere in OpenImport: map a file column to `store:<store view code>:label` for the
attribute's label and to `store:<store view code>:options` for the option labels, in the order of the default
options (`Baumwolle|Wolle|Seide`; leave an entry empty to keep the default).

The dry run shows the attributes that would be created and every column that would change. Writes go through
Magento's attribute repository and attribute management, so the same validation as the admin applies.

## Export

One row per attribute of the shop's own (user-defined) attributes with options, flags and the sets it belongs to,
plus one row per store view with its own label or option labels. Export option `attributes = all` adds Magento's
system attributes. The file imports back as it is.
