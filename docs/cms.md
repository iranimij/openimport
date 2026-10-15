# CMS pages and blocks

Since 1.1. Entities **CMS pages** (`openimport_cms_page`) and **CMS blocks** (`openimport_cms_block`), identifier
`identifier`. Behaviours Add/Update and Delete; Replace is refused (it would change ids that widgets and links use).

| Column | Pages | Blocks | Meaning |
|---|---|---|---|
| `identifier` | ✓ | ✓ | URL key of a page, identifier of a block |
| `stores` | ✓ | ✓ | `all`, or store view codes separated by commas (`default,de`); empty keeps the record's store views (new records: all) |
| `title` | ✓ | ✓ | required for a new record |
| `content` | ✓ | ✓ | HTML, Page Builder markup works as it is |
| `is_active` | ✓ | ✓ | `1`/`0` |
| `content_heading`, `page_layout`, `meta_title`, `meta_keywords`, `meta_description`, `layout_update_xml`, `custom_theme`, `sort_order` | ✓ | | page settings |

An empty cell leaves the value as it is; `__EMPTY__VALUE__` clears it (as in Magento's import).

Magento allows the same identifier once per store view; OpenImport matches a row to the record with that identifier
that is assigned to one of the row's store views (or to all store views).

The dry run shows the records that would be created and every column that would change. Writes go through
Magento's page and block repositories. Exports write one row per record with the store views as codes; the file
imports back as it is (into the same shop or a staging/production twin).
