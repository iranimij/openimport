# Cart and catalog price rules

Since 1.5. Two entities, import and export: **Cart price rules and coupons** (`openimport_cart_rule`, *Marketing ›
Cart Price Rules*) and **Catalog price rules** (`openimport_catalog_rule`, *Marketing › Catalog Price Rules*). A
file of rules — seasonal discounts, a coupon campaign, the price rules of another store — lands in Magento's
rules through the rule models the admin forms use. The export writes every rule with its conditions as JSON, and
the file imports back as it is, into the same or another store.

Rules are matched by **name** (`rule_id` when the file has one, which the export writes). *Add/Update* creates or
updates — empty columns of an existing rule keep their values — *Delete* removes the rules named in the file,
*Replace* is refused. Two rules with the same name in the shop are reported; add the `rule_id` column to say
which one.

## Columns of both

| Column | Required | Meaning |
|---|---|---|
| `name` | yes | The rule's name. |
| `rule_id` | no | Magento's id, to update a rule whose name changes. |
| `description`, `is_active`, `priority`, `stop_rules_processing` | no | As in the admin form (`priority` = *Priority* / sort order). New rules: active, priority 0. |
| `websites` | no | Website codes, comma-separated, or `all`. New rules: all websites. |
| `customer_groups` | no | Customer group codes (`NOT LOGGED IN`, `General`, …), comma-separated, or `all`. New rules: all groups. |
| `from_date`, `to_date` | no | `2026-11-01`; empty: no limit. |
| `action` | new rules | Cart: `by_percent`, `by_fixed`, `cart_fixed`, `buy_x_get_y`. Catalog: `by_percent`, `by_fixed`, `to_percent`, `to_fixed`. |
| `discount_amount` | new rules | The percentage or amount. |
| `condition_skus` | no | SKUs, comma-separated: the rule applies when such a product is in the cart (cart) or to these products (catalog). |
| `condition_categories` | no | Category paths (`Default Category/Sale`), comma-separated, likewise. |
| `conditions` | no | Magento's condition tree as JSON, for everything the simple columns cannot say (see below). When filled, the simple condition columns are ignored. |

## Columns of cart price rules

| Column | Meaning |
|---|---|
| `coupon_type` | `none` (the default), `specific` (a code) or `auto` (Magento generates codes). Empty with a `coupon_code`: specific. |
| `coupon_code` | The coupon code of the rule. |
| `coupon_codes` | Several codes of one rule, separated by `;` or `,` — a code list from a campaign. Codes the rule has already are kept; a code of another rule is an error. |
| `uses_per_coupon`, `uses_per_customer` | Limits; 0 = unlimited. |
| `discount_qty`, `discount_step` | *Maximum Qty Discount is Applied To*, *Discount Qty Step (Buy X)*. |
| `apply_to_shipping` | `1`/`0`. |
| `free_shipping` | `no`, `matching` (for matching items only) or `cart` (for the whole order with matching items). |
| `labels` | What the customer sees: a default label, and per store view `code:label`, separated by `\|`: `10 % off\|de:10 % Rabatt`. |
| `condition_subtotal` | The rule applies from this subtotal on (`base_subtotal >= X`). |
| `action_skus`, `action_categories` | Which items get the discount (*Apply the rule only to cart items matching…*); empty: all items of the cart. |
| `actions` | The action condition tree as JSON (when filled, `action_skus` and `action_categories` are ignored). |

## Conditions as JSON

The `conditions` (and `actions`) columns carry the tree the admin form builds, as Magento stores it: every node
has a `type`, a leaf has `attribute`, `operator` and `value`, a group has `aggregator` (`all`/`any`), `value`
(`1` = true) and `conditions`. Types may be short — `Combine`, `Address`, `Product\Found`, `Product\Subselect`,
`Product` for cart rules, `Combine` and `Product` for catalog rules — or full class names; every type must be a
Magento condition class, anything else is refused with the row number. Operators are Magento's: `==`, `!=`, `>=`,
`<=`, `>`, `<`, `{}` (contains), `!{}`, `()` (is one of), `!()`.

A cart rule for orders of 100 or more **or** any product of category 12:

```json
{"type":"Combine","aggregator":"any","value":"1","conditions":[
  {"type":"Address","attribute":"base_subtotal","operator":">=","value":"100"},
  {"type":"Product\\Found","value":"1","aggregator":"all","conditions":[
    {"type":"Product","attribute":"category_ids","operator":"()","value":"12"}]}]}
```

The export writes exactly this form (with full class names), so the easiest way to a complex rule is: build one in
the admin, export, copy.

## What a run does

- Every row is checked: name, websites and groups exist, dates, action, numbers, coupon type, the condition JSON
  (every type), categories exist. A dry run counts created and updated rules and names every problem with the row
  number; nothing is written.
- Catalog price rules need **applying** after the import, as after a change in the admin: *Marketing › Catalog
  Price Rules › Apply Rules*, or the `catalogrule_rule` indexer (cron does it when the indexer is scheduled).
- Auto-match knows `Regel`, `Gutschein`, `coupon`, `Rabatt`, `discount`, `percent`, `Kundengruppen`, `from`, `to`,
  `SKUs`, `Mindestbestellwert`, `subtotal` and the like.

Sample file (cart rules):

```csv
name;coupon_code;action;discount_amount;condition_subtotal;customer_groups;from_date;to_date;labels
Winter 10;WINTER10;by_percent;10;50;all;2026-12-01;2027-01-31;10 % off|de:10 % Rabatt
Free shipping over 100;;by_percent;0;100;all;;;Free shipping
```

(The second rule needs `free_shipping` = `cart`; add the column.)
