# Base image upgrade to bookworm + PHP 8.4

Moving a project from `debian_bullseye_apache_php_8_2:20230710` to
`debian_bookworm_apache_php_8_4:20260628001`. First done on Cornels (PR #412, 2026-07-15).

## Before upgrading

- **The bookworm images dropped `imagick` and `ftp`; bullseye had both.** Nothing warns you:
  medialibrary's `Pdf`/`Svg` image generators report requirements-not-installed and skip silently,
  so a PDF that used to get a thumbnail stops getting one. Diff the modules first:
  `docker run --rm --entrypoint php <image> -m` against the old image.
- What decides safety: `image_driver` in `config/media-library.php` (must be `gd`), whether any
  `addMediaConversion` targets PDF or SVG media, and whether any filesystem disk uses the `ftp`
  driver. Grep those three, not just the string "imagick".
- mPDF + FPDI do **not** need imagick: PDF merging (`setSourceFile`/`importPage`/`UseTemplate`) is pure
  PHP over GD, and mPDF requires only `ext-gd` and `ext-mbstring`.
- `gs`, `jpegoptim`, `optipng`, `pngquant` and `convert` are missing from both images; that is not a
  regression.
- bookworm 20260628001 = PHP 8.4.22, Debian 12, Node 24, Composer 2.10 (old: PHP 8.2.7, Node 18).
  Vite 5 and Tailwind 4 build fine on Node 24.

## Doing it

- The image is named in a fourth place: `.github/workflows/main.yml` pre-pulls it by name, outside
  `build/web/ubuntu_php/*/Dockerfile`, so a grep for `FROM` misses it.
- The only PHP 8.4 deprecation that shows up in our apps is implicitly nullable parameters
  (`Media $media = null` → `?Media $media = null`):
  `grep -rnE '\(\s*[A-Za-z_\\][A-Za-z0-9_\\|]*\s+\$[A-Za-z0-9_]+\s*=\s*null' --include='*.php' --exclude-dir=vendor`
- **Do not go to PHP 8.5 on Laravel 11.** Laravel 11's bugfix window closed in September 2025, before
  8.5 shipped, so the combination is untested, and the `^8.2` constraint installs it anyway.
- **Composer 2.10 blocks advisory-affected packages by default**, so `composer update` fails outright
  on Laravel 11: every 11.x release is flagged and the fixes only exist in 12.60+/13.x.
  `composer install` from the lock still works. A partial update
  (`composer update "guzzlehttp/*" "symfony/*" ... --with-dependencies`) works around it, but mixes
  symfony 8.x free-floating components with laravel-constrained 7.4 ones, a mix upstream never tested.
- A PR with merge conflicts gets no `refs/pull/N/merge`, so `pull_request` workflows never run and CI
  shows "no checks reported". Check `gh pr view N --json mergeable` first.
- Cornels' `restart.sh`/`start.sh` hardcode `~/GitProjects/${GITREPOSITORY}/${GITREPOSITORY}/container`,
  so they rebuild the main checkout even from a worktree. To test a worktree stack, replicate the
  script by hand from the worktree's `container/` directory (after `cp ~/.secrets container/.secrets`;
  `.secrets` and `code/www/.env` are gitignored). Projects with the slot port don't have this problem:
  see [slot-port.md](slot-port.md).
