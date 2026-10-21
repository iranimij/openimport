# Reviews, newsletter, search terms, synonyms, category positions

Since 1.5. Five small entities, each import (Add/Update, Delete) and export, each a round trip: export a store,
import the file into another, export again, same rows. Replace is refused for all of them.

## Product reviews (`openimport_review`)

Reviews with ratings, matched by **product SKU, nickname and title** (or `review_id`, which the export writes).

| Column | Required | Meaning |
|---|---|---|
| `product_sku` | yes | The reviewed product. |
| `nickname`, `title`, `detail` | new reviews | Author name, summary, text. |
| `status` | no | `approved`, `pending` or `not approved`; new reviews: approved. |
| `store_codes` | no | Store views the review is shown in, comma-separated, or `all`; new reviews: all. |
| `created_at` | no | The date (`2025-02-03 10:00:00`); empty: now. |
| `customer_email` | no | Links the customer with that e-mail on the store view's website (the review stays a guest review when empty). |
| `ratings` | no | `Quality:4, Value:5` — the shop's ratings (Stores › Rating) by name with a value from 1 to 5. Replaces the review's votes. |

The product's review summary (stars on the product page) is recalculated after each review. Delete removes the
reviews named by product, nickname and title.

## Newsletter subscribers (`openimport_newsletter`)

| Column | Required | Meaning |
|---|---|---|
| `email` | yes | The subscriber, matched on the store view's website. |
| `store_code` | no | The store view; empty: the default store view. |
| `status` | no | `subscribed` (the default for new rows), `unsubscribed`, `not activated`, `unconfirmed`. |

A customer with that e-mail on the website is linked to the subscription. **No e-mail is sent** — no
confirmation, no welcome, no unsubscribe notice — this is a data import, not a sign-up form. Delete removes the
subscriptions.

## Search terms (`openimport_search_term`)

| Column | Required | Meaning |
|---|---|---|
| `query_text` | yes | The term as typed, matched per store view. |
| `store_code` | no | Empty: the default store view. |
| `redirect` | no | A URL the search redirects to; an empty cell on an existing term removes the redirect. |
| `display_in_terms` | no | `1`/`0`: shown among the popular search terms. |
| `popularity` | no | The counter; `num_results` is exported but not imported (Magento fills it on the next search). |

## Search synonyms (`openimport_search_synonym`)

| Column | Required | Meaning |
|---|---|---|
| `synonyms` | yes | At least two words, comma-separated: `shirt, tee, top`. |
| `scope` | no | `all` (every store view, the default), `website:base`, `store:de`; a bare code is tried as a store view, then a website. |

A group is matched by its words and scope. A word that already sits in another group of the same scope merges
the two groups, as the admin form does. Delete removes the group that holds the words.

## Product positions in categories (`openimport_category_position`)

| Column | Required | Meaning |
|---|---|---|
| `category_path` | yes | The category by path (`Default Category/Men/Shirts`), as the category import. |
| `product_sku` | yes | The product. |
| `position` | no | The sort position in the category; empty keeps an existing position (new assignments: 0). |

Add/Update assigns the product to the category (when it is not in it yet) and sets the position; Delete removes
the product from the category. The export writes every product-category assignment, so a file of one store's
merchandising lands in another.

## What a run does

Every row is checked first (the product, category, store view, customer, rating or scope exists; statuses, dates
and numbers are valid; the same record is not in the file twice). A dry run counts and names every problem with
the row number. Writes go through Magento's review, subscriber, search query, synonym group and category link
models and repositories — the same code the admin forms use, so indexers, caches and summaries follow.
