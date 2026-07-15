# Changelog

All notable changes to this project will be documented in this file.

## [1.0.1] - 2026-07-15

### Changed
- Roles in the dropdown are now sorted by weight, configurable via Admin → People → Roles (drag-drop order).
- After switching roles, the page now reloads in place (current page) instead of redirecting to the homepage.

### Fixed
- Block plugin ID corrected in `hook_install()` — was `role_switcher_session_block`, now correctly `role_switcher_block` (matching the `@Block` annotation).

## [1.0.0] - 2026-06-30

### Added
- Initial release.
- Session-based role switching for users with multiple custom roles.
- "Acting as" dropdown block auto-placed in header region on install.
- Revert to full role set via "- All my original roles -" option.
- Fresh login always resets to original roles.
- Administrator role lockout prevention.
- Drupal 10 and 11 compatibility.
