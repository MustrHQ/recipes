# cPanel image import

The portal discovers regular ZIP files directly under backups/image-inbox/.
Opening Import and export creates this protected folder and displays its full
path for cPanel File Manager. Source archives are read without copying or moving.
Only explicitly requested, authenticated POST operations can start image updates.

Private job records are stored separately in backups/image-import-jobs/, one
record per source name. They hold an archive fingerprint, recipe ID list, expected
placement signatures, progress, counters and the shared image cache. A file lock
serializes a job; atomic metadata replacement preserves the last recorded progress.
A random run token and expected position reject stale or repeated batch actions.

Each batch applies one existing recipe using the common image-only save function.
Source metadata and current placement signatures must still match. Source ZIPs
are never modified by a job or removed by temporary browser-upload cleanup.

See IMPORT-AND-DELETE.md for the installation and cPanel workflow.
