# URL keys and redirects

Since 1.3. Two settings in step 5 of a product profile.

## URL keys from a pattern

Magento makes a product's URL key from its name. Supplier files often have many products with the same name, and
Magento then refuses the second one. **URL keys from a pattern** builds the key from any mapped fields:

| Pattern | Name, SKU | URL key |
|---|---|---|
| `{name}-{sku}` | Blaue Jacke, J-100 | `blaue-jacke-j-100` |
| `{manufacturer}-{name}` | Acme, Hammer | `acme-hammer` |

- Fields are Magento fields in braces; they must be mapped in step 4. Text is transliterated (*ä* → *ae*), lower
  case, words joined by hyphens, as Magento does it.
- Only rows whose URL key cell is empty get one, so a mapped `url_key` column always wins.
- A store view gets its own key when it has its own values for the pattern (a German name), otherwise it inherits.
- A key that another product has already, or that the pattern makes twice in one file, is a row error naming the
  other SKU: *The URL key "hammer" belongs to H-2 already.*

## Redirect old URLs (301)

When a product's URL key changes, its old address answers with a **permanent redirect** to the new one, so links,
bookmarks and search results keep working. OpenImport asks Magento's import to keep the URL history (the import
column `save_rewrites_history`), the same as the admin's *Create Permanent Redirect for old URL*.
