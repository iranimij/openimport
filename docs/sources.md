# Sources: where a profile reads its file

Step 2 of the profile form. Every source delivers one file per run; the run keeps a copy in `var/openimport/runs/<run id>/`
(removed after "Keep run files for" days).

| Source | Settings | Notes |
|---|---|---|
| **Upload a file** | the file, uploaded in the form | The same file is read again on every run until another one is uploaded; it cannot be scheduled. |
| **Server folder** | folder, file name pattern, which file, move-to folder | A folder under `var/openimport/` (for example `supplier/incoming`), or an absolute path inside a folder allowed under Stores › Configuration › iranimij › OpenImport › Safety › *Extra server folders*. Anything else is refused, also through symbolic links. |
| **Web address (URL)** | address, login (none, user name and password, bearer token), sheet gid | http and https. A Google Sheets link (shared "anyone with the link" or published) is read as CSV; `gid` picks the sheet. |
| **SFTP** | server, port, user name, password or private key (+ passphrase), remote folder, pattern, which file, move-to | Key login with an OpenSSH or PEM key, pasted into its multi-line field (a key that lost its line breaks is repaired before the login). |
| **FTP** | server, port, user name, password, explicit TLS (FTPS), passive mode, remote folder, pattern, which file, move-to | |
| **Amazon S3 / S3-compatible** | saved S3 connection, folder in the bucket, pattern, which file, move-to | Amazon S3, Cloudflare R2, Wasabi, MinIO, Garage and other S3-compatible services; the login is always a [saved connection](connections.md#amazon-s3-and-s3-compatible-storage). |

For SFTP and FTP, **Saved connection** picks a login saved under System › Data Transfer › OpenImport Connections
instead of entering it in the profile (since 1.4); see [Saved connections](connections.md).

**Test connection** checks the settings as they are in the form (saved or not) and says what the next run would read,
without importing anything.

## Which file

For folders and servers, the pattern picks the candidates (`*` any text, `?` one character, case does not matter,
several patterns separated by commas: `stock-*.csv, Stock-*.xlsx`), and *Which file* picks one of them: the newest
(by modification time, the usual choice for a drop folder), the oldest (process a backlog in order, one file per run),
or the first or last by name (`2026-10-14.csv` style names). A run with no matching file fails with a plain message
and nothing else happens.

**All matching files, one run each** (since 1.3) reads every matching file: the run takes the first file by name
(`stock-2.csv` before `stock-10.csv`) and queues one run per other file, so every file has its own report, error list
and move after success. The run message says *File 1 of 3: stock-1.csv. The other files are queued as runs 12, 13.*
Files that another queued run of the profile already has are left to it, so a schedule never imports a file twice.
*When a file fails*: **Go on with the next file** (default; the failed file stays for the next run) or **Stop**: the
queued runs of the batch are cancelled and their files wait for the next run.

## After a successful run

*Source file after a successful run* (step 6) applies to folders and servers:

- **Keep it**: the file stays; the next run reads it again unless a newer one arrives (delta mode makes that cheap).
- **Move it to the processed folder**: moved into the *Move processed files to* subfolder (default `processed`), with
  the date and time added to the name when a file with that name is already there.
- **Delete it**.

Only live runs that imported something (success or partial) move or delete; dry runs and runs started with a file
of their own (CLI `--file`) never touch the source. If the move or deletion fails after the import, the run stays
successful and its message says what did not work.

## Secrets

Passwords, private keys, passphrases and tokens are encrypted with Magento's key (`app/etc/env.php`) and stored in
the profile. They are never sent back to the form (it only says that a value is stored; leave the field empty to
keep it, type a new one to replace it), never exported with a profile and never logged. Saved connections keep theirs
the same way. After
`bin/magento encryption:key:change`, run `bin/magento openimport:secrets:reencrypt`.

## Safety

- URL sources only reach **public** addresses. Private, loopback, link-local and cloud-metadata addresses
  (`10.x`, `192.168.x`, `127.x`, `169.254.x`, `fd00::/8`, …) are refused, also when a public host name resolves to
  one or a redirect leads there. Every redirect hop is checked again, and the request goes to the address that was
  checked. An administrator can allow a host name under Stores › Configuration › iranimij › OpenImport › Safety ›
  *Private hosts allowed as URL sources*, for a feed from an internal system.
- Credentials in the address (`https://user:pw@…`) are refused: use the login fields.
- Downloads are capped at 256 MB.
- Folder sources never leave `var/openimport` and the allowed folders; `..` and symbolic links out of them are refused.
