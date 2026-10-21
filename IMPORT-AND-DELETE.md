# MustrHQ Recipes 1.4.6 — install and use

This is a custom update based on your supplied app. It has not been installed on
your live website. No database migration is needed for these features.

## Install

Open Admin > Updates and backups. Back up the database, app files and photos.
Upload mustrhq-recipes-1.4.6.zip as an APPLICATION UPDATE, review and install it.
This ZIP is not a recipe pack; do not upload it in Import and export.
The updater preserves config.php, uploads/ and backups/.

For manual installation, replace the app files from the ZIP in the current app
folder. Preserve your current config.php, uploads/ and backups/. Copy all new
inc/, admin/ and data/ files too. Do not run install.php again.

## Delete all recipes

Open Admin > Recipes > Delete all recipes. Check the total across ALL brands.
Type DELETE ALL RECIPES and press the permanent-delete button. This includes
hidden recipes and is not limited by your search or brand filters.

Ingredients, steps and unused recipe pictures are removed. Brands and label
records stay; labels are unlinked from deleted recipes. Pictures still used by
labels or remaining recipes stay. Recipes added after deletion starts stay.
Finish or cancel any running imports before deleting recipes.

Keep the page open until it finishes. If interrupted, reopen Delete all recipes
in the same browser session and choose Resume deletion. Completed batches remain
deleted. Restoring deleted recipes requires your backup.

## Import pictures separately

Open Admin > Import and export > Import images only. Choose one ZIP of pictures,
then Check image matches. No recipes.json is required. Recipes must already exist.
JSON files accidentally included in that image ZIP are ignored by this mode.

Review the preview: expand Picture placements for each recipe to see the exact
finished-dish, ingredient or step slot and its filename. Expand Unmatched pictures
to see files that will be skipped. Press Import ticked recipes to attach the
matched pictures. Recipe text, quantities, allergens, notes and publication stay
unchanged; only the matched image slots are replaced.

Your existing Kitchen_All_Images_Small_Part_01.zip through Part_37.zip work here.
Against the supplied 314 drafts, 1,031 images have app slots: 314 dishes, 591 steps
and 126 ingredient references. Fourteen utensil pictures and the unused potato
starch reference have no slots in those drafts and stay separate references.
Your live counts can differ if recipe names, identities or instructions differ.
The supplied kitchen images are AI-generated references requiring chef review.

For other pictures, use filenames from a recipe export:
- brand__code__dish.png: finished dish
- brand__code__step-1.png: step 1
- brand__code__ing-1.png: ingredient 1

JPEG, PNG, WebP and GIF are supported inside the ZIP. Brand and code use the
export's slugs, e.g. katsu-co__k01__dish.png. Numeric slots start at 1 and refer
to the current ingredient/step order. Known kitchen reference step filenames
are matched by their reference instruction, so edited methods are not guessed.

Duplicate filenames cause an error. Duplicate recipe identities and conflicting
pictures for one slot are skipped. Changes after the preview require a new
review. If nothing matches, check the existing recipes and export filenames.

## Checks performed

- PHP syntax: all 40 application files passed. JavaScript syntax passed.
- Bulk deletion: confirmation/count validation, operation token, batch retry,
  interruption/resume, rollback, child cleanup, label/brand/shared-photo retention,
  and preservation of recipes created after confirmation.
- Image matching: full 314-recipe reference fixture; native export filenames;
  explicit image-only mode ignoring recipes.json; duplicate/conflict checks;
  correct destination row IDs; changed-preview detection; recipe-field retention.
- Local HTTP integration using a SQLite fixture: both upload forms rendered,
  two upload chunks accepted, placements previewed, actual PNGs saved in the
  three correct image slots, recipe fields preserved, authentication and CSRF
  failures rejected, deletion resumed and completed, unused PNGs removed.
- Application ZIP CRC validation passed.

Production MySQL and your hosting configuration still need verification after
installation. The source fixes reduce import memory use and request duration;
server logs remain necessary if the live site continues returning 502 errors.


## Import failure diagnostics in 1.4.6

The screenshot's 502 means the website returned a failed request; it is separate
from the chat attachment's earlier missing-file message. The screenshot alone
cannot identify the production cause.

This release caps upload chunks at 512 KB, separates file upload from validation,
and caches parsed preview metadata. It retains image-only imports and bulk delete.
Install this application update, reload Import and export, then try one image ZIP.

If it fails, note the stage and request ID in the message. On Import and export,
choose **Download import diagnostics** and send that JSON report for investigation.
The report contains application/PHP versions, configured limits and bounded request
traces. It excludes uploaded picture bytes, recipe contents, passwords and CSRF
values. Access requires an admin session. PHP memory/time failures are recorded;
a request rejected before PHP starts may have no matching trace, in which case
the host's proxy/PHP logs are required for that time.

