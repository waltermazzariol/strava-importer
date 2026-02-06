# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Changed
- Featured image now uses external Strava URL directly (via a WordPress attachment record) instead of downloading the file, avoiding download/sideload failures.
- Gallery images use external Strava URLs directly in the post content.

### Fixed
- Fixed featured image URL being double-prefixed with the WordPress uploads directory. Now uses a custom meta (`_strava_external_url`) with a `wp_get_attachment_url` filter to return the external URL directly.
- Fixed gallery skipping the first photo. All photos now appear in the gallery, with the first also used as the featured image.
- Added `photo_sources=true` parameter to Strava photos API call, required for the API to return actual image URLs.
- Fixed `krsort` sorting to use `SORT_NUMERIC` so the largest image size (e.g. 2048) is correctly selected over smaller sizes (e.g. 600).
- Added fallback for Strava photo responses that use a flat `url` field instead of the `urls` object.
