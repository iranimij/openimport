# Product pictures

Product imports take pictures in the same columns as Magento's own import: `base_image`, `small_image`,
`thumbnail_image`, `swatch_image`, `additional_images` (several, separated by commas), `hide_from_product_page` and any
custom image attribute. A cell can hold a **web address** or a **file name**.

## Pictures from web addresses

OpenImport downloads every `http://` or `https://` picture itself before Magento sees the row, and hands Magento the
downloaded file. Magento never downloads a picture on its own, so:

- **Safe.** Every address, and every redirect on the way, goes through the same check as URL sources: private, local
  and link-local addresses are refused unless an administrator allows the host under *Stores › Configuration ›
  iranimij › OpenImport › Safety*. Only http and https are read.
- **Checked.** A picture must be a JPG, PNG or GIF, told by its first bytes, not by its address or by what the server
  claims. A web page sent instead of a picture (an error page with status 200, a login page) is refused with a clear
  message. WebP, AVIF and SVG are refused because Magento's product import does not accept them.
- **Capped.** Pictures larger than *Largest picture (MB)* (10 by default) or slower than *Time per picture* (30
  seconds) count as unreachable.
- **Downloaded once.** Each address is downloaded once per run, however many rows and columns use it. Downloaded
  pictures are kept in `var/import/images/openimport`. For *Check for changed pictures after (hours)* (24 by default)
  the kept file is used without asking the server. After that the next run asks with the server's ETag and
  Last-Modified (a conditional request) and downloads only pictures that changed. Set it to 0 to ask on every run.
- **Robust.** When a server cannot be reached but the picture was downloaded before, the earlier file is used.
- **Good names.** The kept file has the name from the address (`…/Red%20Shoe.JPG` becomes `red-shoe.jpg`). Magento
  names the catalog file after it. The folder comes from the content's checksum, so a changed picture gets a new
  file and equal pictures share one.

Dry runs download too, so a dry run tells you which pictures cannot be read and the live run finds them ready.

The run message counts the pictures: *Pictures: 12 downloaded, 230 unchanged, 1 could not be read.*

## When a picture cannot be read

Step 5 of the profile, **Pictures that cannot be read**:

| Choice | What happens |
|---|---|
| Report the row as an error (default) | The row is a row error, with the address and the reason (`HTTP 404`, *a web page, not a picture*, …). *Rows with errors* and *Errors allowed* decide the rest, as for any row error. |
| Import the row without the picture | The row is imported; the picture's cell is left empty, which keeps the product's current picture. The run message names the first five rows and pictures left out. |

With *Skip unchanged rows* on, a row imported without a picture is not remembered as done: the next run tries the
picture again.

## Pictures from file names

A cell without `://` is a file name. Magento reads it from the import images folder, `var/import/images` (the
*Images Upload Directory* under *Stores › Configuration › Advanced › System*), the same folder Magento's own import
uses. Sub folders work: `supplier/red-shoe.jpg` is `var/import/images/supplier/red-shoe.jpg`.

> **Changed in 1.3.** Before 1.3 OpenImport left the folder to Magento's default for imports started by code,
> `pub/media/import`. Move pictures you placed there to `var/import/images`.

## Housekeeping

Downloaded pictures no run used for *Keep unused downloaded pictures for (days)* (30 by default) are removed from
`var/import/images/openimport` by the OpenImport housekeeping cron. Pictures already copied into the catalog stay.

## Settings

*Stores › Configuration › iranimij › OpenImport › Pictures from web addresses*:

| Setting | Default |
|---|---|
| Check for changed pictures after (hours) | 24 |
| Largest picture (MB) | 10 |
| Time per picture (seconds) | 30 |
| Keep unused downloaded pictures for (days) | 30 |
