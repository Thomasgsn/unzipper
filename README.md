# The Unzipper

A single-file PHP utility for extracting ZIP, RAR, GZIP and TAR.GZ archives on a webserver, and creating ZIP archives from local directories. No Composer, JavaScript packages, external fonts or services are required.

## Requirements

- PHP 8.2 or newer, with sessions enabled and a writable system temporary directory.
- PHP `zip` for ZIP extraction and creation.
- PHP `zlib` for GZIP, TAR.GZ and TGZ extraction.
- Optional PHP `rar` extension for RAR extraction.

The page shows which formats your server supports. Unsupported formats are disabled.

## Setup

1. Place `unzipper.php` beside the archives you want to extract.
2. Set the **`UNZIPPER_PASSWORD` server environment variable** to a long, unique password through your hosting control panel or server configuration. Access is disabled until a password is set, including on localhost. Keep credentials outside the web directory and source control.
3. Open `unzipper.php` over **HTTPS**. Sign in using any username and your configured password. Your PHP server must pass HTTP Basic authentication credentials to PHP; some FastCGI/proxy setups require configuration for this.
4. Remove the utility and archives when finished. Your webserver may serve archive files directly, without the utility's password protection.

For local development, after exporting `UNZIPPER_PASSWORD` in your shell:

```sh
php -S 127.0.0.1:8080
```

Open `http://127.0.0.1:8080/unzipper.php`. Use HTTPS for remote deployments; Basic authentication does not encrypt credentials.

## Extract an archive

Select a local archive and optionally enter a relative destination such as `site-restore` or `uploads/images`. An empty destination uses the directory containing the script. New folders are created as needed.

Existing files are **never overwritten**. If a destination file exists, choose another folder. Archive contents are validated and staged outside the web directory before they are copied into the destination. Temporary files are cleaned up after normal success or failure; abrupt server termination can leave temporary or partially published files..

## Create a ZIP

Enter a relative source directory or leave it empty for the script's directory. The ZIP contains that directory's contents and is saved beside `unzipper.php` with a unique name. Source files are left in place.

Creation excludes symbolic links, dotfiles and dot-directories (including `.env` and `.git`), existing archive files, and this utility. Other files can still contain sensitive data: review your source directory before creating or sharing an archive. Empty source directories with no eligible entries produce an error.

## Security and limits

- Password authentication, session CSRF tokens, escaped output, restrictive Content Security Policy and no-store responses.
- All source and destination paths stay inside the directory containing the script. Absolute paths, traversal, stream wrappers, ambiguous Windows names and symbolic links are rejected.
- ZIP and TAR links and special files are rejected; RAR entries are copied through bounded streams instead of native extraction. Checksums are verified before publishing files.
- At most **10,000 archive entries** and **512 MiB of expanded data** per operation. Archive input files are also limited to 512 MiB. TAR metadata and padding count toward the decompressed GZIP limit. Creation also bounds directory traversal, including excluded entries.
- A workspace lock prevents overlapping operations through this utility. Avoid concurrent external modifications to source/destination directories; this utility is not an isolation boundary against other processes with write access to the same files.
- Encrypted archives, multi-member GZIP streams, sparse TAR files, TAR links and unsupported TAR extensions are rejected. Standard USTAR, per-file PAX metadata and GNU long filenames are supported.

Only extract archives you trust. This tool intentionally supports deploying executable files such as PHP scripts: path validation is not malware scanning or a sandbox. Keep backups and remove the tool when finished. Refer to the [OWASP archive/file handling guidance](https://cheatsheetseries.owasp.org/cheatsheets/File_Upload_Cheat_Sheet.html) for deployment considerations.

## Validation

With PHP (including `zip` and `zlib`) and Python 3 installed:

```sh
php -l unzipper.php
python3 -m unittest discover -s tests -v
```

The suite uses temporary directories and a localhost PHP server. It covers normal extraction/creation, archive integrity, traversal, links, no-overwrite behavior, entry/size limits, authentication, CSRF, escaping and security headers. RAR requires its optional extension and is not covered by runtime fixtures in this suite.

## License and credits

GNU GPL v3. Original utility by Andreas Tasch, at[tec], attec.at.

[Upstream project](https://github.com/ndeet/unzipper) · [Contributors](https://github.com/ndeet/unzipper/graphs/contributors)
