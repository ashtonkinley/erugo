## 2026-10-01 — BestOf backgrounds respect manual deletions
- Deleting an auto-synced background via the X button now sticks: the delete endpoint records the filename in a `bestof_backgrounds_blocked` setting and the daily sync skips blocked files instead of re-copying them.
- New commands: `backgrounds:blocked` (list removed backgrounds), `backgrounds:unblock {filename}` / `backgrounds:unblock --all` (allow re-sync).

## 2026-10-01 — BestOf backgrounds auto-sync
- New `SyncBestOfBackgrounds` job + `backgrounds:sync-bestof` artisan command: copies each month folder's `_10Best` photos from the lab's BestOf archive into the rotating landing-page backgrounds (top-level, prefixed with the month folder name so months can't collide). Underscore-prefixed exports (monthly collage, .psd) and non-images are skipped.
- Idempotent: already-synced files and months without a `_10Best` folder are skipped; a missing BestOf mount logs a warning instead of failing.
- Scheduled daily via the existing scheduler. Source path is `BESTOF_PATH` (default `/bestof`, read-only mount); new `config/mtlanalogue.php` holds it so it survives config:cache, and `BESTOF_` was added to the cron env allow-list in start-container.

# FORK Changelog

> **Translation policy of this fork:** Only **English and German** are actively maintained and offered in the UI. The language selector has been reduced to these two languages, and the other locale files (`fr`, `it`, `nl`, `pt`, `pt-BR`) still exist but are no longer updated when UI strings change.

## 2026-10-01 — Share zips skip re-compressing JPEGs
- `CreateShareZip` shelled out to `zip -r` with default compression. Share zips of JPEG scans (already compressed) burned ~72s of CPU for ~0% size savings.
- Now passes `zip -n` with already-compressed suffixes (jpg/jpeg/png/webp/heic/tif/tiff, video, audio, archives, pdf): those are stored as-is, everything else still gets normal compression.

## 2026-10-01 — Upload performance: OPcache + config cache
- Folder uploads of many small files were CPU-bound by PHP recompiling the framework on every tusd hook request (~82ms pure bootstrap per hook, 4 hooks per file). The image didn't ship `php83-opcache`, and the entrypoint cleared the config cache on boot without rebuilding it.
- Dockerfile now installs `php83-opcache`; `php.ini` enables OPcache (256MB, 20k files, 60s revalidation).
- `start-container` now runs `php artisan config:cache` on boot instead of only `config:clear` (route cache still cleared — routes use closures and can't be cached).
- Replaced direct `env('APP_URL')` with `config('app.url')` in `SharesController` and `routes/web.php` (`env()` returns null once config is cached).
- Measured on Tower test instance: hook request time 82ms → ~8ms (~10x less per-request overhead).

## 2026-09-30 — Security dependency upgrades (baseline audit must-fix)
- twig/twig 3.20.0 → 3.30.0 — 14 advisories incl. critical RCE/sandbox escapes; admin email templates compile raw Twig server-side
- laravel/framework 11.42.1 → 11.57.0 — file-validation bypass (GHSA-78fx-h6xr-vch4), directly in the upload threat model
- guzzlehttp/guzzle 7.9.2 → 7.15.5, guzzlehttp/psr7 2.7.0 → 2.13.1
- league/commonmark 2.6.1 → 2.10.3 (XSS/parsing DoS), league/flysystem 3.29.1 → 3.36.0 (path-normalizer bypass), phpseclib/phpseclib 3.0.43 → 3.0.57
- symfony/* 7.2.3 → 7.4.x, symfony/polyfill-intl-idn 1.31.0 → 1.43.0
- dompurify 3.4.13 → 3.4.16, browserslist 4.28.1 → 4.29.3, nanoid 3.3.17 → 3.3.19, baseline-browser-mapping 2.10.43 → 2.11.26
- Dockerfile prod stage now runs `composer install --no-dev` — dev dependencies (phpunit, psysh) are no longer baked into the image
- firebase/php-jwt deliberately left on the 6.x line (6.11.0 → 6.11.1): 7.x is blocked by laravel/socialite requiring `^6.4`; revisit when socialite permits 7.x
- laravel/framework stays on 11.x (now 11.57.0): the CRLF-in-email-rule advisory (GHSA-5vg9-5847-vvmq, CVSS 8.2) is fixed only in 12.x — a major-version bump needs its own plan
- Verified: `php artisan test` green, `npm run build` clean; image rebuilt and pushed to `ghcr.io/ashtonkinley/erugo`

## 2026-09-30 — Montréal Analogue stewardship begins
- Fork synced to `mrpetabyte/main` at `deec8b9` (2026-09-24); this repo is now maintained by Montréal Analogue as its own line.
- Added `.github/workflows/build.yml`: on pushes to `main` and on `v*` tags, builds `docker/alpine/Dockerfile` and pushes to `ghcr.io/ashtonkinley/erugo` (`latest` + commit SHA; git tag when present). Actions pinned to SHAs, `GITHUB_TOKEN` with `packages: write`.
- Branch protection on `main`: force pushes and deletions blocked; no PR-review requirement (solo maintainer).
- Enabled Dependabot alerts + Dependabot security updates; secret scanning and push protection were already on.
- Started weekly maintenance: upstream review (ErugoOSS + mrpetabyte), `composer`/`npm` audits, dependency patches, image rebuild + smoke test, changelog.
- Baseline security audit recorded (2026-09-30): upstream RCE fix confirmed in history; dependency advisories triaged — Twig, Laravel, Guzzle, league/commonmark, phpseclib, symfony/* upgrades queued for the first patch cycle.


## Reverse Shares
- Reverse Shares: Add option for link-only invite
- Reverse Shares: Remove option for guest email invites (only existing users can be invited via email)
- Reverse Shares: Link invites create a guest account (multi-use); email invites target existing registered users only
- Reverse Shares: Email invites to an unrecognized address are rejected with an error
- Reverse Shares: Allow multiple uses of the same invite
- Reverse Shares: Add a link/email mode switch to the reverse invite dialog
- Reverse Shares: Link-only invites generate a copyable invite URL after creation
- Reverse Shares: Require a label when creating a link-only invite
- Reverse Shares: Preserve the active invite label across authentication and page reloads
- Reverse Shares: Show the invite label as a title above the upload buttons so guests know they clicked the right link
- Reverse Shares: Guest-created shares are named "<invite label> – <guest name>"
- Reverse Shares: Removed the redundant "invite accepted" toast when accepting via link

## Guest Upload Experience
- Reverse Shares: Uploaders stay logged in to upload multiple files; the uploader name is remembered and editable per batch
- Reverse Shares: After uploading, guests can choose "Upload More Files" or "Done"
- Guests can upload additional batches without exposing account settings or password controls
- Redesigned the guest name field with an inline label; theme-aware dark styling
- Added an upload progress header with the reverse-share label and upload status
- Enforce a minimum expiry value of one
- Expiry time display uses proper singular/plural forms for all languages (ICU)
- File dropzone no longer opens the file picker when clicked; the hand cursor was removed

## Uploads & Downloads
- Direct download supported via ?directdl=1
- Faster and resumable multi-file uploads
- Improved estimated upload time display
- Downloaded ZIP filenames use safe ASCII fallbacks to avoid malformed names in some clients

## Interface & Theming
- Added a full-screen loading screen while the initial authentication state is determined
- Prevented the main UI and login form from flashing before initial authentication completes
- Fixed layout issue on mobile
- Fixed dark seam artifacts between upload sections on mobile
- Server-side theme category now determines the initial dark/light mode without operating-system detection

## Security & Reliability
- Various security improvements and removal of security vulnerabilities
- RCE fix in uploads controllers
- Forbidden API responses no longer log users out; logout is reserved for expired sessions
- Fixed a 500 error on downloading non-public shares that had no reverse-share invite (null `invite` guard in `SharesController::checkShareAccess`)
- Harden the share-notification jobs (`sendExpiredWarningEmails`, `sendDeletionWarningEmails`, `sendExpiryWarningEmails`) against ownerless (guest/deleted-account) shares so they no longer crash and retry every night
- Guarded `File::user()` against a null share relationship
- Reworked guest-user cleanup in `maintainDb` to run by invite lifecycle instead of an age sweep: expired-unused link invites and their guests are removed together (fixing a nightly foreign-key constraint failure). Invites (and their guests) are kept alive as long as the invite still has a live share, so a share's access and cleanup remain intact until the share itself is deleted at `deletes_at` (`expires_at + clean_files_after_days`)

## Translations
- Language selector now offers only English and German; other locales are no longer maintained in this fork
- Major and continued German translation improvements
- Added upload progress title translations for all supported locales
