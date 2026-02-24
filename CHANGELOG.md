# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Changed
- Replaced Bootstrap one-slide-at-a-time carousel with a horizontal scrollable media strip: all photos and video thumbnails are visible side by side with rounded corners (`border-radius: 12px`), scroll-snap enabled, and a thin styled scrollbar on WebKit.
- Lightbox now triggered by clicking `.strava-strip-item` elements instead of carousel items; carousel prev/next controls removed.

## [1.2.0]

### Added
- Unified media carousel on Strava activity posts: photos and videos appear in a single Bootstrap 5.3 carousel (450 px tall) in their original Strava order, replacing the separate "📸 Photos" gallery and "🎥 Videos" sections.
- Custom lightbox (`assets/strava-carousel.css` / `assets/strava-carousel.js`): clicking any carousel photo opens it full-screen; clicking a video thumbnail opens the lightbox and begins playback. ESC and backdrop clicks close the lightbox.
- `enqueue_frontend_assets()` method automatically loads carousel CSS/JS only on single posts that carry a `_strava_activity_id` meta value.
- "Update" button on already-imported activities to re-fetch data from Strava and update the existing WordPress post in place, preserving post ID, date, status, author, and comments.

### Changed
- `build_post_content()` signature changed from `($activity, $photo_urls, $video_urls)` to `($activity, $carousel_items)` where each entry carries `{ type, src }` (photo) or `{ type, src, thumb }` (video).
- `run_import()` now builds an ordered `$carousel_items` list that preserves the original interleaved photo/video sequence from Strava instead of splitting them into two separate arrays.

### Changed
- Photos (featured image and gallery) are now downloaded to the WordPress media library using `wp_remote_get()` + `media_handle_sideload()` instead of referencing external Strava URLs. Uses `wp_remote_get()` directly (instead of `download_url()` which wraps `wp_safe_remote_get()`) to avoid CDN URL blocking in local/containerized environments.
- On reimport, old Strava-downloaded attachments (both current `_strava_photo_url` and legacy `_strava_external_url` formats) are cleaned up before downloading fresh copies, preventing duplicate media accumulation.
- Removed `wp_get_attachment_url` filter and `_strava_external_url` meta — no longer needed since all attachments have real local files.

### Fixed
- Fixed downloaded images being corrupted due to hardcoded `.jpg` extension. Now detects the actual image format (JPEG, PNG, WebP, GIF) from file content using `wp_get_image_mime()` and uses the correct extension, preventing MIME type mismatches during WordPress image processing.
- Fixed double file extension (e.g. `photo.jpg.jpg`) when Strava CDN URLs already contained an extension in the path.
- Fixed featured image URL being double-prefixed with the WordPress uploads directory. Now uses proper `media_handle_sideload()` which creates real file-backed attachments.
- Fixed gallery skipping the first photo. All photos now appear in the gallery, with the first also used as the featured image.
- Added `photo_sources=true` parameter to Strava photos API call, required for the API to return actual image URLs.
- Fixed `krsort` sorting to use `SORT_NUMERIC` so the largest image size (e.g. 2048) is correctly selected over smaller sizes (e.g. 600).
- Added fallback for Strava photo responses that use a flat `url` field instead of the `urls` object.
