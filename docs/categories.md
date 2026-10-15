# Categories

Since 1.1. Import and export categories by **path**: the names from the root category down, joined with `/`
(`Default Category/Men/Shoes`). A slash inside a name is written `\/`. Paths are matched ignoring case; the first
segment must be a root category of the shop.

## Import

Entity **Categories** (`openimport_category`), behaviours Add/Update and Delete (Replace is refused: Magento would
delete and recreate the categories with new ids).

| Column | Meaning |
|---|---|
| `path` | identifier; parents that do not exist yet are created on the way (active, in the menu, named after the segment) |
| `name` | defaults to the last segment of the path |
| `url_key`, `description`, `meta_title`, `meta_keywords`, `meta_description`, `custom_design`, `page_layout` | texts |
| `is_active`, `include_in_menu`, `is_anchor` | `1`/`0`, also `yes`/`no`, `true`/`false`, `ja`/`nein` |
| `position` | whole number |
| `display_mode` | `PRODUCTS`, `PAGE` or `PRODUCTS_AND_PAGE` |

Only the columns a row has are changed. Store-view values work like everywhere in OpenImport: map a file column to
`store:<store view code>:<field>` (`name_de` → *Store view* `de`, field `name`) and the row sets that store view's
value. The dry run shows the categories that would be created and every field that would change; delete runs
count the children too and wait for the typed confirmation above the safety threshold.

Writes go through Magento's category model and repository (URL rewrites, indexers and events happen as in the admin),
never through raw SQL. Products are assigned to categories through the product import (`categories` column), not
here.

## Export

Entity **Categories**, one row per category with its default values, plus one row per store view that overrides
any text (`store_view_code` set, only the overridden columns filled). Export option `category_rows = default` writes
default rows only. The file imports back as it is.
