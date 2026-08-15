# Releasing / Updates

This plugin is **not** distributed through wordpress.org. Updates are delivered
via GitHub Releases and picked up automatically on every install by the bundled
[Plugin Update Checker](lib/plugin-update-checker/) (PUC) library.

## How updates reach a site

1. PUC polls the GitHub Releases API of `mikemiler/wp-page-sync` (configured via
   `WPPS_UPDATE_REPO` in `wp-page-sync.php`) on a WP-Cron schedule (~ every 12 h).
2. It compares the latest release tag with the installed `WPPS_VERSION`.
3. If the release is newer, WordPress shows the normal update notice under
   *Plugins* and *Dashboard → Updates*. "View details" is fed by `readme.txt`.
4. The user clicks update; WordPress downloads the **ZIP asset** attached to the
   release, extracts it and replaces the plugin folder.

The repository's default branch is `main`, and `wp-page-sync.php` calls
`setBranch( 'main' )` for that reason: PUC defaults to `master`, which would
break its branch fallback. Do not remove that call.

## Pre-release checks

Run the linter before cutting a release. The `Lint` GitHub Action runs the same
check on every push, and the build is expected to be green before you tag.

The dev tooling (PHP_CodeSniffer + WordPress Coding Standards) lives in
`composer.json` under `require-dev` and is never shipped in the release ZIP
(`/vendor/` is gitignored and excluded by `.distignore`).

```bash
composer install     # once: installs phpcs/phpcbf into vendor/ (gitignored)
composer lint        # phpcs against phpcs.xml.dist, this is the CI gate
composer lint:fix    # phpcbf: auto-fix the fixable violations, then re-lint
```

The ruleset (`phpcs.xml.dist`) is WordPress-Extra plus security and PHP 7.4
compatibility sniffs. The gate fails on **errors**; **warnings** are reported but
do not fail the build (`ignore_warnings_on_exit`). Most alignment and whitespace
warnings are auto-fixable: run `composer lint:fix`, then re-run `composer lint`
and confirm a clean run (0 errors, 0 warnings) before tagging. A quick
`php -l <file>` is a cheap syntax sanity check on any file you touched.

## Cutting a new release

Keep these three in sync; the CI checks it and fails otherwise:

- `Version:` header in `wp-page-sync.php`
- `WPPS_VERSION` constant in `wp-page-sync.php`
- the git tag `vX.Y.Z` (leading `v` is stripped by PUC)

The `Stable tag` in `readme.txt` is **not** checked by CI. Keep it in sync by
hand anyway: it is what a wp.org-style reader expects to see. What PUC actually
compares against the installed version is the `Version:` header in the plugin
file of the tagged commit, so a wrong header ships a release nobody is offered.

Steps:

1. Bump the version in both spots in `wp-page-sync.php`.
2. Add a `= X.Y.Z =` entry under `== Changelog ==` in `readme.txt` and update
   `Stable tag`.
3. Run `composer lint` and make sure it is clean (see *Pre-release checks*).
4. Commit, then tag and push:

   ```bash
   git tag vX.Y.Z
   git push origin main --tags
   ```

5. The `Release` GitHub Action verifies tag == header == `WPPS_VERSION`, builds
   `wp-page-sync.zip` (top-level folder `wp-page-sync/`) and publishes it as a
   release asset. No manual zipping needed.

## Private repo (later)

If the repo becomes private, add authentication after `buildUpdateChecker(...)`:

```php
$update_checker->setAuthentication( WPPS_GITHUB_TOKEN );
```

Provide the token via a `wp-config.php` constant or server option, never commit
it. Use a fine-grained PAT with read-only access to this repo.