Additional verification: local HTTP tested the 512 KB cap, separate validation,
cache creation/cleanup and the diagnostic report. A simulated PHP memory fatal
returned valid JSON with a request ID. Production gateway behavior has not been
verified and this update is not a claim that the live 502 cause is resolved.


## Upload recovery in 1.4.6

Install this release and start an upload once. Uploads started on 1.4.5 or later support
resume; the older 1.4.4 upload did not save the receipts needed for recovery.

Temporary gateway or network failures are retried up to five times. If retries
are exhausted, saved progress stays available. Reopen Import and export and
select the SAME file in the same signed-in browser session, then submit it again.
The system checks committed progress with the server before sending another
chunk. Keep the original file name, size and modification date unchanged.

Pause upload stops after the current request. Submit the selected file again to
continue. Start over clears the browser's upload reference and starts a new upload
on the next submission; unused temporary files expire after 24 hours. Signing out,
losing the session or waiting beyond server cleanup may require a new upload.

After the file is saved, normal validation and the recipe/image placement preview
still apply. The application never retries recipe writes automatically. Image-only
imports and bulk deletion are retained.

The supplied diagnostic report showed six successful 512 KB chunks, PHP execution
of 6–8 ms per chunk, and 2 MB peak PHP memory. The selected file was approximately
1.56 GB (2,983 chunks). It contained no logged failed request or fatal error and no
validation/apply phase. It therefore does not establish why the gateway returned
502. These changes recover from temporary upload interruptions; they do not claim
to repair a persistent hosting or proxy fault. If the same stage keeps failing,
download the updated diagnostics and provide the host's proxy/PHP logs for that time.

Additional checks: PHP upload receipt tests covered duplicate chunks, changed data,
out-of-order requests, ownership, interrupted writes, exact reconstruction, lost
final responses and interrupted finalization. JavaScript upload-flow tests simulated
network failure, a lost successful response followed by 502, bounded retries, resume,
validation retry without resending the file, and pause/resume. Local HTTP integration
verified the upload status endpoint and retained the image import/deletion behavior.


## Import ZIPs uploaded through cPanel — 1.4.6

1. Install mustrhq-recipes-1.4.6.zip using Admin > Updates and backups.
2. Open Admin > Import and export. The **Import images from cPanel** section
   creates its folder automatically and shows its exact server path.
3. In cPanel File Manager, open this application's folder (the folder containing
   version.php and admin/), then open **backups/image-inbox/**.
4. Upload the image ZIP there and wait for cPanel to finish. Keep it compressed.
   Do not replace or rename a ZIP while an import is running. If using a transfer
   client that writes incrementally, upload with a .uploading suffix, then rename
   to .zip when the transfer finishes.
5. Back in the portal, press **Refresh file list**, then **Import images** beside
   the ZIP. That one click checks matches and applies all matched image slots in
   batches, with progress. No recipes file or second browser upload is required.

Recipes must already exist. Matching uses the same supplied kitchen image map
and native export filenames described above. Picture bytes are saved into the
application's normal image storage and linked to the correct recipe fields.
Recipe text, quantities, allergens and publication are not replaced.

The result lists images without a clear supported match; those are skipped.
Utensil pictures have no recipe-image slot. Expand Unmatched images to see files
that were not attached. Your source ZIP stays unchanged in image-inbox/.

Keep the progress page open. If interrupted, refresh this section and click
**Resume import**. The job resumes from completed recipes; already completed
batches are not automatically applied a second time. If recipes changed, use
**Recheck and import** to rebuild the plan. **Import again** intentionally starts
another pass, for example after adding recipes. Jobs persist beyond the browser
session and require an admin sign-in to access.

The folder is inside the app's protected backups area and includes its own deny
rule for Apache/LiteSpeed. The source ZIP is never extracted into public paths.
All imported images go through the existing image-save validation. After a
successful import, you may remove its original ZIP through cPanel to recover
space; the imported website pictures remain in the normal uploads folder.

Additional checks: local HTTP tested folder discovery, permission guards,
traversal/symlink rejection, malformed ZIP rejection, image-only operation with
bundled JSON ignored, correct destination columns, unchanged recipe fields,
lost-response protection, persisted progress, resume, re-import and change guards.
JavaScript flow tests covered one-click completion, duplicate clicks, error/resume
and safe display of unmatched filenames. A synthetic 1.56 GB ZIP with one matched
image and a large ignored member imported under a 32 MB PHP limit at 4 MB peak;
this checks large-archive access, not the throughput of a full production image set.
The update has not been installed on your live website.
