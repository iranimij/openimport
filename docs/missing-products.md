# Products missing from the file

Since 1.3. When a supplier sends the **complete list** of what it sells, products it no longer lists should not stay
for sale. Step 5 of a product profile, **Products missing from the file**:

| Choice | What happens to a product in the scope that the file does not name |
|---|---|
| Leave them (default) | nothing |
| Disable them | status *Disabled* (only enabled products are counted) |
| Set them out of stock | quantity 0, *Out of stock* (only products in stock are counted) |
| Delete them | deleted |

**Scope** decides which products the list covers:

- **All products**;
- **Products of a website** (choose the website);
- **Products with an attribute value**, for example `supplier` = *Acme*: the right choice when several suppliers
  fill one catalog. For dropdowns enter the option label.

## How it works

1. The run reads the whole file. Every SKU in it counts as named: also rows the row filters leave out, rows with
   errors, rows *Skip unchanged rows* leaves out and configurable parents OpenImport builds.
2. After the last row OpenImport asks the shop for the products in the scope and keeps those the file does not name.
3. Magento imports the file, then the change for the missing products goes through Magento's import too (status,
   stock or delete), as one more step of the same run.

The run message names them: *Products missing from the file: disabled 2 (A-4, A-5).* The dry run says what it
would do (*would disable 2 …*) and changes nothing.

## Safety

- **Rows with errors stop it.** When OpenImport could not prepare a row, that row may name a product, so nothing is
  disabled, set out of stock or deleted. The run message says so.
- **Safety limit.** When the missing products are more than the profile's safety limit (share of the products in the
  scope), the run waits for a typed confirmation: *Type DISABLE to confirm* (or `OUT OF STOCK`, `DELETE`). The dry
  run shows the same note. On the command line use `--confirm`.
- It works with the behaviours *Add/Update* and *Only update existing*.

Stock: *Set them out of stock* writes Magento's default stock (with MSI: the default source), like the `qty` and
`is_in_stock` columns of Magento's product import.
