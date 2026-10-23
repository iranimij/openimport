# Security

OpenImport fetches files from other servers, reads formats that can carry attacks, and writes into the catalog. This
page lists what the module does about that, what the settings control, and what remains your job.

## Admin actions

- Every controller is behind an ACL resource: *View profiles*, *Create and edit profiles*, *Run profiles*, *Confirm
  runs above the safety limit*, *View runs and error reports*, *Download run files*, *Manage connections*, plus the
  configuration section. Roles that may view but
  not edit get a read-only form. File contents (first rows, the mapping preview, an export's first rows, the source
  cells of error reports) need *Download run files*.
- Grids send no secrets: the profiles grid strips the encrypted passwords, keys and tokens from the settings it sends
  to the browser (since 1.5.4).
- Everything that changes state is a POST with Magento's form key; everything that reads is a GET. File answers go
  through Magento's `FileFactory`, never a raw path.
- Uploads: 256 MB at most, only `.csv .tsv .txt .xlsx .json .xml`, stored under a random token in `var/openimport/tmp`
  and removed by housekeeping. A token is accepted only if it names a file in that folder (no `..`, no other folder).
- Sample and preview answers, error reports and the run page escape every value; messages from Magento's importer
  are shown as text.

## Remote sources and destinations

- **Web address:** `http` and `https` only, no credentials in the URL. The host is resolved and every address must
  be public: loopback, private (RFC 1918), link-local (169.254.x, the cloud metadata address), carrier-grade NAT,
  IPv6 ULA and link-local, and IPv4-mapped IPv6 are refused. The request is pinned to the checked address
  (`CURLOPT_RESOLVE`), every redirect hop is checked again, basic auth and bearer tokens are sent to the first host
  only, and downloads stop at 256 MB. Hosts you trust (a warehouse system on the LAN) go into *Allowed private hosts*.
- **Server folder:** only `var/openimport` and the folders listed in *Allowed folders* (absolute paths, real paths
  compared, so symlinks out of the sandbox do not help). Patterns pick files; the module never runs them.
- **SFTP / FTP:** host names are resolved and checked like URLs. SFTP uses phpseclib (password or private key, with an
  optional passphrase); FTP uses PHP's `ftp_*` functions with TLS when switched on, passive mode. Nothing is executed on
  the remote side; the module lists, downloads, uploads, renames and deletes inside the configured folder.
- **E-mail destination:** attachments are capped (setting), the recipients come from the profile, never from the
  file.

## Credentials

Passwords, private keys, passphrases and tokens are encrypted with Magento's encryptor and stored under a separate
`secrets` key of the profile. They are never written to logs, run messages, JSON profile exports, the admin form (the
form shows "stored" and lets you replace a secret, not read it) or the error report. After a key rotation run
`bin/magento openimport:secrets:reencrypt`.

## File formats

- **XML:** streamed with `XMLReader`; documents with a `DOCTYPE` are refused, so there is no entity expansion and no
  external entity. The parser never resolves URLs.
- **Archives (ZIP, gzip, tar):** the file inside is written under a fixed name (entry names never become paths: no
  zip-slip), its size is counted while unpacking (1 GB limit, zip bombs), links are skipped, nested archives and
  password-protected entries are refused. See [Archives](archives.md).
- **XSLT:** stylesheets run with every read and write permission of libxslt switched off (no `document()`, no
  `xsl:include`/`xsl:import`, no files, no network), no PHP functions, no DOCTYPE in the stylesheet or the file, a
  size limit for the file (a DOM holds it whole). See [XSLT](file-formats.md#xslt-since-14).
- **ODS:** as XLSX (no DOCTYPE, no network, 512 MB unpacked limit), streamed with XMLReader.
- **XLSX:** read from the zip with size checks; a workbook whose parts would unpack to more than 512 MB is refused
  (zip bombs). Only the parts the reader needs are opened.
- **CSV written by the module** (error reports, exports): cells starting with `= + - @` or a tab are prefixed with an
  apostrophe when the *formula guard* setting is on (default), so a product name cannot become a spreadsheet formula.
- **Expressions and transforms:** the expression language is parsed once per run into a small AST and evaluated
  without `eval`; it has no access to PHP functions, files or the database. Division by zero and type errors become
  row errors.

## Writing into Magento

- Magento's own importer validates and writes every row. OpenImport does not write catalog tables itself.
- Runs that would delete or replace more records than the profile's safety threshold stop and wait for a typed
  confirmation by a user with the *Confirm runs above the safety limit* resource. New profiles always start with a dry
  run.
- One run per profile and one live import per core entity at a time (database locks with heartbeats), so two cron
  ticks cannot import the same file twice.

## What stays with you

- Protect `var/openimport` like the rest of `var/`: it holds uploaded and fetched files and the error reports.
- Give SFTP/FTP accounts the smallest folder they need; the module cannot escape its folder, but the account could.
- Keep Magento's cron under a user that cannot write to the code base.
- Report problems privately, see [SECURITY.md](https://github.com/iranimij/openimport/blob/main/SECURITY.md).
