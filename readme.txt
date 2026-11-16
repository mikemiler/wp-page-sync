=== WP Page Sync ===
Contributors: wpmike
Tags: sync, migration, staging, media, generateblocks
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.6.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Syncs pages and posts, including their media files and GenerateBlocks global styles, between two WordPress installs over a signed connection.

== Description ==

WP Page Sync pairs two WordPress installs with a copy-paste connection key and
then syncs pages and posts in both directions between them. Push a post to the
connected site or pull the peer's copy back, one at a time or as a bulk
selection that respects the parent order.

The upload files a post references travel with it. Images, videos, audio and
linked files such as PDFs are transferred with their sha256 checksum and
re-attached on the receiving side, where their URLs are rewritten to that site's
own domain and uploads path. An image used in several sizes, or a large photo
that WordPress stores as a "-scaled" copy next to its original, is transferred
once and every size of it points at the receiving site's copy. The image's
title, alt text, caption and description travel along. Files are sent in pieces
of 1 MB, so the receiving server's upload limit does not cap the file size; a
single file may be up to 256 MB, and a larger one blocks the sync in the
pre-flight check. Two kinds of reference stay behind. A file whose name or file
type is outside the transfer whitelist (special characters in the file name, an
extension the plugin does not carry) is left on the source site and its URL
keeps pointing there; the sync dialog names it as a pre-flight warning
beforehand and the verifier reports the leftover URL afterwards. A reference
written without a host (`/wp-content/uploads/…` instead of a full URL) is not
recognised at all, so its file is neither transferred nor reported.

Along with the content, a post keeps its publish date, its password, whether
comments and pingbacks are open, and its categories and tags. Everything a sync
does not carry (the author, for example) stays as it is on the receiving post. They replace the post's categories and tags on the receiving site, so a
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

Both copies are compared by a content hash over title, path, status, content,
excerpt, the featured image file, the password and the comment and pingback
settings (the date, the terms, the image texts and the attachment IDs in media
blocks are not part of it), so the plugin knows whether a post is in sync, changed on one side,
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
5. If one site is protected by HTTP Basic Auth on its web server (the browser
   asks for a username and password before showing the site, common on staging
   sites), create the connection key on that protected site and connect from the other
   one: enter the Basic Auth username and password together with the key under
   **Connect to a site**. They are stored with that connection and sent with
   every request to the protected site, never in a request body. To add,
   change or remove them later, edit the connection's Basic Auth under
   Connected sites (button **Basic Auth** in its row); saving pings the site
   right away. The protected site must run 1.4.0 or later as well. If both
   sites are protected, add the other site's credentials afterwards the same
   way on the site where the key was created.
6. Syncing SVG uploads additionally requires the safe-svg plugin on the
   receiving site, because its bundled sanitizer is mandatory before an SVG is
   written. Without it, SVG imports are rejected; all other file types are
   unaffected.

== Changelog ==

= 1.6.0 =
* New: a bulk push asks in the plugin's own dialog instead of a browser prompt. It says how many posts will be pushed (parents first) and lists the selected posts whose copy on the peer differs, with their status. One option overwrites all of these at once; the copy on the peer is saved as a post revision first, as with a single forced push. Without the option they are skipped and listed as before. Only the posts listed in the dialog can be overwritten: a post that became a conflict after the list was loaded is still skipped.

= 1.5.0 =
* New: edit the HTTP Basic Auth of an existing connection. Each row under Connected sites shows whether Basic Auth is on (with its username) and has a "Basic Auth" button: fill in both fields to set or replace the credentials, leave both empty to remove them. Saving pings the site right away, so a wrong password shows at once. The password is never shown again. Disconnecting and connecting again is no longer needed, for example when a site gets password protection after it was paired.
* Improvement: two sites that are both behind HTTP Basic Auth can now be paired: connect with the protected site's credentials as before, then add the other site's credentials under Connected sites on the site where the key was created.
* Fix: a URL on another connected site's address no longer survives a sync to a site that is not connected to that one. Example: a local site connected to DEV and LIVE pushes a page with a LIVE video and a LIVE page link to DEV. Before, the video file was transferred but the page kept pointing at LIVE, and the link stayed absolute to LIVE. Now such URLs are handled like the sending site's own: files point at the receiving site's copy, page links become relative.
* New: the "Posts on this site" list in the Sync tab is sorted by modification date, newest first, and can be sorted by title, path, modification date or status with a click on the column header (a second click reverses the order). Titles and paths sort naturally ("page-2" before "page-10"), statuses in the order of the status filter. The chosen order stays while the list is reloaded or another site or post type is selected, until the page is reloaded.

