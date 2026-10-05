# Changelog

All notable changes to MustrHQ Recipes are listed here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow
[Semantic Versioning](https://semver.org/).

## [1.0.0] - 2026-10-05

First public release.

### Kitchen screens
- Recipe library with instant search by name or code, brand filters, and recipes grouped by brand.
- Recipe page: finished-dish photo, timings, the 14 allergens, diet tags and a safety note.
- "What you need": every ingredient with its photo and amount. Batch buttons (½× to 4×) scale
  the amounts, and staff can tick ingredients off as they gather them.
- Step-by-step mode: one step per screen with a large photo and large text, Back and Next,
  swipe and keyboard. Step timers with an alarm. The ingredient list opens from any step. Ends
  on the finished photo so the team can check the dish matches. Keeps the screen awake.
- Image preview on every photo: full screen, swipe between pictures, double-tap to zoom.
- Optional kitchen code (4 to 8 digits) so only your team can open recipes.
- Installable as an app on tablets and phones, with photos cached for weak kitchen wifi and an
  offline screen.
- Print layout for any recipe.

### Admin
- Recipe editor with ingredient and step rows you can add, reorder and remove, and a photo for
  the dish, each ingredient and each step. Big photos are shrunk in the browser before upload
  to fit shared-hosting limits.
- Recipe list with counts for live, hidden and missing photos, search and filters. Copy, hide
  and delete.
- Brands with colours and order. Several admins. Kitchen settings.
- Import and export of recipe packs (`recipes.json` plus photos), with a preview before anything
  changes. A downloadable ChatGPT brief that produces packs in the right format.
- Updates and backups: install a new release from its zip with a preview, automatic backups
  first, database upgrades, and one-click restore. Manual backups of the database, photos and
  app files.

### Under the hood
- Plain PHP 8.1+ and MySQL/MariaDB. No framework, Composer or build step.
- Browser installer with server checks; writes `config.php` for you.
- Two illustrated example recipes.
- Security headers and a strict Content Security Policy, CSRF tokens, prepared statements,
  sign-in throttling, protected folders.
