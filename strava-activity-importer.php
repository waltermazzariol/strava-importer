<?php
/**
 * Plugin Name: Strava Activity Importer
 * Plugin URI: https://waltermazzariol.com
 * Description: Import your Strava activities as WordPress posts on demand. Includes activity stats, photos, maps, and descriptions.
 * Version: 1.0.0
 * Author: Walter Mazzariol
 * Author URI: https://waltermazzariol.com
 * License: GPL v2 or later
 * Text Domain: strava-importer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'STRAVA_IMPORTER_VERSION', '1.0.0' );
define( 'STRAVA_IMPORTER_DIR', plugin_dir_path( __FILE__ ) );
define( 'STRAVA_IMPORTER_URL', plugin_dir_url( __FILE__ ) );

class Strava_Activity_Importer {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'init', array( $this, 'register_taxonomy_and_meta' ) );

        // AJAX handlers
        add_action( 'wp_ajax_strava_fetch_activities', array( $this, 'ajax_fetch_activities' ) );
        add_action( 'wp_ajax_strava_import_activity', array( $this, 'ajax_import_activity' ) );
        add_action( 'wp_ajax_strava_disconnect', array( $this, 'ajax_disconnect' ) );

        // OAuth callback
        add_action( 'admin_init', array( $this, 'handle_oauth_callback' ) );
    }

    /**
     * Register custom category and post meta
     */
    public function register_taxonomy_and_meta() {
        // Ensure 'Strava Activities' category exists
        if ( ! term_exists( 'Strava Activities', 'category' ) ) {
            wp_insert_term( 'Strava Activities', 'category', array(
                'slug' => 'strava-activities',
                'description' => 'Activities imported from Strava',
            ) );
        }
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_menu_page(
            __( 'Strava Importer', 'strava-importer' ),
            __( 'Strava Importer', 'strava-importer' ),
            'manage_options',
            'strava-importer',
            array( $this, 'render_admin_page' ),
            'dashicons-performance',
            30
        );
    }

    /**
     * Register settings
     */
    public function register_settings() {
        register_setting( 'strava_importer_settings', 'strava_client_id', array(
            'sanitize_callback' => 'sanitize_text_field',
        ) );
        register_setting( 'strava_importer_settings', 'strava_client_secret', array(
            'sanitize_callback' => 'sanitize_text_field',
        ) );
        register_setting( 'strava_importer_settings', 'strava_post_status', array(
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'draft',
        ) );
        register_setting( 'strava_importer_settings', 'strava_post_author', array(
            'sanitize_callback' => 'absint',
        ) );
    }

    /**
     * Enqueue admin CSS and JS
     */
    public function enqueue_admin_assets( $hook ) {
        if ( 'toplevel_page_strava-importer' !== $hook ) {
            return;
        }

        wp_enqueue_style(
            'strava-importer-admin',
            STRAVA_IMPORTER_URL . 'assets/admin.css',
            array(),
            STRAVA_IMPORTER_VERSION
        );

        wp_enqueue_script(
            'strava-importer-admin',
            STRAVA_IMPORTER_URL . 'assets/admin.js',
            array( 'jquery' ),
            STRAVA_IMPORTER_VERSION,
            true
        );

        wp_localize_script( 'strava-importer-admin', 'stravaImporter', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'strava_importer_nonce' ),
        ) );
    }

    /**
     * Handle OAuth callback from Strava
     */
    public function handle_oauth_callback() {
        if ( ! isset( $_GET['page'] ) || 'strava-importer' !== $_GET['page'] ) {
            return;
        }

        if ( ! isset( $_GET['code'] ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $code          = sanitize_text_field( $_GET['code'] );
        $client_id     = get_option( 'strava_client_id' );
        $client_secret = get_option( 'strava_client_secret' );

        if ( empty( $client_id ) || empty( $client_secret ) ) {
            add_settings_error( 'strava_importer', 'missing_credentials', __( 'Client ID and Secret are required.', 'strava-importer' ) );
            return;
        }

        $response = wp_remote_post( 'https://www.strava.com/oauth/token', array(
            'body' => array(
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
                'code'          => $code,
                'grant_type'    => 'authorization_code',
            ),
            'timeout' => 30,
        ) );

        if ( is_wp_error( $response ) ) {
            add_settings_error( 'strava_importer', 'oauth_error', $response->get_error_message() );
            return;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( isset( $body['errors'] ) ) {
            $msg = isset( $body['message'] ) ? $body['message'] : __( 'OAuth error', 'strava-importer' );
            add_settings_error( 'strava_importer', 'oauth_error', $msg );
            return;
        }

        update_option( 'strava_access_token', $body['access_token'] );
        update_option( 'strava_refresh_token', $body['refresh_token'] );
        update_option( 'strava_token_expires_at', $body['expires_at'] );
        update_option( 'strava_athlete', $body['athlete'] );

        // Redirect to clean URL
        wp_redirect( admin_url( 'admin.php?page=strava-importer&connected=1' ) );
        exit;
    }

    /**
     * Get a valid access token, refreshing if needed
     */
    private function get_access_token() {
        $expires_at = (int) get_option( 'strava_token_expires_at', 0 );
        $now        = time();

        if ( $now < $expires_at - 60 ) {
            return get_option( 'strava_access_token' );
        }

        // Refresh token
        $refresh_token = get_option( 'strava_refresh_token' );
        $client_id     = get_option( 'strava_client_id' );
        $client_secret = get_option( 'strava_client_secret' );

        if ( empty( $refresh_token ) || empty( $client_id ) || empty( $client_secret ) ) {
            return false;
        }

        $response = wp_remote_post( 'https://www.strava.com/oauth/token', array(
            'body' => array(
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
                'grant_type'    => 'refresh_token',
                'refresh_token' => $refresh_token,
            ),
            'timeout' => 30,
        ) );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( isset( $body['access_token'] ) ) {
            update_option( 'strava_access_token', $body['access_token'] );
            update_option( 'strava_refresh_token', $body['refresh_token'] );
            update_option( 'strava_token_expires_at', $body['expires_at'] );
            return $body['access_token'];
        }

        return false;
    }

    /**
     * Make an authenticated request to Strava API
     */
    private function strava_api_get( $endpoint, $params = array() ) {
        $token = $this->get_access_token();
        if ( ! $token ) {
            return new WP_Error( 'no_token', __( 'No valid access token. Please reconnect to Strava.', 'strava-importer' ) );
        }

        $url = 'https://www.strava.com/api/v3/' . $endpoint;
        if ( ! empty( $params ) ) {
            $url = add_query_arg( $params, $url );
        }

        $response = wp_remote_get( $url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
            ),
            'timeout' => 30,
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 ) {
            $msg = isset( $body['message'] ) ? $body['message'] : __( 'API request failed', 'strava-importer' );
            return new WP_Error( 'api_error', $msg . ' (HTTP ' . $code . ')' );
        }

        return $body;
    }

    /**
     * AJAX: Fetch activities list
     */
    public function ajax_fetch_activities() {
        check_ajax_referer( 'strava_importer_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'strava-importer' ) );
        }

        $page     = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1;
        $per_page = isset( $_POST['per_page'] ) ? absint( $_POST['per_page'] ) : 30;

        $activities = $this->strava_api_get( 'athlete/activities', array(
            'page'     => $page,
            'per_page' => $per_page,
        ) );

        if ( is_wp_error( $activities ) ) {
            wp_send_json_error( $activities->get_error_message() );
        }

        // Check which activities are already imported
        $imported_ids = $this->get_imported_activity_ids();

        foreach ( $activities as &$activity ) {
            $activity['already_imported'] = in_array( (string) $activity['id'], $imported_ids, true );
        }

        wp_send_json_success( $activities );
    }

    /**
     * Get list of already imported Strava activity IDs
     */
    private function get_imported_activity_ids() {
        global $wpdb;

        $ids = $wpdb->get_col(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_strava_activity_id'"
        );

        return $ids;
    }

    /**
     * AJAX: Import a single activity
     */
    public function ajax_import_activity() {
        check_ajax_referer( 'strava_importer_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'strava-importer' ) );
        }

        $activity_id = isset( $_POST['activity_id'] ) ? sanitize_text_field( $_POST['activity_id'] ) : '';

        if ( empty( $activity_id ) ) {
            wp_send_json_error( __( 'Activity ID is required.', 'strava-importer' ) );
        }

        // Check if already imported
        $existing = get_posts( array(
            'meta_key'   => '_strava_activity_id',
            'meta_value' => $activity_id,
            'post_type'  => 'post',
            'numberposts' => 1,
        ) );

        if ( ! empty( $existing ) ) {
            wp_send_json_error( __( 'This activity has already been imported.', 'strava-importer' ) );
        }

        // Get detailed activity
        $activity = $this->strava_api_get( 'activities/' . $activity_id );
        if ( is_wp_error( $activity ) ) {
            wp_send_json_error( $activity->get_error_message() );
        }

        // Get photos
        $photos = array();
        if ( ! empty( $activity['total_photo_count'] ) && $activity['total_photo_count'] > 0 ) {
            $photos_response = $this->strava_api_get( 'activities/' . $activity_id . '/photos', array(
                'size' => 2048,
            ) );
            if ( ! is_wp_error( $photos_response ) && is_array( $photos_response ) ) {
                $photos = $photos_response;
            }
        }

        // Build post content
        $content = $this->build_post_content( $activity, $photos );

        // Determine post status and author
        $post_status = get_option( 'strava_post_status', 'draft' );
        $post_author = get_option( 'strava_post_author', get_current_user_id() );

        // Use activity local date for post date
        $post_date = '';
        if ( ! empty( $activity['start_date_local'] ) ) {
            $post_date = str_replace( array( 'T', 'Z' ), array( ' ', '' ), $activity['start_date_local'] );
        }

        // Create the post
        $post_data = array(
            'post_title'   => sanitize_text_field( $activity['name'] ),
            'post_content' => $content,
            'post_status'  => $post_status,
            'post_author'  => $post_author,
            'post_date'    => $post_date,
            'post_type'    => 'post',
        );

        $post_id = wp_insert_post( $post_data, true );

        if ( is_wp_error( $post_id ) ) {
            wp_send_json_error( $post_id->get_error_message() );
        }

        // Assign category
        $cat = get_term_by( 'slug', 'strava-activities', 'category' );
        if ( ! $cat ) {
            $result = wp_insert_term( 'Strava Activities', 'category', array( 'slug' => 'strava-activities' ) );
            $cat_id = is_array( $result ) ? $result['term_id'] : 0;
        } else {
            $cat_id = $cat->term_id;
        }

        // Also add sport type as category
        $sport_type = ! empty( $activity['sport_type'] ) ? $activity['sport_type'] : $activity['type'];
        $sport_cat  = get_term_by( 'name', $sport_type, 'category' );
        if ( ! $sport_cat ) {
            $result    = wp_insert_term( $sport_type, 'category', array(
                'parent' => $cat_id,
            ) );
            $sport_cat_id = is_array( $result ) ? $result['term_id'] : 0;
        } else {
            $sport_cat_id = $sport_cat->term_id;
        }

        $categories = array_filter( array( $cat_id, $sport_cat_id ) );
        wp_set_post_categories( $post_id, $categories );

        // Save Strava metadata
        update_post_meta( $post_id, '_strava_activity_id', $activity_id );
        update_post_meta( $post_id, '_strava_activity_url', 'https://www.strava.com/activities/' . $activity_id );
        update_post_meta( $post_id, '_strava_sport_type', $sport_type );
        update_post_meta( $post_id, '_strava_distance', isset( $activity['distance'] ) ? $activity['distance'] : 0 );
        update_post_meta( $post_id, '_strava_moving_time', isset( $activity['moving_time'] ) ? $activity['moving_time'] : 0 );
        update_post_meta( $post_id, '_strava_elapsed_time', isset( $activity['elapsed_time'] ) ? $activity['elapsed_time'] : 0 );
        update_post_meta( $post_id, '_strava_elevation_gain', isset( $activity['total_elevation_gain'] ) ? $activity['total_elevation_gain'] : 0 );
        update_post_meta( $post_id, '_strava_avg_speed', isset( $activity['average_speed'] ) ? $activity['average_speed'] : 0 );
        update_post_meta( $post_id, '_strava_max_speed', isset( $activity['max_speed'] ) ? $activity['max_speed'] : 0 );
        update_post_meta( $post_id, '_strava_avg_heartrate', isset( $activity['average_heartrate'] ) ? $activity['average_heartrate'] : 0 );
        update_post_meta( $post_id, '_strava_max_heartrate', isset( $activity['max_heartrate'] ) ? $activity['max_heartrate'] : 0 );
        update_post_meta( $post_id, '_strava_calories', isset( $activity['calories'] ) ? $activity['calories'] : 0 );
        update_post_meta( $post_id, '_strava_kudos_count', isset( $activity['kudos_count'] ) ? $activity['kudos_count'] : 0 );
        update_post_meta( $post_id, '_strava_suffer_score', isset( $activity['suffer_score'] ) ? $activity['suffer_score'] : '' );
        update_post_meta( $post_id, '_strava_gear', isset( $activity['gear']['name'] ) ? $activity['gear']['name'] : '' );

        if ( ! empty( $activity['start_latlng'] ) ) {
            update_post_meta( $post_id, '_strava_start_latlng', $activity['start_latlng'] );
        }
        if ( ! empty( $activity['map']['summary_polyline'] ) ) {
            update_post_meta( $post_id, '_strava_polyline', $activity['map']['summary_polyline'] );
        }

        // Download all photos to WordPress media library
        if ( ! empty( $photos ) ) {
            $local_photos = $this->download_all_photos( $photos, $post_id, $activity['name'] );

            // Set first photo as featured image
            if ( ! empty( $local_photos[0]['attachment_id'] ) ) {
                set_post_thumbnail( $post_id, $local_photos[0]['attachment_id'] );
            }

            // Rebuild post content with local image URLs and update the post
            if ( ! empty( $local_photos ) ) {
                $content = $this->build_post_content( $activity, $photos, $local_photos );
                wp_update_post( array(
                    'ID'           => $post_id,
                    'post_content' => $content,
                ) );
            }
        }

        wp_send_json_success( array(
            'post_id'  => $post_id,
            'edit_url' => get_edit_post_link( $post_id, 'raw' ),
            'view_url' => get_permalink( $post_id ),
            'title'    => $activity['name'],
        ) );
    }

    /**
     * Get the largest image URL from a Strava photo object
     */
    private function get_photo_url( $photo ) {
        if ( ! empty( $photo['urls'] ) && is_array( $photo['urls'] ) ) {
            $urls = $photo['urls'];
            krsort( $urls );
            return reset( $urls );
        }
        return '';
    }

    /**
     * Download a photo from Strava and upload it to the WordPress media library.
     * Returns the attachment ID on success, or 0 on failure.
     */
    private function download_photo( $image_url, $post_id, $title = '' ) {
        if ( empty( $image_url ) ) {
            return 0;
        }

        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = download_url( $image_url );
        if ( is_wp_error( $tmp ) ) {
            return 0;
        }

        $file_array = array(
            'name'     => sanitize_file_name( 'strava-' . $post_id . '-' . time() . '-' . wp_rand() . '.jpg' ),
            'tmp_name' => $tmp,
        );

        $attachment_id = media_handle_sideload( $file_array, $post_id, sanitize_text_field( $title ) );

        if ( is_wp_error( $attachment_id ) ) {
            @unlink( $tmp );
            return 0;
        }

        return $attachment_id;
    }

    /**
     * Download all photos from Strava and return an array of local attachment data.
     * Each element contains 'attachment_id' and 'url'.
     */
    private function download_all_photos( $photos, $post_id, $activity_name ) {
        $local_photos = array();

        foreach ( $photos as $index => $photo ) {
            $image_url = $this->get_photo_url( $photo );
            $title     = $activity_name . ( $index > 0 ? ' - Photo ' . ( $index + 1 ) : '' );

            $attachment_id = $this->download_photo( $image_url, $post_id, $title );
            if ( $attachment_id ) {
                $local_photos[] = array(
                    'attachment_id' => $attachment_id,
                    'url'           => wp_get_attachment_url( $attachment_id ),
                );
            }
        }

        return $local_photos;
    }

    /**
     * Build the HTML post content from activity data and photos
     */
    private function build_post_content( $activity, $photos = array(), $local_photos = array() ) {
        $blocks = array();

        // Description
        if ( ! empty( $activity['description'] ) ) {
            $blocks[] = '<!-- wp:paragraph -->';
            $blocks[] = '<p>' . nl2br( esc_html( $activity['description'] ) ) . '</p>';
            $blocks[] = '<!-- /wp:paragraph -->';
            $blocks[] = '';
        }

        // Stats block
        $blocks[] = '<!-- wp:group {"className":"strava-activity-stats"} -->';
        $blocks[] = '<div class="wp-block-group strava-activity-stats">';
        $blocks[] = '<!-- wp:heading {"level":3} -->';
        $blocks[] = '<h3>🏃 Activity Stats</h3>';
        $blocks[] = '<!-- /wp:heading -->';

        // Build stats table
        $blocks[] = '<!-- wp:table {"className":"strava-stats-table"} -->';
        $blocks[] = '<figure class="wp-block-table strava-stats-table"><table><tbody>';

        // Sport type
        $sport_type = ! empty( $activity['sport_type'] ) ? $activity['sport_type'] : $activity['type'];
        $blocks[] = '<tr><td><strong>🏅 Sport</strong></td><td>' . esc_html( $this->format_sport_type( $sport_type ) ) . '</td></tr>';

        // Distance
        if ( ! empty( $activity['distance'] ) ) {
            $km = round( $activity['distance'] / 1000, 2 );
            $blocks[] = '<tr><td><strong>📏 Distance</strong></td><td>' . $km . ' km</td></tr>';
        }

        // Duration
        if ( ! empty( $activity['moving_time'] ) ) {
            $blocks[] = '<tr><td><strong>⏱️ Moving Time</strong></td><td>' . $this->format_duration( $activity['moving_time'] ) . '</td></tr>';
        }
        if ( ! empty( $activity['elapsed_time'] ) && $activity['elapsed_time'] !== $activity['moving_time'] ) {
            $blocks[] = '<tr><td><strong>⏳ Elapsed Time</strong></td><td>' . $this->format_duration( $activity['elapsed_time'] ) . '</td></tr>';
        }

        // Pace / Speed
        if ( ! empty( $activity['average_speed'] ) && $activity['average_speed'] > 0 ) {
            $is_run = in_array( $sport_type, array( 'Run', 'TrailRun', 'VirtualRun', 'Walk', 'Hike' ), true );
            if ( $is_run ) {
                $pace = $this->format_pace( $activity['average_speed'] );
                $blocks[] = '<tr><td><strong>🏎️ Avg Pace</strong></td><td>' . $pace . ' /km</td></tr>';
                if ( ! empty( $activity['max_speed'] ) ) {
                    $max_pace = $this->format_pace( $activity['max_speed'] );
                    $blocks[] = '<tr><td><strong>⚡ Best Pace</strong></td><td>' . $max_pace . ' /km</td></tr>';
                }
            } else {
                $avg_kmh = round( $activity['average_speed'] * 3.6, 1 );
                $blocks[] = '<tr><td><strong>🏎️ Avg Speed</strong></td><td>' . $avg_kmh . ' km/h</td></tr>';
                if ( ! empty( $activity['max_speed'] ) ) {
                    $max_kmh = round( $activity['max_speed'] * 3.6, 1 );
                    $blocks[] = '<tr><td><strong>⚡ Max Speed</strong></td><td>' . $max_kmh . ' km/h</td></tr>';
                }
            }
        }

        // Elevation
        if ( ! empty( $activity['total_elevation_gain'] ) ) {
            $blocks[] = '<tr><td><strong>⛰️ Elevation Gain</strong></td><td>' . round( $activity['total_elevation_gain'] ) . ' m</td></tr>';
        }

        // Heart rate
        if ( ! empty( $activity['average_heartrate'] ) ) {
            $blocks[] = '<tr><td><strong>❤️ Avg Heart Rate</strong></td><td>' . round( $activity['average_heartrate'] ) . ' bpm</td></tr>';
        }
        if ( ! empty( $activity['max_heartrate'] ) ) {
            $blocks[] = '<tr><td><strong>💓 Max Heart Rate</strong></td><td>' . round( $activity['max_heartrate'] ) . ' bpm</td></tr>';
        }

        // Calories
        if ( ! empty( $activity['calories'] ) ) {
            $blocks[] = '<tr><td><strong>🔥 Calories</strong></td><td>' . round( $activity['calories'] ) . ' kcal</td></tr>';
        }

        // Suffer Score
        if ( ! empty( $activity['suffer_score'] ) ) {
            $blocks[] = '<tr><td><strong>💪 Suffer Score</strong></td><td>' . $activity['suffer_score'] . '</td></tr>';
        }

        // Gear
        if ( ! empty( $activity['gear']['name'] ) ) {
            $blocks[] = '<tr><td><strong>👟 Gear</strong></td><td>' . esc_html( $activity['gear']['name'] ) . '</td></tr>';
        }

        // Kudos
        if ( ! empty( $activity['kudos_count'] ) ) {
            $blocks[] = '<tr><td><strong>👍 Kudos</strong></td><td>' . $activity['kudos_count'] . '</td></tr>';
        }

        $blocks[] = '</tbody></table></figure>';
        $blocks[] = '<!-- /wp:table -->';
        $blocks[] = '</div>';
        $blocks[] = '<!-- /wp:group -->';
        $blocks[] = '';

        // Photos gallery (skip first since it's the featured image)
        if ( count( $photos ) > 1 ) {
            $blocks[] = '<!-- wp:heading {"level":3} -->';
            $blocks[] = '<h3>📸 Photos</h3>';
            $blocks[] = '<!-- /wp:heading -->';
            $blocks[] = '';
            $blocks[] = '<!-- wp:gallery {"columns":2,"linkTo":"none","className":"strava-photos"} -->';
            $blocks[] = '<figure class="wp-block-gallery has-nested-images columns-2 strava-photos">';

            foreach ( array_slice( $photos, 1 ) as $photo ) {
                $url = '';
                if ( ! empty( $photo['urls'] ) && is_array( $photo['urls'] ) ) {
                    krsort( $photo['urls'] );
                    $url = reset( $photo['urls'] );
                }
                if ( ! empty( $url ) ) {
                    $blocks[] = '<!-- wp:image -->';
                    $blocks[] = '<figure class="wp-block-image"><img src="' . esc_url( $url ) . '" alt="Activity photo"/></figure>';
                    $blocks[] = '<!-- /wp:image -->';
                }
            }

            $blocks[] = '</figure>';
            $blocks[] = '<!-- /wp:gallery -->';
            $blocks[] = '';
        }

        // Strava link
        $strava_url = 'https://www.strava.com/activities/' . $activity['id'];
        $blocks[] = '<!-- wp:paragraph {"className":"strava-link"} -->';
        $blocks[] = '<p class="strava-link"><a href="' . esc_url( $strava_url ) . '" target="_blank" rel="noopener noreferrer">View on Strava →</a></p>';
        $blocks[] = '<!-- /wp:paragraph -->';

        return implode( "\n", $blocks );
    }

    /**
     * Format duration from seconds to H:MM:SS
     */
    private function format_duration( $seconds ) {
        $hours   = floor( $seconds / 3600 );
        $minutes = floor( ( $seconds % 3600 ) / 60 );
        $secs    = $seconds % 60;

        if ( $hours > 0 ) {
            return sprintf( '%d:%02d:%02d', $hours, $minutes, $secs );
        }
        return sprintf( '%d:%02d', $minutes, $secs );
    }

    /**
     * Format pace from m/s to min:sec per km
     */
    private function format_pace( $speed_ms ) {
        if ( $speed_ms <= 0 ) {
            return '--:--';
        }
        $pace_seconds = 1000 / $speed_ms;
        $min  = floor( $pace_seconds / 60 );
        $sec  = round( $pace_seconds % 60 );
        return sprintf( '%d:%02d', $min, $sec );
    }

    /**
     * Format sport type string for display
     */
    private function format_sport_type( $type ) {
        // Convert CamelCase to spaced words
        return trim( preg_replace( '/([A-Z])/', ' $1', $type ) );
    }

    /**
     * Get the OAuth authorization URL
     */
    private function get_auth_url() {
        $client_id    = get_option( 'strava_client_id' );
        $redirect_uri = admin_url( 'admin.php?page=strava-importer' );

        return 'https://www.strava.com/oauth/authorize?' . http_build_query( array(
            'client_id'     => $client_id,
            'redirect_uri'  => $redirect_uri,
            'response_type' => 'code',
            'scope'         => 'read,activity:read_all',
            'approval_prompt' => 'auto',
        ) );
    }

    /**
     * Check if connected to Strava
     */
    private function is_connected() {
        return (bool) get_option( 'strava_refresh_token' );
    }

    /**
     * AJAX: Disconnect from Strava
     */
    public function ajax_disconnect() {
        check_ajax_referer( 'strava_importer_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'strava-importer' ) );
        }

        // Deauthorize with Strava
        $token = $this->get_access_token();
        if ( $token ) {
            wp_remote_post( 'https://www.strava.com/oauth/deauthorize', array(
                'headers' => array( 'Authorization' => 'Bearer ' . $token ),
                'timeout' => 15,
            ) );
        }

        delete_option( 'strava_access_token' );
        delete_option( 'strava_refresh_token' );
        delete_option( 'strava_token_expires_at' );
        delete_option( 'strava_athlete' );

        wp_send_json_success();
    }

    /**
     * Render the admin page
     */
    public function render_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $is_connected = $this->is_connected();
        $athlete      = get_option( 'strava_athlete', array() );
        $client_id    = get_option( 'strava_client_id', '' );
        $client_secret = get_option( 'strava_client_secret', '' );
        $post_status  = get_option( 'strava_post_status', 'draft' );
        $post_author  = get_option( 'strava_post_author', get_current_user_id() );

        settings_errors( 'strava_importer' );

        include STRAVA_IMPORTER_DIR . 'templates/admin-page.php';
    }
}

// Initialize plugin
Strava_Activity_Importer::get_instance();
