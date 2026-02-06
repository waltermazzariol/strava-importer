<?php
if ( ! defined( 'ABSPATH' ) ) exit;
?>
<div class="wrap strava-importer-wrap">
    <div class="strava-header">
        <div class="strava-header-inner">
            <svg class="strava-logo" viewBox="0 0 24 24" width="32" height="32" fill="#FC4C02">
                <path d="M15.387 17.944l-2.089-4.116h-3.065L15.387 24l5.15-10.172h-3.066m-7.008-5.599l2.836 5.598h4.172L10.463 0l-7 13.828h4.169"/>
            </svg>
            <h1><?php _e( 'Strava Activity Importer', 'strava-importer' ); ?></h1>
        </div>
    </div>

    <!-- Settings Section -->
    <div class="strava-card" id="strava-settings-card">
        <div class="strava-card-header" data-toggle="settings-body">
            <h2>⚙️ <?php _e( 'API Settings', 'strava-importer' ); ?></h2>
            <span class="toggle-indicator <?php echo ( $is_connected ? 'collapsed' : '' ); ?>">▼</span>
        </div>
        <div class="strava-card-body" id="settings-body" style="<?php echo $is_connected ? 'display:none;' : ''; ?>">
            <div class="strava-notice info">
                <p><strong><?php _e( 'Setup Instructions:', 'strava-importer' ); ?></strong></p>
                <ol>
                    <li><?php printf( __( 'Go to %s and create a new API application.', 'strava-importer' ), '<a href="https://www.strava.com/settings/api" target="_blank">strava.com/settings/api</a>' ); ?></li>
                    <li><?php _e( 'Set the <strong>Authorization Callback Domain</strong> to your WordPress domain (e.g., <code>yourdomain.com</code>).', 'strava-importer' ); ?></li>
                    <li><?php _e( 'Copy the <strong>Client ID</strong> and <strong>Client Secret</strong> below.', 'strava-importer' ); ?></li>
                    <li><?php _e( 'Save settings, then click <strong>Connect with Strava</strong>.', 'strava-importer' ); ?></li>
                </ol>
            </div>

            <form method="post" action="options.php" id="strava-settings-form">
                <?php settings_fields( 'strava_importer_settings' ); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="strava_client_id"><?php _e( 'Client ID', 'strava-importer' ); ?></label>
                        </th>
                        <td>
                            <input type="text" id="strava_client_id" name="strava_client_id"
                                   value="<?php echo esc_attr( $client_id ); ?>"
                                   class="regular-text" placeholder="e.g. 12345" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="strava_client_secret"><?php _e( 'Client Secret', 'strava-importer' ); ?></label>
                        </th>
                        <td>
                            <input type="password" id="strava_client_secret" name="strava_client_secret"
                                   value="<?php echo esc_attr( $client_secret ); ?>"
                                   class="regular-text" placeholder="Your client secret" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="strava_post_status"><?php _e( 'Default Post Status', 'strava-importer' ); ?></label>
                        </th>
                        <td>
                            <select id="strava_post_status" name="strava_post_status">
                                <option value="draft" <?php selected( $post_status, 'draft' ); ?>><?php _e( 'Draft', 'strava-importer' ); ?></option>
                                <option value="publish" <?php selected( $post_status, 'publish' ); ?>><?php _e( 'Published', 'strava-importer' ); ?></option>
                                <option value="private" <?php selected( $post_status, 'private' ); ?>><?php _e( 'Private', 'strava-importer' ); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="strava_post_author"><?php _e( 'Post Author', 'strava-importer' ); ?></label>
                        </th>
                        <td>
                            <?php wp_dropdown_users( array(
                                'name'     => 'strava_post_author',
                                'id'       => 'strava_post_author',
                                'selected' => $post_author,
                                'who'      => 'authors',
                            ) ); ?>
                        </td>
                    </tr>
                </table>

                <?php submit_button( __( 'Save Settings', 'strava-importer' ) ); ?>
            </form>
        </div>
    </div>

    <!-- Connection Section -->
    <div class="strava-card">
        <div class="strava-card-header">
            <h2>🔗 <?php _e( 'Strava Connection', 'strava-importer' ); ?></h2>
        </div>
        <div class="strava-card-body">
            <?php if ( $is_connected && ! empty( $athlete ) ) : ?>
                <div class="strava-athlete-info">
                    <?php if ( ! empty( $athlete['profile'] ) ) : ?>
                        <img src="<?php echo esc_url( $athlete['profile'] ); ?>" alt="Profile" class="strava-avatar" />
                    <?php endif; ?>
                    <div class="strava-athlete-details">
                        <strong><?php echo esc_html( $athlete['firstname'] . ' ' . $athlete['lastname'] ); ?></strong>
                        <?php if ( ! empty( $athlete['city'] ) || ! empty( $athlete['country'] ) ) : ?>
                            <span class="strava-location">📍 <?php echo esc_html( implode( ', ', array_filter( array(
                                isset( $athlete['city'] ) ? $athlete['city'] : '',
                                isset( $athlete['state'] ) ? $athlete['state'] : '',
                                isset( $athlete['country'] ) ? $athlete['country'] : '',
                            ) ) ) ); ?></span>
                        <?php endif; ?>
                        <span class="strava-connected-badge">✅ <?php _e( 'Connected', 'strava-importer' ); ?></span>
                    </div>
                    <button type="button" class="button button-link-delete" id="strava-disconnect">
                        <?php _e( 'Disconnect', 'strava-importer' ); ?>
                    </button>
                </div>
            <?php else : ?>
                <?php if ( ! empty( $client_id ) && ! empty( $client_secret ) ) : ?>
                    <a href="<?php echo esc_url( $this->get_auth_url() ); ?>" class="strava-connect-btn">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="white">
                            <path d="M15.387 17.944l-2.089-4.116h-3.065L15.387 24l5.15-10.172h-3.066m-7.008-5.599l2.836 5.598h4.172L10.463 0l-7 13.828h4.169"/>
                        </svg>
                        <?php _e( 'Connect with Strava', 'strava-importer' ); ?>
                    </a>
                <?php else : ?>
                    <div class="strava-notice warning">
                        <p><?php _e( 'Please enter your Client ID and Client Secret in the settings above before connecting.', 'strava-importer' ); ?></p>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Import Section -->
    <?php if ( $is_connected ) : ?>
    <div class="strava-card">
        <div class="strava-card-header">
            <h2>📥 <?php _e( 'Import Activities', 'strava-importer' ); ?></h2>
        </div>
        <div class="strava-card-body">
            <div class="strava-toolbar">
                <button type="button" class="button button-primary" id="strava-load-activities">
                    <?php _e( 'Load Activities from Strava', 'strava-importer' ); ?>
                </button>
                <button type="button" class="button" id="strava-import-selected" style="display:none;">
                    <?php _e( 'Import Selected', 'strava-importer' ); ?> (<span id="selected-count">0</span>)
                </button>
                <span class="spinner" id="strava-spinner"></span>
            </div>

            <div id="strava-activities-container">
                <div class="strava-empty-state" id="strava-empty-state">
                    <p>🏃 <?php _e( 'Click "Load Activities" to fetch your recent Strava activities.', 'strava-importer' ); ?></p>
                </div>
                <table class="wp-list-table widefat striped" id="strava-activities-table" style="display:none;">
                    <thead>
                        <tr>
                            <th class="check-column">
                                <input type="checkbox" id="strava-select-all" />
                            </th>
                            <th><?php _e( 'Activity', 'strava-importer' ); ?></th>
                            <th><?php _e( 'Type', 'strava-importer' ); ?></th>
                            <th><?php _e( 'Date', 'strava-importer' ); ?></th>
                            <th><?php _e( 'Distance', 'strava-importer' ); ?></th>
                            <th><?php _e( 'Duration', 'strava-importer' ); ?></th>
                            <th><?php _e( 'Elevation', 'strava-importer' ); ?></th>
                            <th><?php _e( 'Photos', 'strava-importer' ); ?></th>
                            <th><?php _e( 'Status', 'strava-importer' ); ?></th>
                        </tr>
                    </thead>
                    <tbody id="strava-activities-list"></tbody>
                </table>
                <div class="strava-pagination" id="strava-pagination" style="display:none;">
                    <button type="button" class="button" id="strava-prev-page" disabled>← <?php _e( 'Previous', 'strava-importer' ); ?></button>
                    <span id="strava-page-info"><?php _e( 'Page 1', 'strava-importer' ); ?></span>
                    <button type="button" class="button" id="strava-next-page">→ <?php _e( 'Next', 'strava-importer' ); ?></button>
                </div>
            </div>

            <!-- Import progress -->
            <div id="strava-import-progress" style="display:none;">
                <div class="strava-progress-bar">
                    <div class="strava-progress-fill" id="strava-progress-fill"></div>
                </div>
                <div class="strava-progress-text" id="strava-progress-text"></div>
                <div class="strava-import-log" id="strava-import-log"></div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
