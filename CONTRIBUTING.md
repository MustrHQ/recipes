# Contributing to MustrHQ Recipes

Thanks for taking the time to help. MustrHQ Recipes runs on tablets above real kitchen benches,
so the bar is simple: changes should keep it easy to install on ordinary shared hosting, and easy
to read with wet hands and a ticket printer going.

## Ground rules

- **Plain PHP and MySQL.** No frameworks, no Composer, no Node, no build step. Anything that
  cannot be uploaded to cPanel and run as-is will not be merged.
- **PHP 8.1 or newer** must keep working.
- **No third-party requests.** Fonts, scripts and icons are bundled. Nothing phones home. The
  Content Security Policy blocks inline scripts, so behaviour goes in `assets/app.js` and is
  wired up with `data-` attributes.
- **Security first.** Prepared statements for all SQL, `e()` for all output, `csrf_field()` and
  `check_csrf()` on every form, and `require_admin()` on every admin page.
- **Database changes** go in `inc/schema.php` (fresh installs) *and* as a new step at the end of
  `inc/migrations.php` (existing installs). Add tables and columns; never drop or rewrite data.
  Never edit a migration step that has shipped.
- **The recipe pack format** (`docs/recipe-pack.md`) is a public contract. Add optional fields
  only, so older packs keep importing.
- **Kitchen screens first.** Touch targets of at least 44 px, text readable at arm's length, and
  nothing that needs a mouse.

## Reporting a bug

Open an issue using the **Bug report** template. The most useful reports include the version
(Admin → Settings → This installation, or `version.php`), PHP and MySQL versions, what you did,
what you expected and what happened instead. Screenshots help.

**Security problems should not go in a public issue.** See [SECURITY.md](SECURITY.md).

## Suggesting a feature

Open an issue using the **Feature request** template and describe the job you are trying to get
done in the kitchen, not just the button you would add.

## Sending a change

1. Fork the repository and create a branch from `main`.
2. Run it locally: any PHP 8.1+ with MySQL or MariaDB works (MAMP, Laravel Herd, XAMPP, or
   `php -S localhost:8000`). Open the folder in a browser, run the installer, and tick
   "Add two example recipes".
3. Make your change. Keep to the existing style: small functions, readable names, comments that
   explain *why*.
4. Check it on a tablet width, a phone width and desktop.
5. Run `find . -name '*.php' -print0 | xargs -0 -n1 php -l`, and `tests/smoke.sh` against a fresh
   database (the CI runs both on every pull request).
6. If users will notice the change, add a line under an **Unreleased** heading in `CHANGELOG.md`.
7. Open a pull request and fill in the template.

## Making a release (maintainers)

1. Set the version in `version.php` and move the **Unreleased** notes in `CHANGELOG.md` under
   a heading for that version.
2. Commit, then tag and push: `git tag v1.2.0 && git push origin main v1.2.0`.
3. The **Release** workflow checks the tag matches `version.php`, builds
   `mustrhq-recipes-1.2.0.zip` and publishes the release with the changelog notes.

## Licence of contributions

By contributing, you agree that your contribution is licensed under the
[GNU AGPL-3.0](LICENSE), the same licence as the project.

## Code of conduct

Everyone taking part is expected to follow the [Code of Conduct](CODE_OF_CONDUCT.md).
