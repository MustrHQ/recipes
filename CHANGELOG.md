# Changelog

All notable changes to MustrHQ Recipes are listed here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow
[Semantic Versioning](https://semver.org/).

## [1.1.0] - 2026-10-05

### Added
- **Use-by labels**, from MustrHQ Station. A Labels screen on the tablet: pick an item, say when it was
  made, opened or defrosted, check the exact label, and print. Shelf life in days (the day it was made
  counts as day 1) or hours. Optional supplier's date (the sooner date wins), the cook's initials, and
  allergens on the label.
- **Bluetooth label printing** (Chrome and Edge): TSPL and ESC/POS printers, test print, invert option,
  remembered per tablet and reconnected automatically. The print dialog (AirPrint on iPad) at the exact
  label size is always there as a fallback. Default 50 × 30 mm labels printed 48 mm wide at 203 dpi.
- **Admin → Labels**: label items with shelf life, storage, allergens and an optional link to a recipe;
  one click adds a label item for every recipe that has none; label size and printer settings with a
  live sample.
- **Print label** buttons on the recipe page and at the end of step by step, for linked recipes.
- **Import from MustrHQ Station**: Station exports and ChatGPT `mustrhq-pack.zip` files import with every
  recipe and picture. Ready items become ingredients with their photos, picture file names such as
  `katsu-and-co__k01__step3.png` are placed automatically, split `recipes-part1.json`… files are merged,
  and Station's packing, quality and chef notes go into the recipe note.
- **Big packs**: files are sent in parts, so they can be larger than the hosting's upload limit, and
  imported a few recipes at a time with a progress bar, so hundreds of recipes never hit a time limit.
  A picture shared by many recipes is stored once.
- **Check GitHub for a new version** in Updates and backups: download the release and see the usual
  preview, without leaving the admin. Only runs when you press the button.
- **Tablet app**: an Install app button, shortcuts to Recipes and Labels, store screenshots, and a setup
  guide for Android and iPad in Settings.

### Changed
- Recipe and Labels tabs in the kitchen top bar.
- Photos used by more than one recipe are only deleted when nothing uses them any more.



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
