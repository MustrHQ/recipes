# MustrHQ Recipes

[![Latest release](https://img.shields.io/github/v/release/MustrHQ/recipes?label=release&color=01216C)](https://github.com/MustrHQ/recipes/releases/latest)
[![Checks](https://github.com/MustrHQ/recipes/actions/workflows/ci.yml/badge.svg)](https://github.com/MustrHQ/recipes/actions/workflows/ci.yml)
[![Licence: AGPL-3.0](https://img.shields.io/badge/licence-AGPL--3.0-blue.svg)](LICENSE)
![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-777bb4.svg)
![MySQL](https://img.shields.io/badge/MySQL-MariaDB-4479a1.svg)

Recipe screens for kitchens: what you need, photos, and step-by-step guides on a tablet above
the bench. Plain PHP and MySQL, with no framework, no Composer and no build step. Upload the
folder to cPanel shared hosting and it runs.

Part of the [MustrHQ](https://mustrhq.app) open-source set, alongside
[MustrHQ Rota](https://github.com/MustrHQ/rota) and [MustrHQ Stock](https://github.com/MustrHQ/stock).

<p align="center">
  <img src="docs/screenshots/03-step-by-step.png" alt="Step-by-step mode on a kitchen tablet: step 4 of 6, slice the chicken on the red board" width="760">
</p>

**Quick start:** download the latest zip from [Releases](https://github.com/MustrHQ/recipes/releases/latest),
upload it to your hosting, open the folder in your browser and follow the installer.
Full details under [Install](#install).

## What it does

Built for delivery-only ("dark") kitchens running several brands from one team, and just as
useful in cafés, bakeries and restaurants. Every cook builds every dish the same way, including
the new starter on their first shift.

**On the kitchen tablet**

| Screen | What it is |
|---|---|
| Recipe library | Every dish as a photo tile, grouped by brand. Search by name or ticket code as you type. |
| Recipe page | The finished photo, timings, allergens and diet tags, a safety note, **What you need** and the method. |
| What you need | Every ingredient with its photo and amount. Batch buttons (½× to 4×) scale the amounts; tap to tick an ingredient off. |
| Step by step | One step per screen with a big photo and big text. Back and Next, swipe, or the keyboard. Step timers with an alarm. Ends on the finished photo: "Check it matches the photo". |
| Image preview | Tap any photo: full screen, swipe through every picture, double-tap to zoom. |

The screens install as an app on tablets and phones, keep the screen awake while cooking, cache
photos for weak kitchen wifi, and can sit behind an optional kitchen code.

**In the admin panel** (`/admin/`)

| Page | What it is |
|---|---|
| Recipes | Add and edit recipes, with a photo for the dish, each ingredient and each step. Counts for live, hidden and missing photos. Copy, hide, delete. |
| Brands | Names, colours and order. |
| Import and export | Recipe packs in and out, with a preview before anything changes, and a ChatGPT brief that writes packs for you. |
| Updates and backups | Install a new release from its zip, with automatic backups and one-click restore. |
| Settings | Kitchen name, kitchen code, admins, your password. |

<table>
  <tr>
    <td><img src="docs/screenshots/01-library.png" alt="Recipe library with photo tiles and search"></td>
    <td><img src="docs/screenshots/02-recipe.png" alt="Recipe page with allergens, ingredients with photos and method"></td>
  </tr>
  <tr>
    <td><img src="docs/screenshots/04-step-timer.png" alt="Step by step with a running timer"></td>
    <td><img src="docs/screenshots/05-image-preview.png" alt="Full-screen image preview"></td>
  </tr>
</table>

<p align="center">
  <img src="docs/screenshots/06-phone.png" alt="The library, a recipe and step by step on a phone" width="760">
</p>

## Install

1. In cPanel → **MySQL Databases**, create a database and a user, and add the user to the
   database with **All Privileges**.
2. Download `mustrhq-recipes-x.y.z.zip` from [Releases](https://github.com/MustrHQ/recipes/releases/latest)
   and upload it, for example to `public_html/recipes`. Unzip it there.
3. Open `https://your-domain/recipes/` in a browser. The installer opens.
4. **Server check:** PHP version, PDO MySQL, and whether the `uploads` and `backups` folders are
   writable (set them to 755 if not).
5. Enter the database details, your kitchen name, your admin account and, if you want one, a
   kitchen code. Tick **Add two example recipes** to see it working straight away.
6. Press **Delete install.php and sign in**.

The installer writes `config.php` for you. If your host does not allow that, it shows you the
exact file to create.

**Requirements:** PHP 8.1 or newer with PDO MySQL, and MySQL 5.7+ or MariaDB 10.3+. Optional:
the GD extension (resizes big photos on the server) and the zip extension (import, export and
one-click updates). Most cPanel hosts have all of these.

### On the kitchen tablets

Open the main address on each tablet, enter the kitchen code if you set one, then use the
browser's **Add to home screen** or **Install app**. It opens full screen like an app.

## Writing recipes

Each recipe has:

- a **code** that matches the ticket or menu number, so staff can search for it,
- a **finished photo**, shown on the recipe and at the end of step by step,
- **ingredients** for one batch, as prepped items from the fridge. Keep amounts as plain
  numbers so the kitchen can scale them,
- **steps**, one clear action each, with a photo where seeing it helps, a timer where the cook
  has to wait, and a tip for anything that could go wrong,
- the **allergens** the finished dish contains, from the 14 UK allergens, and diet tags.

Photos can be any size. The browser shrinks them to 1800 px before uploading, so they fit
shared-hosting upload limits and load quickly on the tablets.

## Getting recipes from ChatGPT

**Admin → Import and export** builds a whole menu in one go:

1. **Download the brief.** It includes your brands, the allergen keys, a house photo style and
   the exact file format. Optionally paste the menu first.
2. Attach the brief to a new ChatGPT chat and say *"Follow the attached brief"*. It replies with
   a recipe pack: `recipes.json` plus the photos it names.
3. **Import the pack.** You see every recipe (new or replacing an existing one) and any problems,
   such as missing photos or an allergen it did not recognise, before anything is saved.

To improve recipes you already have, export them, attach the export and the brief to ChatGPT, and
ask it to improve them. Codes stay the same, so the import updates them instead of making copies.

**Always check the allergens yourself before a recipe goes live.** The format is documented in
[docs/recipe-pack.md](docs/recipe-pack.md).

<p align="center">
  <img src="docs/screenshots/11-import-preview.png" alt="Import preview listing new and replaced recipes with warnings" width="760">
</p>

## Updating

**Admin → Updates and backups → upload the new release zip.** You see what will change, then:

1. the current app files and the database are backed up,
2. the new files are written (if anything fails, the old version is put straight back),
3. any database upgrades run.

Your recipes, photos and `config.php` are never touched. **Restore** puts back any earlier app
files backup. **Back up now** saves the database, the photos and the app files; download them now
and then and keep them off the server.

You can also update by uploading the new files over the old ones in File Manager. The database
upgrades itself on the next page load.

## Files

```
index.php            recipe library
recipe.php           recipe page
cook.php             step-by-step mode
unlock.php           kitchen code screen
install.php          installer (deleted after use)
manifest.php, sw.php installable app and photo caching
offline.html         shown when the tablet loses the internet
version.php          the release number, read by the updater
admin/               admin panel
assets/              CSS, JavaScript, Archivo font (SIL OFL), icons, logo
inc/                 shared code, database schema and upgrades, demo recipes
uploads/             recipe photos (scripts are blocked here)
backups/             update and manual backups (not served)
docs/                recipe pack format; screenshots for this page
tests/smoke.sh       installs into a fresh database and loads every screen
```

## Security

- Passwords use PHP's `password_hash`. Sign-in and kitchen-code guessing is limited per address.
- CSRF tokens on every form, prepared statements for all SQL, escaped output everywhere.
- A strict Content Security Policy: no inline scripts and nothing loaded from other sites.
  Fonts and icons are bundled; the app never phones home.
- Photos are checked as real images and renamed; `uploads/` refuses to run scripts. `config.php`,
  `inc/` and `backups/` are not served.
- Updates are read as data, checked for unsafe paths and backed up before anything is written.

Please serve it over HTTPS (free with AutoSSL in cPanel). Report security problems privately:
see [SECURITY.md](SECURITY.md).

## Design

Navy `#01216C` and amber, the same as the rest of MustrHQ. Archivo, a variable typeface with a
width axis: condensed weights for the big step numbers, normal width for reading. Touch targets
of at least 44 px, text sized to read at arm's length, and the step photo given most of the
screen, because that is what a cook glances at.

## Contributing

Bug reports, ideas and pull requests are welcome. Read [CONTRIBUTING.md](CONTRIBUTING.md) first:
the short version is plain PHP, no build step, and test on a tablet.

## Licence

[GNU AGPL-3.0](LICENSE). You can run it, change it and share it. If you run a changed version
for other people over a network, you must share your changes under the same licence.

The Archivo typeface is by the Archivo Project Authors under the
[SIL Open Font License](assets/fonts/OFL.txt).
