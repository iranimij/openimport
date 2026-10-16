# Saved connections

*Since 1.4.* A saved connection is a server login that many profiles share: change the password once, and every
profile that uses it logs in with the new one. Open **System › Data Transfer › OpenImport Connections**.

| Kind | Settings |
|---|---|
| **SFTP** | server, port (empty = 22), user name, password or private key (+ passphrase), time limit |
| **FTP** | server, port (empty = 21), user name, password, explicit TLS (FTPS), passive mode, time limit |
| **Amazon S3 / S3-compatible** | bucket, region, endpoint, path-style addresses, access key, secret key, time limit |
| **Google Drive / Sheets** | a service account key, or a Google account (OAuth client ID and secret, then *Sign in with Google*) |

Dropbox, OneDrive and REST APIs follow in later 1.4 releases as more kinds of connection.

**Test connection** logs in with what the form holds (saved or not; an empty password field uses the stored one) and
reads the login folder: *Connected to files.example.com:22. The login folder has 3 file(s).*

## Amazon S3 and S3-compatible storage

OpenImport talks to S3 itself (Signature Version 4, no SDK), so the same connection works with Amazon S3 and with
services that speak its API:

| Service | Region | Endpoint | Path-style |
|---|---|---|---|
| Amazon S3 | the bucket's region, e.g. `eu-central-1` | empty | off |
| Cloudflare R2 | `auto` | `https://<account id>.r2.cloudflarestorage.com` | on or off |
| Wasabi | the bucket's region, e.g. `eu-central-1` | `https://s3.eu-central-1.wasabisys.com` | off |
| MinIO, Garage, SeaweedFS and other self-hosted servers | what the server is set up with, often `us-east-1` | the server's address, e.g. `http://minio:9000` | on |

Folders are key prefixes: the profile's folder `incoming` reads the files `incoming/*` (not those in deeper
folders), *Move it to the processed folder* copies the file to `incoming/processed/` and deletes the original.

The access key needs these permissions on the bucket: list (`s3:ListBucket`), read (`s3:GetObject`), and for exports
and moving files write and delete (`s3:PutObject`, `s3:DeleteObject`).

Like every address OpenImport reaches, the endpoint must be public. A server in your own network (MinIO on the same
host, for example) needs its host name under Stores › Configuration › iranimij › OpenImport › Safety › *Private hosts
allowed as URL sources*.

## Google Drive and Google Sheets

Two ways to log in:

- **Service account** (best for servers): in the Google Cloud console create a project, switch on the *Google Drive
  API*, create a service account and a JSON key for it, and paste the key into the connection. The form then shows
  the account's e-mail address: share the Drive folders (or a Shared Drive) with it, as you would with a person.
- **Google account**: create an OAuth client of the type *Web application*, add the *Authorized redirect URI* the
  connection form shows, enter client ID and client secret, save, then click **Sign in with Google**. OpenImport
  keeps only the refresh token, encrypted. The connection sees what that person sees.

OpenImport runs no service of its own in between: each shop uses its own Google Cloud project.

In a profile, the folder is the folder's link (`https://drive.google.com/drive/folders/…`), its id, or a path from
*My Drive* such as `Supplier/incoming`. Google Sheets in the folder appear as `<name>.xlsx` and are read as Excel
files (every sheet; pick one under the file settings). *Move it to the processed folder* moves the file into a
`processed` folder next to it (made when missing); *Delete it* moves it to the Drive bin.

Exports upload into the folder; a file with the same name gets the new content and keeps its link. **Save as a Google
Sheet** turns the export into a Google Sheet (named without `.csv`), and later exports replace its content.

Published "anyone with the link" Sheets still work without a connection as a *Web address* source.

## Using a connection in a profile

In step 2 of a profile, choose SFTP, FTP, Amazon S3 or Google Drive as the source (or as the export destination), then pick the
connection under **Saved connection**. The login fields disappear; the profile keeps only what is its own: the folder, the file
pattern, which file to read and what to do with it after a successful run. For SFTP and FTP, *Enter the login here* keeps the
login in the profile, as before 1.4; S3 and Google Drive always use a saved connection.

Only connections of the chosen kind are offered. A profile whose connection was deleted or changed to another kind
cannot be saved and its runs fail with a plain message (*The saved connection "Supplier" is for SFTP, not for FTP.*).

## Deleting

A connection that a profile uses cannot be deleted; the message names the profiles. The grid shows which profiles use
each connection.

## Secrets

Passwords, private keys and passphrases are encrypted with Magento's key, never sent back to the form and never
logged. After `bin/magento encryption:key:change`, `bin/magento openimport:secrets:reencrypt` encrypts the secrets of
profiles and connections again.

## Profiles as JSON

A profile file names its connection (`"connection": "Supplier SFTP"` in `source_config` or `export_options`), never
its id or login. Importing the file on another shop needs a connection with that name there; otherwise the import
stops before anything is saved and says which connection is missing.

## Permissions

The admin role resource **OpenImport › Manage connections** opens the connections grid and form. Choosing a saved
connection in a profile only needs the profile permissions.
