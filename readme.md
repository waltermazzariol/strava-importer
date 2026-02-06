# Strava Activity Importer for WordPress

Import your Strava activities as WordPress blog posts, on demand.

## Features

- **OAuth 2.0 authentication** — Secure connection to your Strava account with automatic token refresh
- **Browse activities** — Paginated list of your Strava activities with key stats at a glance
- **Selective import** — Choose which activities to import using checkboxes (single or batch)
- **Auto-categorization** — Creates a "Strava Activities" category and sport-type sub-categories (Run, Ride, Swim, etc.)
- **Rich post content** — Each imported post includes:
  - Activity description as post content
  - Stats table (distance, duration, pace/speed, elevation, heart rate, calories, suffer score, gear, kudos)
  - Smart pace/speed formatting (pace for runs, speed for rides)
  - Photos gallery (additional photos embedded in content)
  - Featured image from the first activity photo
  - Link back to the activity on Strava
- **Full metadata** — All activity data saved as post meta for theme/plugin use:
  - `_strava_activity_id`, `_strava_activity_url`, `_strava_sport_type`
  - `_strava_distance`, `_strava_moving_time`, `_strava_elapsed_time`
  - `_strava_elevation_gain`, `_strava_avg_speed`, `_strava_max_speed`
  - `_strava_avg_heartrate`, `_strava_max_heartrate`, `_strava_calories`
  - `_strava_kudos_count`, `_strava_suffer_score`, `_strava_gear`
  - `_strava_start_latlng`, `_strava_polyline`
- **Duplicate detection** — Already-imported activities are visually flagged and cannot be re-imported
- **Configurable** — Choose post status (draft/published/private) and post author

## Installation

1. Download and unzip the plugin
2. Upload the `strava-importer` folder to `/wp-content/plugins/`
3. Activate the plugin in **Plugins → Installed Plugins**
4. Go to **Strava Importer** in the admin menu

## Setup

1. Visit [strava.com/settings/api](https://www.strava.com/settings/api) and create a new API Application
2. Set the **Authorization Callback Domain** to your WordPress domain (e.g., `yourdomain.com`)
3. In the plugin settings, enter your **Client ID** and **Client Secret**
4. Click **Save Settings**, then **Connect with Strava**
5. Authorize the app on Strava — you'll be redirected back to WordPress

## Usage

1. Click **Load Activities from Strava** to fetch your recent activities
2. Browse the list — use pagination to see older activities
3. Select the activities you want to import using the checkboxes
4. Click **Import Selected** and watch the progress
5. Imported posts link directly to their edit screen in WordPress

## Requirements

- WordPress 5.0+
- PHP 7.4+
- An active Strava account

## License

GPL v2 or later
