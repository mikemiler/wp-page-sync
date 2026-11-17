# WP Page Sync

WordPress plugin that pairs two installs and syncs pages and posts between them
in both directions, including the upload files they reference.

## What it does

- Push and pull pages and posts between two paired sites, individually or as a
  bulk selection that syncs parents before their children. A bulk push can
  overwrite all conflicting posts at once from its confirmation dialog.
- Transfers the referenced upload files with the post: images, videos, audio
  and linked files such as PDFs, checksum-verified and re-attached on the
  receiving side with their URLs rewritten to that site's domain and uploads
  path. Every size of an image (including WordPress' "-scaled" copy of large
  photos and its original) maps onto one copy on the receiving side, together
  with the image's title, alt text, caption and description. Files travel in
  1 MB pieces, up to 256 MB per file.
- Carries the publish date, the post password, the comment and pingback
  settings and the categories and tags with the post. Fields it does not
  carry, such as the author, stay as they are on the receiving post. Terms
  replace the receiving post's categories and tags, so a category or tag that
  exists only there is removed from the post. Terms are matched by slug;
  missing ones are created on the receiving site, existing ones are never
  renamed. A created category sits at the top level: the hierarchy is not
  carried.
- Points media blocks (image, video, audio, file, cover, media and text,
  GenerateBlocks media and image) and `wp-image-N` classes at the receiving
  site's own attachment IDs.
- Syncs GenerateBlocks Pro global classes and the GeneratePress color palette.
  Classes that exist only on the target are never deleted.
- Detects conflicts via content hashes: two copies that differ are reported
  instead of overwritten, and overwriting needs an explicit confirmation.
- Warns in the editor when the connected site holds a newer copy of the post
  being edited, and offers to pull it and reload. When both sides changed, the
  notice says so and sends the decision to the Sync tab instead of offering a
  one-click pull.
- Keeps the overwritten text: before an import replaces an existing post, its
  current title, content and excerpt are stored as a post revision on the
  receiving site. A revision covers no more than those three fields, and none
  at all when that site has revisions switched off or the post type does not
  support revisions.
- Verifies a site for missing upload files, URLs pointing at another
  environment, broken internal links and undefined global classes.

## Limits

- **Not every upload reference travels.** A file whose name or file type is
  outside the transfer whitelist (special characters in the file name, an
  extension the plugin does not carry) or larger than 256 MB is excluded from the transfer and its URL deliberately
  keeps pointing at the source site. The sync dialog names it as a pre-flight
  warning before the sync and the verifier reports the leftover URL after it.
  A reference written without a host (`/wp-content/uploads/…` instead of a full
  URL) is not extracted at all, so its file is neither transferred nor reported.
- **"Never deleted" covers global classes only.** The GeneratePress color
  palette is replaced as a whole rather than merged, so colors that exist only
  on the target are lost.
- **The content hash covers title, path, status, content, excerpt, the
  featured image file, the password and the comment and pingback settings.**
  Post meta, the date, the terms, image texts and the attachment IDs in media
  blocks are not part of it: two copies can report as in sync while their custom fields differ, and
  meta deleted on the source is not deleted on the target by an import.
- **Not every attachment ID is remapped.** The legacy `core/gallery` ID list,
  the `[gallery]` shortcode, the GenerateBlocks v1 background image ID
  (`bgImage.id`) and links to attachment pages keep the sending site's IDs.
- **Single site only.** There is no multisite or network handling.
- **No deletion propagation.** Deleting a page, post or media file locally never
  deletes anything on the connected site. A file a post stops referencing (for
  example after switching to a new versioned file name) also stays in the
  receiving site's media library.
- **Update both connected sites.** Sync runs in both directions. A site before
  1.3.0 cannot receive a pushed file (files travel in 1 MB pieces since
  1.3.0): such a push stops before anything is written and asks for the
  update. Mixed versions also compute different content hashes, so posts show
  as changed until both sites run 1.3.0.

## Pairing

1. Install and activate the plugin on **both** sites.
2. On site A: *WP Page Sync → Connections → Create connection key*. The key is
   shown once, usable once, and expires after 60 minutes.
3. On site B: paste the key under *Connect to a site* and click **Connect**.

Then enable **Allow incoming** on whichever site should be allowed to receive
pushes.

**Site behind HTTP Basic Auth** (a staging site whose web server asks for a
username and password): create the connection key on the protected site, and enter its
Basic Auth username and password together with the key under *Connect to a
site* on the other one. Both sites need 1.4.0 or newer. To add, change or
remove the credentials later, edit the connection's Basic Auth under
*Connected sites* (1.5.0 or newer); saving pings the site right away. If both
sites are protected, add the other site's credentials afterwards the same way
on the site where the key was created.

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
- Optional HTTP Basic Auth credentials of a peer's web server are stored in
  that peer's record (same protection as its secret), sent only to that peer
  as an `Authorization` header and never shown in the admin (only the
  username is). On the receiving site, requests that carry the plugin's
  signature and target its namespace are exempt from WordPress's application
  password check, which would otherwise mistake the web server's login for an
  application password.

## License

GPL-2.0-or-later
