(function ($) {
    'use strict';

    var currentPage = 1;
    var perPage = 30;
    var selectedIds = new Set();

    // =====================
    // Card toggle
    // =====================
    $(document).on('click', '.strava-card-header[data-toggle]', function () {
        var targetId = $(this).data('toggle');
        var $body = $('#' + targetId);
        var $indicator = $(this).find('.toggle-indicator');
        $body.slideToggle(200);
        $indicator.toggleClass('collapsed');
    });

    // =====================
    // Load activities
    // =====================
    $('#strava-load-activities').on('click', function () {
        currentPage = 1;
        selectedIds.clear();
        updateSelectedCount();
        loadActivities();
    });

    $('#strava-next-page').on('click', function () {
        currentPage++;
        loadActivities();
    });

    $('#strava-prev-page').on('click', function () {
        if (currentPage > 1) {
            currentPage--;
            loadActivities();
        }
    });

    function loadActivities() {
        var $spinner = $('#strava-spinner');
        var $table = $('#strava-activities-table');
        var $empty = $('#strava-empty-state');
        var $pagination = $('#strava-pagination');
        var $tbody = $('#strava-activities-list');

        $spinner.addClass('is-active');
        $empty.hide();
        $('#strava-select-all').prop('checked', false);

        $.ajax({
            url: stravaImporter.ajaxUrl,
            type: 'POST',
            data: {
                action: 'strava_fetch_activities',
                nonce: stravaImporter.nonce,
                page: currentPage,
                per_page: perPage,
            },
            success: function (response) {
                $spinner.removeClass('is-active');

                if (!response.success) {
                    alert('Error: ' + response.data);
                    return;
                }

                var activities = response.data;
                $tbody.empty();

                if (activities.length === 0) {
                    $table.hide();
                    $pagination.hide();
                    $empty.show().find('p').text('No activities found on this page.');
                    return;
                }

                activities.forEach(function (activity) {
                    $tbody.append(buildActivityRow(activity));
                });

                $table.show();
                $pagination.show();

                // Update pagination
                $('#strava-page-info').text('Page ' + currentPage);
                $('#strava-prev-page').prop('disabled', currentPage <= 1);
                $('#strava-next-page').prop('disabled', activities.length < perPage);

                // Restore checkbox state
                selectedIds.forEach(function (id) {
                    $tbody.find('input[data-id="' + id + '"]').prop('checked', true);
                });
            },
            error: function () {
                $spinner.removeClass('is-active');
                alert('Failed to fetch activities. Please try again.');
            },
        });
    }

    function buildActivityRow(activity) {
        var date = new Date(activity.start_date_local || activity.start_date);
        var dateStr = date.toLocaleDateString('en-GB', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
        });
        var timeStr = date.toLocaleTimeString('en-GB', {
            hour: '2-digit',
            minute: '2-digit',
        });

        var distance = activity.distance
            ? (activity.distance / 1000).toFixed(2) + ' km'
            : '—';
        var duration = activity.moving_time
            ? formatDuration(activity.moving_time)
            : '—';
        var elevation = activity.total_elevation_gain
            ? Math.round(activity.total_elevation_gain) + ' m'
            : '—';
        var photos = activity.total_photo_count || 0;
        var sportType = formatSportType(activity.sport_type || activity.type);
        var sportEmoji = getSportEmoji(activity.sport_type || activity.type);

        var stravaUrl = 'https://www.strava.com/activities/' + activity.id;
        var isImported = activity.already_imported;

        var statusHtml;
        if ( isImported ) {
            statusHtml = '<span class="strava-status-imported">✅ <a href="' + escHtml(activity.edit_url) + '" target="_blank">Imported</a></span>' +
                ' <button type="button" class="button button-small strava-reimport-btn" data-id="' + activity.id + '" data-post-id="' + activity.wp_post_id + '">Update</button>';
        } else {
            statusHtml = '<span class="strava-status-ready" id="status-' + activity.id + '">—</span>';
        }

        var checkboxHtml = isImported
            ? '<input type="checkbox" disabled />'
            : '<input type="checkbox" class="strava-activity-check" data-id="' + activity.id + '" />';

        var rowClass = isImported ? 'strava-row-imported' : '';

        return (
            '<tr class="' + rowClass + '" id="row-' + activity.id + '">' +
            '<td class="check-column">' + checkboxHtml + '</td>' +
            '<td class="strava-activity-name"><a href="' + stravaUrl + '" target="_blank">' + escHtml(activity.name) + '</a></td>' +
            '<td><span class="strava-sport-badge">' + sportEmoji + ' ' + sportType + '</span></td>' +
            '<td>' + dateStr + '<br><small style="color:#999">' + timeStr + '</small></td>' +
            '<td>' + distance + '</td>' +
            '<td>' + duration + '</td>' +
            '<td>' + elevation + '</td>' +
            '<td>' + (photos > 0 ? '📷 ' + photos : '—') + '</td>' +
            '<td>' + statusHtml + '</td>' +
            '</tr>'
        );
    }

    // =====================
    // Selection handling
    // =====================
    $(document).on('change', '.strava-activity-check', function () {
        var id = $(this).data('id').toString();
        if ($(this).is(':checked')) {
            selectedIds.add(id);
        } else {
            selectedIds.delete(id);
        }
        updateSelectedCount();
    });

    $('#strava-select-all').on('change', function () {
        var isChecked = $(this).is(':checked');
        $('.strava-activity-check:not(:disabled)').each(function () {
            $(this).prop('checked', isChecked);
            var id = $(this).data('id').toString();
            if (isChecked) {
                selectedIds.add(id);
            } else {
                selectedIds.delete(id);
            }
        });
        updateSelectedCount();
    });

    function updateSelectedCount() {
        var count = selectedIds.size;
        $('#selected-count').text(count);
        if (count > 0) {
            $('#strava-import-selected').show();
        } else {
            $('#strava-import-selected').hide();
        }
    }

    // =====================
    // Import selected
    // =====================
    $('#strava-import-selected').on('click', function () {
        if (selectedIds.size === 0) return;

        var ids = Array.from(selectedIds);
        var total = ids.length;
        var completed = 0;
        var errors = 0;

        var $progress = $('#strava-import-progress');
        var $fill = $('#strava-progress-fill');
        var $text = $('#strava-progress-text');
        var $log = $('#strava-import-log');

        $progress.show();
        $fill.css('width', '0%');
        $text.text('Importing 0 of ' + total + '...');
        $log.empty();

        // Disable buttons during import
        $('#strava-import-selected, #strava-load-activities').prop('disabled', true);

        function importNext(index) {
            if (index >= ids.length) {
                $text.text(
                    'Done! ' + (completed - errors) + ' imported' +
                    (errors > 0 ? ', ' + errors + ' failed' : '') + '.'
                );
                $('#strava-import-selected, #strava-load-activities').prop('disabled', false);
                selectedIds.clear();
                updateSelectedCount();
                return;
            }

            var activityId = ids[index];
            var $row = $('#row-' + activityId);
            var $status = $('#status-' + activityId);

            $row.addClass('strava-row-importing');
            $status.html('<span class="strava-status-importing">⏳ Importing...</span>');

            $.ajax({
                url: stravaImporter.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'strava_import_activity',
                    nonce: stravaImporter.nonce,
                    activity_id: activityId,
                },
                success: function (response) {
                    completed++;
                    $row.removeClass('strava-row-importing');

                    if (response.success) {
                        $row.addClass('strava-row-imported');
                        $row.find('.strava-activity-check').prop('disabled', true).prop('checked', false);
                        $status.html('<span class="strava-status-imported">✅ <a href="' + response.data.edit_url + '" target="_blank">Imported</a></span>');
                        $log.append('<div class="strava-log-success">✅ ' + escHtml(response.data.title) + ' → Post created</div>');
                    } else {
                        errors++;
                        $status.html('<span class="strava-status-error">❌ Failed</span>');
                        $log.append('<div class="strava-log-error">❌ Activity ' + activityId + ': ' + escHtml(response.data) + '</div>');
                    }

                    var pct = Math.round((completed / total) * 100);
                    $fill.css('width', pct + '%');
                    $text.text('Importing ' + completed + ' of ' + total + '...');

                    // Scroll log to bottom
                    $log.scrollTop($log[0].scrollHeight);

                    // Small delay to avoid rate limiting
                    setTimeout(function () {
                        importNext(index + 1);
                    }, 500);
                },
                error: function () {
                    completed++;
                    errors++;
                    $row.removeClass('strava-row-importing');
                    $status.html('<span class="strava-status-error">❌ Error</span>');
                    $log.append('<div class="strava-log-error">❌ Activity ' + activityId + ': Network error</div>');

                    var pct = Math.round((completed / total) * 100);
                    $fill.css('width', pct + '%');
                    $text.text('Importing ' + completed + ' of ' + total + '...');

                    setTimeout(function () {
                        importNext(index + 1);
                    }, 1000);
                },
            });
        }

        importNext(0);
    });

    // =====================
    // Reimport (Update)
    // =====================
    $(document).on('click', '.strava-reimport-btn', function () {
        if (!confirm('Update this activity with the latest data from Strava?')) {
            return;
        }

        var $btn = $(this);
        var activityId = $btn.data('id').toString();
        var postId = $btn.data('post-id');
        var $log = $('#strava-import-log');
        var $progress = $('#strava-import-progress');

        $btn.prop('disabled', true).text('Updating...');
        $progress.show();
        if ($log.is(':empty')) {
            $log.empty();
        }

        $.ajax({
            url: stravaImporter.ajaxUrl,
            type: 'POST',
            data: {
                action: 'strava_reimport_activity',
                nonce: stravaImporter.nonce,
                activity_id: activityId,
                post_id: postId,
            },
            success: function (response) {
                if (response.success) {
                    var d = response.data;
                    $btn.closest('td').html(
                        '<span class="strava-status-imported">✅ <a href="' + escHtml(d.edit_url) + '" target="_blank">Updated</a></span>' +
                        ' <button type="button" class="button button-small strava-reimport-btn" data-id="' + activityId + '" data-post-id="' + d.post_id + '">Update</button>'
                    );
                    $log.append('<div class="strava-log-info">🔄 ' + escHtml(d.title) + ' → Post updated</div>');
                } else {
                    $btn.prop('disabled', false).text('Update');
                    $log.append('<div class="strava-log-error">❌ Activity ' + activityId + ': ' + escHtml(response.data) + '</div>');
                }
                $log.scrollTop($log[0].scrollHeight);
            },
            error: function () {
                $btn.prop('disabled', false).text('Update');
                $log.append('<div class="strava-log-error">❌ Activity ' + activityId + ': Network error</div>');
                $log.scrollTop($log[0].scrollHeight);
            },
        });
    });

    // =====================
    // Disconnect
    // =====================
    $('#strava-disconnect').on('click', function () {
        if (!confirm('Are you sure you want to disconnect from Strava? You can reconnect anytime.')) {
            return;
        }

        $.ajax({
            url: stravaImporter.ajaxUrl,
            type: 'POST',
            data: {
                action: 'strava_disconnect',
                nonce: stravaImporter.nonce,
            },
            success: function () {
                location.reload();
            },
        });
    });

    // =====================
    // Utilities
    // =====================
    function formatDuration(seconds) {
        var h = Math.floor(seconds / 3600);
        var m = Math.floor((seconds % 3600) / 60);
        var s = seconds % 60;
        if (h > 0) {
            return h + ':' + pad(m) + ':' + pad(s);
        }
        return m + ':' + pad(s);
    }

    function pad(n) {
        return n < 10 ? '0' + n : '' + n;
    }

    function formatSportType(type) {
        if (!type) return 'Activity';
        return type.replace(/([A-Z])/g, ' $1').trim();
    }

    function getSportEmoji(type) {
        var map = {
            Run: '🏃',
            TrailRun: '🥾',
            VirtualRun: '🏃',
            Walk: '🚶',
            Hike: '🥾',
            Ride: '🚴',
            MountainBikeRide: '🚵',
            GravelRide: '🚴',
            VirtualRide: '🚴',
            EBikeRide: '🔋',
            Swim: '🏊',
            WeightTraining: '🏋️',
            Workout: '💪',
            Yoga: '🧘',
            Crossfit: '🏋️',
            Elliptical: '🏋️',
            StairStepper: '🏋️',
            RockClimbing: '🧗',
            NordicSki: '⛷️',
            AlpineSki: '⛷️',
            Snowboard: '🏂',
            IceSkate: '⛸️',
            Rowing: '🚣',
            Kayaking: '🛶',
            Canoeing: '🛶',
            Windsurf: '🏄',
            Kitesurf: '🏄',
            Surf: '🏄',
            Skateboard: '🛹',
            Soccer: '⚽',
            Tennis: '🎾',
            Badminton: '🏸',
            Pickleball: '🏓',
            Golf: '⛳',
            Sail: '⛵',
            Handcycle: '♿',
            Wheelchair: '♿',
            Velomobile: '🚲',
        };
        return map[type] || '🏅';
    }

    function escHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

})(jQuery);
