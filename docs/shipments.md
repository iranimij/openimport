# Shipments and tracking numbers

Since 1.5. Entity **Shipments and tracking numbers** (`openimport_shipment`), import only. A file from the
warehouse or the carrier ships orders through Magento's own shipment service (`ShipOrderInterface`): stock, order
state, shipment e-mails and the order history are exactly what the admin's *Ship* button does.

## Columns

| Column | Required | Meaning |
|---|---|---|
| `increment_id` | yes | The order number as the customer sees it (`100000123`). |
| `item_sku` | no | One item of the order. Empty: everything that is still open is shipped. A child's SKU (configurable, bundle) finds its parent. |
| `qty` | no | How many of that item; needs `item_sku`. Empty: all that is left of the item. |
| `carrier_code` | no | `ups`, `dhl`, `fedex`, `usps`, a carrier of an installed shipping module, or `custom` (the default when a tracking number has no carrier). |
| `title` | no | The name shown with the tracking number; empty: the carrier's name. |
| `tracking_number` | no | Empty: a shipment without a tracking number. |
| `notify` | no | `1`, `yes` or `true`: send the shipment e-mail to the customer. |
| `comment` | no | A comment on the shipment (not visible to the customer). |

Rows of the same order in one file make **one shipment**: all their items, all their tracking numbers. Two
shipments of one order are two runs (or two files).

## What a run does

- Every row is checked first: the order exists and can be shipped, the item is open, the quantity is within what is
  left, the carrier is known. A dry run shows how many shipments would be made and names every problem with the
  file's row number. With *When a row has an error → stop*, nothing is shipped while one row is wrong.
- An order that already carries one of the file's tracking numbers, or has nothing left to ship, is **skipped and
  reported** (the run ends as *partial*): the same file can be imported twice without a second shipment.
- Behaviour: *Add/Update* or *Only add*. There is nothing to update, replace or delete.
- Auto-match knows `Bestellnummer`, `order number`, `Sendungsnummer`, `tracking`, `carrier`, `Menge` and the like.

Sample file:

```csv
order;sku;qty;carrier;tracking;notify
100000123;;;dhl;00340434161094015902;1
100000124;SHIRT-RED-M;1;ups;1Z999AA10123456784;1
100000124;SHIRT-RED-L;1;ups;1Z999AA10123456784;
```
