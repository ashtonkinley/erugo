## 2026-10-01 — Black film-border removal for backgrounds
- **Border detection:** `scripts/detect_focal_point.py` now also scans for solid black film borders on lab scans (a row/column counts as border when ≥97% of its pixels are near-black; thin JPEG edge noise ignored). When borders are found, a border-cropped display copy is written to `cleaned/` on the backgrounds disk and the slideshow shows that copy — the original scan is never modified. Face detection runs on the cropped region so focal points stay accurate.
- **API/frontend:** `/api/backgrounds` now returns `display_files` (original → display variant); the slideshow renders the cleaned copy with the focal-based `background-position`. Admin listing/deletion still operate on originals; deleting a background also removes its cleaned copy. `backgrounds:detect-subjects --force` backfills borders for existing backgrounds.

## 2026-10-01 — Subject-aware background cropping + editorial download page
- **Smart-crop backgrounds:** new `background_focal_points` table stores one subject focal point per background image, detected with YuNet face detection (`scripts/detect_focal_point.py`, OpenCV via `py3-opencv` in the image). Detection runs automatically for newly uploaded and BestOf-synced backgrounds; `backgrounds:detect-subjects` backfills existing ones (`--force` to redo). The frontend now renders `background-position: x% y%` instead of always centering, so portrait subjects stay in frame on both mobile and desktop. No subject found → centered crop, i.e. the old behavior; nothing gets worse.
- **Download page redesign (Option C):** the share download page is now a full-bleed editorial treatment — background photo, bottom gradient scrim, small supporting share name, and a prominent pill download button in the theme's primary color. File list is behind a "View files" toggle; sender message, password flow, and all error states (expired / limit reached / not found / loading) preserved.

## 2026-10-01 — Rolled back the weekly cleanup toggle
- Reverted the "Automatically delete expired shares" toggle: the existing "Clean Files After" setting already covers this (now set to 7 days), with the original daily cleanup schedule restored.

## 2026-10-01 — Random first background photo
- The landing page no longer always opens on the same background photo: the slideshow now starts on a random image from your uploaded backgrounds, then keeps rotating randomly every 30 seconds as before.

# FORK Changelog

> **Translation policy of this fork:** The UI is **English-only**. The language selector was removed entirely (2026-10-01) along with the German translation and the admin settings for default language / language selector visibility. The other locale files (`de`, `fr`, `it`, `nl`, `pt`, `pt-BR`) may still exist on disk but are no longer part of the build.

## 2026-10-01 — Remove language options, centre login screen
- **Language selector removed:** the floating language button (which overlapped the logout button) is gone, along with `languageSelector.vue`, the German translation (`de.json`), and the "default language" / "show language selector" admin settings. Tolgee is now hardcoded to English; all other locales were dropped from the frontend build.
- **Login screen centred:** the logged-out login card is now horizontally centred in the viewport to match the centred upload panel (it was hugging the left side).

## 2026-10-01 — Editorial upload page (Option A)
- **Upload page redesign (Option A):** the share upload page is now a full-bleed editorial treatment matching the download page — rotating background photo, bottom gradient scrim (plus a subtle top shade for the status-bar clock), centred logo, and a bottom-centred upload panel. "Add files" is the dominant pill button in the theme's primary color; "Add folders" is a quieter glass pill. Storage quota and expiry sit on one centred meta line (expiry settings expand inline); recipients, share-name/message fields, and the dropzone use restrained glass styling; the bottom "Upload" button is a full-width hero pill and the password lock is a glass circle. The upload progress overlay now takes over the viewport with a dark scrim. Settings/logout/reverse-share actions float as glass circles top-right; "Powered by" is hidden on the upload page.
- New i18n keys `uploader.new_share` / `uploader.drop_hint` (English + German).

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

## Immersive glass: login + settings (Option A)

Carries the upload page's design language across the login splash screen and
all settings pages:

- Login: the fixed top logo is replaced by a centred logo + glass-card
  composition (frosted card, glass inputs, white pill buttons) over the
  full-bleed background.
- Settings: the full-screen settings overlay becomes a floating frosted-glass
  panel (rounded 24px) with the photo visible around it; tabs become glass
  pills with a white active pill.
- All 9 settings tabs (About, Stats, Branding, System, Email templates,
  Users, All shares, My shares, My profile) are restyled at once via
  theme-variable overrides scoped under `.settings-overlay` — no per-tab
  edits needed. Inputs, tables, buttons, checkboxes and toggles inherit the
  glass look.
- Native `<select>` dropdown options are pinned to dark-on-white so they stay
  readable on glass inputs.

## Settings glass readability fixes

- Sticky table headers are now near-opaque dark glass, so scrolled rows
  can no longer show through the header.
- `--label-text-color` is overridden to white under `.settings-overlay`
  (checkbox labels like "Show deleted shares" were rendering dark-on-dark).
- Very light photos: gentle text-shadow lift on headings, labels, tabs and
  table text inside the settings panel and login card, so white text stays
  readable without changing the light-glass look. Dark-text controls
  (white pills, active tab) are excluded so they stay crisp. The dim behind
  the settings panel went from 0.25 to 0.32 for a touch more contrast.
