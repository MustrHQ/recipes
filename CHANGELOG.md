# Changelog

All notable changes to MustrHQ Recipes are listed here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow
[Semantic Versioning](https://semver.org/).

## [1.4.6] - 2026-10-21

### Added
- **Import images from cPanel** in Admin > Import and export. ZIPs placed in the
  protected `backups/image-inbox/` folder appear in the portal after refreshing.
- One-click import into matching dish, ingredient and step slots. ZIPs are read
  directly on the server, never uploaded through the portal or copied in full.
- Persistent per-archive progress, Resume import and Import again controls, with
  completion counts and unmatched filename lists. Original ZIPs are kept.

### Safety and matching
- Server imports use image-only mode even when a ZIP also contains recipes.json.
- Admin access, CSRF tokens, locked job records and position checks protect each run.
- Symlink/path traversal sources are rejected. Changed ZIPs or recipe placements
  stop an existing run for rechecking rather than guessing another slot.

## [1.4.5] - 2026-10-21

### Added
- Resumable uploads: reselect the same file in the same signed-in browser to
  continue from the server's committed byte position. Pause and Start over controls.
- Bounded automatic retries for transient connection/gateway failures during uploads.
  Recipe application is never automatically retried.
- Persisted upload receipts and a status endpoint. Repeated chunks are verified
  against saved bytes, including after a lost final response.

### Fixed
- Retrying the first chunk no longer erases saved progress or appends duplicate data.
- Incomplete, uncommitted writes are truncated to the last acknowledged boundary
  before resending. Interrupted finalization can be completed through a status check.
- Expired upload receipts are cleaned with the temporary import files after 24 hours.

## [1.4.4] - 2026-10-21

### Changed
- Upload chunks are capped at 512 KB, with room for multipart overhead on smaller
  hosting limits, instead of sending up to 8 MB per request.
- The final upload request only finishes saving the file. ZIP validation runs in a
  separate request, and normalized preview metadata is cached without image bytes.
- Upload errors identify whether sending, validating or applying the pack failed.

### Added
- Private bounded import diagnostics with request IDs, request stages, elapsed time,
  peak PHP memory and status. Admins can download the report from Import and export.
- Fatal-memory and execution failures return a structured error when PHP can still
  respond. A gateway failure before PHP runs cannot be captured by the application.

## [1.4.3] - 2026-10-21

### Added
- Admin **Delete all recipes**: confirms the global count and typed phrase, deletes in
  resumable batches, keeps brands and label records, and removes only unused pictures.
- Dedicated **Import images only** upload. No recipes file is needed; JSON files inside
  an image ZIP are ignored in this mode.
- Image matching for native export filenames (`__dish`, `__step-1`, `__ing-1`) as well
  as the supplied kitchen image sets. Preview lists each image's destination slot
  and every unmatched filename.

### Fixed
- All upload forms use chunked uploads, including the new images-only form.
- Duplicate image basenames and ambiguous recipe identities are rejected or skipped
  instead of silently assigning a picture. Changed placement previews must be reviewed
  again before applying pictures.

## [1.4.2] - 2026-10-21

### Added
- Kitchen image-only ZIP import: matches existing recipes using the supplied kitchen reference map.
  Updates image slots only; never overwrites instructions, ingredients, allergens or publication.
  Step text must match the reference; unmatched images and unsupported utensils are reported.

### Fixed
- PharData import preview lists filenames without reading every photo into memory.
- Browser import batches process one recipe per request, reducing timeout risk.
- Non-JSON server failures explain that progress must be checked before restarting.

## [1.4.1] - 2026-10-07

### Fixed
- **Pages looking broken after an update** (for example a huge arrow on the sign-in page). The
  tablet app kept serving the previous release's stylesheet: it looked files up without their
  version number, and when it refreshed them it could pick up the server's year-long cached copy.
  Files are now cached by their exact versioned address and refreshed straight from the server.
  Screens stuck on an old stylesheet repair themselves a moment after this update arrives, and
  later updates show correctly on the first load. Pages where someone may be typing (the recipe
  and label editors, import, settings, brands) are never reloaded under them.
- Icons have a built-in size, so they can never fill the screen even if a stylesheet fails to load.

### Changed
- The sign-in page's "Kitchen screens" link is an outlined button, lined up with the form on
  tablets in portrait.

## [1.4.0] - 2026-10-06

### Added
- **Photos on the labels screen**, so the team can find an item at a glance. Each label card
  shows the item's photo; tap the photo or **Print label**. The print window shows it too.
  Photos are for the screen only: **the printed label is unchanged**.
- In **Admin → Labels**, add, replace or remove a photo on any label item. Items linked to a
  recipe use the recipe's photo until they have their own. Items with no photo show their
  initials in the brand colour.
- The example label items come with photos.

### Fixed
- Database upgrades after an update now run one at a time. The first page after an update loads
  several pictures at once, and two requests could try the same upgrade step together; the
  upgrade still finished, but the admin showed an alarming "upgrade stopped" message.

## [1.3.0] - 2026-10-06

### Fixed
- **Image preview.** On landscape tablets and computers the picture was drawn larger than the
  screen, so the bottom was cut off and the arrows sat on top of it. Pictures now always fit.
  Double-tap to zoom did nothing on touch screens; it works now, along with **pinch to zoom**,
  dragging a zoomed picture (it stops at the picture's edges), swiping to the next picture,
  **swiping down to close**, a zoom button, click and scroll-wheel zoom on a computer, and
  `+` `-` `0` keys. The page behind no longer scrolls, a spinner shows while a large photo
  loads, and the next and previous photos load in advance.
- **Sign-in page.** Rebuilt: a clear "Kitchen screens" back link, full-width Sign in button,
  Show/Hide password, a note that staff don't need to sign in, and a compact layout on tablets
  in portrait and on phones.

### Changed
- **Tablet app, the same as the other MustrHQ apps.** Opens full screen with a navy splash and a
  black status bar, like the Rota kiosk, and is named after the kitchen on the home screen. The
  start screen shows an **Install app** card, like MustrHQ Stock: an Install button on Android
  and Chrome, the Safari steps on iPad, and a button to hide it.
- "Recipe admin" is now "Kitchen admin", as it covers labels too.

## [1.2.0] - 2026-10-06

### Added
- **Start screen.** The kitchen address now opens on "What do you need?" with two big choices,
  **Recipes** and **Labels**, plus today's date and time. Staff need no admin account for
  either: only the kitchen code, if one is set.
- **Admin sign in** link on the start screen and in the top bar, for managers. Signed-in
  managers see **Admin** instead.

### Changed
- The **Recipes** and **Labels** tabs are always in the top bar, so staff can switch at any time,
  even before any label items exist.
- The recipe library moved to `recipes.php`. Old links such as `/?brand=3` redirect there.
- The app's Recipes shortcut opens the library; the app itself opens on the start screen.

## [1.1.1] - 2026-10-05

### Fixed
- The recipe library, admin list and small pictures now load thumbnails instead of full photos.
  With 300 recipes the library page drops from about 85 MB of pictures to about 8 MB, which
  matters on kitchen wifi. Thumbnails are made when a photo is saved, and on first view for
  photos added before this release. Full photos are still used for the dish, step by step and
  the image preview.

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
