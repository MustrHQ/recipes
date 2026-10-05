# Security policy

## Supported versions

Security fixes are made for the latest release. Please update to it before reporting:
Admin → Updates and backups makes that a one-file upload.

| Version | Supported |
|---|---|
| 1.1.x | Yes |
| 1.0.x | Security fixes until 1.2 |
| Older | No, please update |

## Reporting a vulnerability

**Please do not open a public issue for security problems.**

Report privately using GitHub's
[private vulnerability reporting](https://github.com/MustrHQ/recipes/security/advisories/new)
(the **Security → Report a vulnerability** button on this repository), or by email to
team@sparkx.digital.

Please include:

- the version you tested,
- the page or file affected,
- steps to reproduce, and what an attacker could achieve,
- any suggested fix, if you have one.

You will get an acknowledgement within a few working days. Once the issue is confirmed, a fix is
prepared and released, and you are credited in the release notes unless you would rather not be.

## Scope

In scope: the code in this repository. Out of scope: problems that need an already-compromised
admin account or server, missing HTTPS on an installation, and the configuration of your own
hosting.

## What the app does to protect you

- Passwords are stored with PHP's `password_hash`. Sign-in and kitchen-code guesses are limited
  per address and recorded in the database.
- Every form carries a CSRF token. All SQL uses prepared statements. All output is escaped.
- A strict Content Security Policy blocks inline and third-party scripts. Nothing is loaded from
  outside your server.
- Uploaded photos are checked as real images and renamed; the `uploads` folder refuses to run
  scripts. `config.php`, `inc/` and `backups/` are not served.
- Updates are read from the zip as data, checked for unsafe paths, backed up first, and rolled
  back if writing fails. Downloads from GitHub are only accepted from this repository's releases.
- The app makes no outgoing connections except when an admin presses **Check now** for updates.

## What you should do

- Serve the app over **HTTPS** (free with AutoSSL or Let's Encrypt in cPanel).
- Delete `install.php` after installing. The admin warns you until it is gone.
- Set a kitchen code if the recipes are confidential.
- Download a backup now and then from Admin → Updates and backups and keep it off the server.
