# WP Page Sync

WordPress plugin that pairs two installs and syncs pages and posts between them
in both directions, including the upload files they reference.

## What it does

- Push and pull pages and posts between two paired sites, individually or as a
  bulk selection that syncs parents before their children.
- Transfers the referenced upload files with the post: images and linked files
  such as PDFs, checksum-verified and re-attached on the receiving side with
  their URLs rewritten to that site's domain and uploads path.
- Syncs GenerateBlocks Pro global classes and the GeneratePress color palette.
  Classes that exist only on the target are never deleted.
- Detects conflicts via content hashes: two copies that differ are reported
  instead of overwritten, and overwriting needs an explicit confirmation.
- Verifies a site for missing upload files, URLs pointing at another
  environment, broken internal links and undefined global classes.

## Limits

- **Not every upload reference travels.** A file whose name or file type is
  outside the transfer whitelist (an umlaut in the file name, an extension the
  plugin does not carry) is excluded from the transfer and its URL deliberately
  keeps pointing at the source site. The sync dialog names it as a pre-flight
  warning before the sync and the verifier reports the leftover URL after it.
  A reference written without a host (`/wp-content/uploads/…` instead of a full
  URL) is not extracted at all, so its file is neither transferred nor reported.
- **"Never deleted" covers global classes only.** The GeneratePress color
  palette is replaced as a whole rather than merged, so colors that exist only
  on the target are lost.
- **The content hash covers title, path, status, content and excerpt.** Post
  meta is not part of it: two copies can report as in sync while their custom
  fields differ, and meta deleted on the source is not deleted on the target by
  an import.
- **Single site only.** There is no multisite or network handling.
- **No deletion propagation.** Deleting a page, post or media file locally never
  deletes anything on the connected site.

## Pairing

1. Install and activate the plugin on **both** sites.
2. On site A: *WP Page Sync → Connections → Create connection key*. The key is
   shown once, usable once, and expires after 60 minutes.
3. On site B: paste the key under *Connect to a site* and click **Connect**.

Then enable **Allow incoming** on whichever site should be allowed to receive
pushes.

## How updates work

The plugin is not on wordpress.org. It ships the
[Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker)
(PUC) library, which hooks into the normal WordPress update flow:

1. A `vX.Y.Z` tag is pushed to this repository.
2. The `Release` GitHub Action verifies that the tag, the plugin header version
   and `WPPS_VERSION` all match, builds a clean ZIP and attaches it to a GitHub
   Release.
3. PUC on each install polls the Releases API, compares the release against the
   installed `WPPS_VERSION`, and offers the update under *Plugins* and
   *Dashboard → Updates*. "View details" is fed by `readme.txt`.

Release steps are documented in [RELEASING.md](RELEASING.md).

## Requirements

- WordPress 6.0 or newer
- PHP 7.4 or newer
- GenerateBlocks Pro / GeneratePress on both sites, for the global styles sync
  only. Page and post syncing does not require them.
- The safe-svg plugin on the receiving site, if SVG uploads should sync. Its
  bundled sanitizer is mandatory before an SVG is written; without it SVG
  imports are rejected. Other file types are unaffected.

## Security

- Site-to-site traffic runs over the plugin's own REST namespace
  (`wp-page-sync/v1`), authenticated with HMAC-SHA256 request signatures. No
  application passwords are issued and no WordPress core REST route is exposed
  for syncing.
- Each signature is bound to the receiving site, so a signed request cannot be
  reflected back in the other direction.
- The shared secret is generated per pairing and stored per site as a
  non-autoloaded option. Connection keys are single-use and expire after 60
  minutes.
- **Allow incoming** governs whether a peer may write to a site. It defaults to
  on only for local hosts (`*.test`, `*.local`, `localhost`, `127.0.0.1`) and
  must be enabled deliberately everywhere else.
- Plain HTTP peers are rejected outside of those local hosts.

## License

GPL-2.0-or-later
