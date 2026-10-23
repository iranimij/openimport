# Archives: ZIP, gzip and tar

Since 1.4. Suppliers often send a `products.zip` or a `stock.csv.gz`; OpenImport unpacks it and reads the file
inside, and can pack an export before it is delivered.

## Reading an archive

Every source (upload, server folder, web address, SFTP, FTP, S3, cloud drives) may deliver an archive. Step 3 of the
profile form has two settings:

| Setting | Meaning |
|---|---|
| **Archives (ZIP, gzip, tar)** | *Unpack archives* (default): a `.zip`, `.gz`, `.tar`, `.tar.gz` or `.tgz` file (by name, else by its first bytes) is unpacked and the file inside is read. *Read the file as it is*: nothing is unpacked. |
| **File inside the archive** | Which file to take out of a ZIP or tar: a name or a pattern (`products-*.csv`, `data/*.xml`, matched against the file name and its path inside the archive, case-insensitive). Empty: the first CSV, TSV, TXT, Excel, ODS, JSON or XML file by name. Hidden files and macOS `__MACOSX` folders are ignored. |

A `.gz` file holds one file, named after the archive (`stock.csv.gz` → `stock.csv`); a `.tar.gz` holds a tar.
The **Format** setting and the sample in the form apply to the file inside; the run page names it
("Read products.csv from feed.zip.") and the run's copy is that file, not the archive. *After success* (move or
delete) acts on the archive, as it does on any other source file. Excel (`.xlsx`) and OpenDocument files are ZIP
files too; they are never taken for archives.

## Writing an archive

**Compress** in step 3 of an export profile packs the written file as `.zip`, `.gz` or `.tar.gz` before it is
delivered (download, folder, SFTP, FTP, S3, cloud drive, REST, e-mail). The file name gets the archive's extension
(`products-2026-10-21.csv.zip`); the archive holds the file under its own name.

## Limits and safety

- Entry names never become paths: the chosen file is written under a fixed name in the run folder, so an archive
  with `../../` or absolute names (zip-slip) cannot write anywhere else. Links and devices inside tar files and ZIPs
  are skipped.
- The unpacked size is counted while writing, not taken from the archive's headers: more than 1 GB stops the run with
  a message (zip bombs); the partial file is removed. An archive with more than 10,000 entries is refused.
- Archives inside archives are not unpacked; password-protected entries are refused; a damaged archive fails the run
  with a plain message. The limits are arguments of `Iranimij\OpenImport\Model\Archive\Unpacker` in `di.xml`.