= 1.4.0 =
* New: connect to a site protected by HTTP Basic Auth on its web server, such as a password-protected staging site. The "Connect to a site" form takes an optional Basic Auth username and password, which are stored with the connection and sent with every request to that site. Create the connection key on the protected site and connect from the other one. To change the credentials, disconnect and connect again. Pairing two sites that are both behind HTTP Basic Auth is not supported.
* Improvement: a login prompt from the other site's web server (HTTP 401 without a message from the plugin) now says that the site is protected by HTTP Basic Auth instead of only "Peer responded with HTTP 401".
* Note: update the protected site to 1.4.0 as well. If it has an application password, WordPress would otherwise take the web server's login for an application password and reject every sync request with "Unauthorized". 1.4.0 exempts the plugin's own signed requests from that check; they never used application passwords.

= 1.3.0 =
* Fix: an image used at a size such as "large" now arrives as well when WordPress stores it as a "-scaled" copy (photos wider or taller than 2560 pixels). Before, the size URL kept pointing at the sending site. Every size of an image, its untouched original and size files no longer listed by WordPress now point at the receiving site's copy, and the image is transferred and created once even when the post uses it in several sizes and as featured image.
* Fix: a page whose address equals the name of one of its images (a page "pricing" with an image "pricing.png") no longer lands at "pricing-2" and duplicates with every sync. The image entry moves aside and the page keeps its address. When a real page or post holds the address, the sync says so instead.
* Fix: updating an existing post no longer resets what the sync does not carry: the author, and for a sending site before 1.3.0 also the comment and pingback settings, stay as they are on the receiving site.
* New: the post password and the comment and pingback settings are synced, so a password-protected page stays protected on the connected site. They are part of the content hash.
* New: files are sent in pieces of 1 MB and pulled straight to disk, so videos and large PDFs up to 256 MB per file transfer independent of the receiving server's upload limit. A larger file is a blocking problem in the sync preview. A request the receiving server cuts short now reports that its size limit is too small instead of "Unauthorized".
* New: audio (MP3, M4A, OGG, WAV), AVIF images and MOV, M4V and OGV videos travel with the post. File names with letters of any language (umlauts, accents, other scripts) and "@" (for example "logo@2x.png") are transferred too.
* New: the title, alt text, caption and description of a synced image or file are applied to the receiving site's copy on every sync.
* Improvement: warnings of a sync are no longer lost. A bulk push lists them per post, the editor's one-click pull shows them and reloads only after a click, and the log stores them with each entry.
* Note: update both connected sites. A site before 1.3.0 cannot receive a pushed file: the push stops before anything is written and asks for the update. The content hash changes once with this update; posts whose copies are otherwise equal show as "In sync (adopted)" once both sites run 1.3.0.
* Note: posts whose password, comment or pingback settings differ between the two sites show as "Diverged" after the update, because these settings are now part of the content hash. This is common for posts re-synced under 1.2.0, which closed comments on the receiving post. Push each of them once with the force option; a bulk push skips them as conflicts.
* Note: an image that 1.2.0 synced only in a size such as "large" (and that WordPress stores as a "-scaled" copy) is transferred once more on the first 1.3.0 sync and stored next to the old copy as "…-scaled-wpps<code>.jpg". The old copy is not deleted and nothing is overwritten; it is no longer used by the post.

= 1.2.0 =
* New: a sync now carries the post's categories and tags and replaces the receiving post's categories and tags with them. They are matched by slug on the receiving site, missing ones are created there at the top level (the category hierarchy is not carried), and existing ones are never renamed. A sending site on an older version sends no terms, so the receiving site leaves them unchanged.
* New: a sync now carries the publish date. Before, an update of an existing post reset its date to the time of the import. A future date on a published post makes it a scheduled post on the receiving site, as in WordPress itself.
* New: WebVTT subtitle files (`.vtt`) travel with the post, e.g. the track of a video block. Update both connected sites: a site on an older version rejects them, so a push to it or a pull by it of a post with a subtitle track fails.
* Improvement: media blocks (image, video, audio, file, cover, media and text, GenerateBlocks media and image) and `wp-image-N` classes now point at the receiving site's own attachment IDs instead of the sending site's. Not remapped: the legacy `core/gallery` ID list, the `[gallery]` shortcode, the GenerateBlocks v1 background image ID and attachment-page links.
* Improvement: the featured image file is part of the content hash, so a post whose only change is a new featured image now shows as changed. The date, the terms and the attachment IDs in media blocks are not part of the hash, so a difference in them alone does not show a post as changed.
* Note: the content hash of every post changes once with this update. A post whose two copies are otherwise equal shows as "In sync (adopted)" afterwards, provided both sites run 1.2.0. A post changed on one side meanwhile shows as "Diverged" once and needs the force option. A sync never deletes uploads: a file the post no longer references stays in the receiving site's media library.

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
