# Saved connections

*Since 1.4.* A saved connection is a server login that many profiles share: change the password once, and every
profile that uses it logs in with the new one. Open **System › Data Transfer › OpenImport Connections**.

| Kind | Settings |
|---|---|
| **SFTP** | server, port (empty = 22), user name, password or private key (+ passphrase), time limit |
| **FTP** | server, port (empty = 21), user name, password, explicit TLS (FTPS), passive mode, time limit |

Amazon S3 and S3-compatible storage, Google Drive, Dropbox, OneDrive and REST APIs follow in later 1.4 releases as
more kinds of connection.

**Test connection** logs in with what the form holds (saved or not; an empty password field uses the stored one) and
reads the login folder: *Connected to files.example.com:22. The login folder has 3 file(s).*

## Using a connection in a profile

In step 2 of a profile, choose SFTP or FTP as the source (or as the export destination), then pick the connection
under **Saved connection**. The login fields disappear; the profile keeps only what is its own: the folder, the file
pattern, which file to read and what to do with it after a successful run. *Enter the login here* keeps the login in
the profile, as before 1.4.

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
