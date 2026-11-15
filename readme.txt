=== WP Page Sync ===
Contributors: wpmike
Tags: sync, migration, staging, media, generateblocks
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Syncs pages and posts, including their media files and GenerateBlocks global styles, between two WordPress installs over a signed connection.

== Description ==

WP Page Sync pairs two WordPress installs with a copy-paste connection key and
then syncs pages and posts in both directions between them. Push a post to the
connected site or pull the peer's copy back, one at a time or as a bulk
selection that respects the parent order.

The upload files a post references travel with it. Images and linked files such
as PDFs are transferred with their sha256 checksum and re-attached on the
receiving side, where their URLs are rewritten to that site's own domain and
uploads path. Two kinds of reference stay behind. A file whose name or file type
is outside the transfer whitelist (an umlaut in the file name, an extension the
plugin does not carry) is left on the source site and its URL keeps pointing
there; the sync dialog names it as a pre-flight warning beforehand and the
verifier reports the leftover URL afterwards. A reference written without a host
(`/wp-content/uploads/…` instead of a full URL) is not recognised at all, so its
file is neither transferred nor reported.

Along with the content, a post keeps its publish date and its categories and
tags. They replace the post's categories and tags on the receiving site, so a
category or tag that exists only there is removed from the post. Terms are
matched by slug; a missing one is created, an existing one is never renamed. A
created category always sits at the top level: the category hierarchy is not
carried.

Media blocks (image, video, audio, file, cover, media and text, GenerateBlocks
media and image) and `wp-image-N` classes are pointed at the receiving site's
own attachment IDs. Other attachment ID references keep the sending site's IDs:
the legacy `core/gallery` ID list, the `[gallery]` shortcode, the GenerateBlocks
v1 background image ID (`bgImage.id`) and links to attachment pages.

Uploads are never deleted by a sync: when a post references a new file, the
file it used before stays in the receiving site's media library.

GenerateBlocks Pro global classes and the GeneratePress color palette can be
pushed or pulled separately. Classes that exist only on the target are never
deleted. The color palette is the exception to that rule: it is replaced as a
whole instead of merged, so colors that exist only on the target are lost.

Both copies are compared by a content hash over title, path, status, content and
excerpt (the date, the terms and the attachment IDs in media blocks are not
part of it), so the plugin knows whether a post is in sync, changed on one side,
or changed on both. A conflict is reported instead of silently overwritten, and
overwriting requires an explicit confirmation. Post meta is not part of that
hash: two copies can be reported as in sync while their custom fields differ,
and meta deleted on the source is not deleted on the target by an import.

Opening an existing page or post for editing checks the connected site in the
background. If the copy there is newer, a notice offers to pull it and reload
the editor; if both sides changed since the last sync, the notice says so and
points to the Sync tab rather than offering a one-click pull that would discard
local work. Before an import overwrites an existing post, its current title,
content and excerpt are written to a post revision, so that much stays
recoverable even on a page that had no revisions before. A revision covers no
more than those three fields: post meta, the featured image and the page
template are written after the post itself and cannot be restored from it, so
the custom fields mentioned above stay outside that safety net too. And nothing
is saved at all when the receiving site has revisions switched off or the post
type does not support them.

Two limits are worth knowing before installing. The plugin is built for single
WordPress installs and has no multisite or network handling. And it never
propagates deletions: deleting a page, post or media file locally leaves the
copy on the connected site untouched.

The built-in verifier scans this site or the connected one for missing upload
files, URLs still pointing at another environment, broken internal links and
global classes that are used but not defined.

Site-to-site traffic runs over the plugin's own REST namespace
(`wp-page-sync/v1`), authenticated with HMAC-SHA256 request signatures. No
application passwords are issued and no WordPress core REST route is exposed for
syncing. Each direction is signed separately, and the write routes additionally
require the receiving site to have "Allow incoming" enabled for that peer.

Updates are delivered via GitHub, not wordpress.org.

== Installation ==

1. Upload the `wp-page-sync` folder to `/wp-content/plugins/` on **both** sites
   you want to connect, and activate the plugin on each of them.
2. On site A, open *WP Page Sync → Connections* and click **Create connection
   key**. The key is shown once, can be used once, and expires after 60 minutes.
   Transfer it over a secure channel: anyone holding it can claim the pairing.
3. On site B, open *WP Page Sync → Connections*, paste the key into **Connect to
   a site** and click **Connect**. Both sites now list each other under
   *Connected sites*.
