# Changelog

All notable changes to **Clevers Image Optimizer** are documented here. The
format follows [Keep a Changelog](https://keepachangelog.com/) and the
project adheres to [Semantic Versioning](https://semver.org/).

## [1.0.5] - 2026-10-30

### Added
- Brizy adapter rewrites local JPG/PNG URLs in compiled content to explicit
  WebP sidecars when they exist, without modifying Brizy data or media GUIDs.

### Fixed
- The Nginx WebP snippet now replaces the original JPG/PNG extension when it
  resolves a sidecar, and warns that CDNs must vary their cache by `Accept`.

### Added
- WebP is now supported by the Media Library re-optimization flow and upload
  safety net.
- An optional maximum dimension can resize images processed by the optimizer;
  it is disabled by default so existing dimensions are preserved until enabled.
- `CIO_Logger` exposes debug/info/warn/error severity routing via `error_log`
  with a structured payload (`plugin`, `severity`, `channel`, `message`,
  `context`, `ts`). Closes CLE-125.
- `phpstan.neon` + `phpstan-stubs.php` bring PHPStan level 3 to `includes/`
  on PHP 8.1 via a dedicated `static-analysis` job in CI. Closes CLE-93.
- `.github/workflows/plugin-check.yml` runs the
  `wp-cli/wordpress-org-plugin` action against `develop` so the standards
  enforced by WordPress.org plugin review are validated on every PR.
  Closes CLE-123.
- Conventional Commits-friendly changelog section (this file) backs the
  GitHub release body for each tag. Closes CLE-94.

### Changed
- `optimize_file()` and `create_image_resource()` now route their warnings
  through `CIO_Logger` instead of conditional `error_log` calls.

## [1.0.1] - 2026-04

### Fixed
- Hardened path traversal check in `optimize_attachment()`.

## [1.0.0] - 2026-03

### Added
- Initial public release of the WebP/AVIF local image optimizer.
