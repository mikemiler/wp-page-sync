=== WP Page Sync ===
Contributors: wpmike
Tags: sync, migration, staging, media, generateblocks
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.0
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

GenerateBlocks Pro global classes and the GeneratePress color palette can be
pushed or pulled separately. Classes that exist only on the target are never
deleted. The color palette is the exception to that rule: it is replaced as a
whole instead of merged, so colors that exist only on the target are lost.

Both copies are compared by a content hash over title, path, status, content and
excerpt, so the plugin knows whether a post is in sync, changed on one side, or
changed on both. A conflict is reported instead of silently overwritten, and
overwriting requires an explicit confirmation. Post meta is not part of that
hash: two copies can be reported as in sync while their custom fields differ,
and meta deleted on the source is not deleted on the target by an import.

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
