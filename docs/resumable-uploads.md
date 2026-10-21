# Upload resume

See IMPORT-AND-DELETE.md in the release ZIP for user instructions.

The server stores chunk receipts in the private backups folder, bound to the
signed-in browser session. Each upload has a random reference, fixed filename,
size, mode and chunk size. Chunk byte lengths and order must agree with that record.
File locking serializes mutations. Receipt files are atomically replaced after
writing each chunk. Retry verification reads only that chunk's saved byte range.
An uncommitted partial tail is discarded before rewriting its expected chunk.
Completed files are finalized by rename, without concatenating a second full copy.
The final receipt can be recovered after a lost response or interrupted rename.

Only upload/status calls retry transient transport failures. Recipe application
requires its normal request and is not automatically replayed. Existing files
are never accepted using another signed-in session's upload receipt.