4. Enable **Allow incoming** on whichever site should be allowed to receive
   pushes. It controls whether a peer may write to that site and defaults to on
   only for local hosts (`*.test`, `*.local`, `localhost`, `127.0.0.1`); on dev
   and production sites, turn it on deliberately per peer.
5. Syncing SVG uploads additionally requires the safe-svg plugin on the
   receiving site, because its bundled sanitizer is mandatory before an SVG is
   written. Without it, SVG imports are rejected; all other file types are
   unaffected.

== Changelog ==

= 1.2.0 =
* New: a sync now carries the post's categories and tags and replaces the receiving post's categories and tags with them. They are matched by slug on the receiving site, missing ones are created there at the top level (the category hierarchy is not carried), and existing ones are never renamed. A sending site on an older version sends no terms, so the receiving site leaves them unchanged.
* New: a sync now carries the publish date. Before, an update of an existing post reset its date to the time of the import. A future date on a published post makes it a scheduled post on the receiving site, as in WordPress itself.
* New: WebVTT subtitle files (`.vtt`) travel with the post, e.g. the track of a video block. Update both connected sites: a site on an older version rejects them, so a push to it or a pull by it of a post with a subtitle track fails.
* Improvement: media blocks (image, video, audio, file, cover, media and text, GenerateBlocks media and image) and `wp-image-N` classes now point at the receiving site's own attachment IDs instead of the sending site's. Not remapped: the legacy `core/gallery` ID list, the `[gallery]` shortcode, the GenerateBlocks v1 background image ID and attachment-page links.
* Note: the date, the terms and the attachment IDs in media blocks are not part of the content hash, so a difference in them alone does not show a post as changed. The hash of a post with media blocks changes once with this update; a post whose two copies are otherwise equal shows as "In sync (adopted)" afterwards, provided both sites run 1.2.0. A sync never deletes uploads: a file the post no longer references stays in the receiving site's media library.

= 1.1.0 =
* New: opening an existing page or post for editing now checks the connected site in the background. If the copy there is newer, a notice offers to pull it and reload the editor in one click; if both sides changed since the last sync, the notice says so and points to the Sync tab instead of offering a pull that would discard local work.
* New: the post list in the Sync tab can be filtered by sync status and searched by title or path. The status dropdown offers only the statuses the loaded list actually contains, and a bulk push acts on the visible selection only.
* Improvement: an import no longer replaces an existing post without a trace. The target's current title, content and excerpt are written to a post revision first, so they stay recoverable even on a page that never had a revision before. A revision covers no more than those three fields, and none at all on a site with revisions switched off or a post type that does not support them.
* Improvement: while a push or pull is running, the preview dialog locks itself. Both buttons and the force option are disabled and a click on the backdrop no longer closes it, so the same sync cannot be started twice or dismissed mid-write. Its progress and result message moved below the buttons, where a full error message has the room to read as a block.
* Improvement: the lock now works in both directions. A bulk push keeps the preview dialog from confirming a single push or pull to the same site, and says so in the dialog instead of leaving a dead button. Opening a preview to look at a post stays possible while the bulk run finishes.
* Improvement: the push and pull preview states the media situation in plain words. "Media: 4 referenced, all already on the target." replaces the old pair of counters, which showed "To transfer: 0 (0 B)" and read as if nothing at all would happen.

= 1.0.0 =
* New: pair two WordPress installs with a single-use, expiring connection key that is created on one site and pasted on the other.
* New: push and pull pages and posts in both directions, individually or as a bulk selection that syncs parents before their children.
* New: the upload files a post references travel with it, images and linked files such as PDFs alike, verified by sha256 checksum and re-attached with their URLs rewritten to the receiving site. Files outside the transfer whitelist are reported as a pre-flight warning and stay on the source site; host-less `/wp-content/uploads/` references are not picked up.
* New: push or pull GenerateBlocks Pro global classes and the GeneratePress color palette; classes that exist only on the target are never deleted, while the palette is replaced as a whole.
* New: conflict detection via content hashes over title, path, status, content and excerpt, so two copies that differ are reported instead of silently overwritten and can only be overwritten after an explicit confirmation. Post meta is outside the hash.
* New: verifier that scans this site or the connected one for missing upload files, URLs pointing at another environment, broken internal links and undefined global classes.
* New: an operations log listing the most recent sync actions and their outcome.
* Security: all site-to-site traffic runs over the plugin's own REST namespace with HMAC-SHA256 signed requests, bound per direction, without application passwords or exposed core REST routes. Incoming writes are off by default on every non-local host.
* GitHub based automatic updates via the Plugin Update Checker library.
