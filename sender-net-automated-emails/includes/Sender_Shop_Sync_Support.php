<?php

if (!defined('ABSPATH')) {
    exit;
}

/** Authenticated support operations share the normal sync's job ownership rules. */
class Sender_Shop_Sync_Support
{
    private static function snapshot()
    {
        global $wpdb;
        // getSyncState() recovers expired cancellations. Inspection must not do that.
        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            Sender_Helper::SYNC_STATE_OPTION
        ));
        if ($wpdb->last_error !== '') {
            return new WP_Error('sync_state_unavailable', 'Could not read shop sync state.', ['status' => 503]);
        }
        $state = maybe_unserialize($value);
        return is_array($state) ? $state : [];
    }

    private static function identifier($value)
    {
        return is_string($value) && preg_match('/\A[a-zA-Z0-9_-]{1,128}\z/', $value) ? $value : null;
    }

    public static function status()
    {
        $state = self::snapshot();
        if (is_wp_error($state)) return $state;
        $statuses = ['queued', 'running', 'cancelling', 'cancelled', 'failed', 'completed'];
        $status = in_array($state['status'] ?? null, $statuses, true) ? $state['status'] : 'unknown';
        $stages = ['', 'starting', 'customers', 'orders', 'products'];
        $updated = Sender_Helper::validateSyncDate($state['updated_at'] ?? null);
        $updatedEpoch = $updated ? (int) get_gmt_from_date($updated, 'U') : null;
        // Report eligibility for recovery; inspection does not recover the job.
        // Preserve unknown diagnostic time for an explicitly null modern field.
        $cancelledAt = array_key_exists('cancel_requested_at', $state) && $state['cancel_requested_at'] === null
            ? null : Sender_Helper::getSyncCancellationRequestedAt($state);
        $scheduled = wp_next_scheduled('sender_export_shop_data_cron');
        $worker = get_option(Sender_Helper::SYNC_WORKER_DEBUG_OPTION, []);
        $schedule = get_option(Sender_Helper::SYNC_SCHEDULE_DEBUG_OPTION, []);
        $worker = is_array($worker) ? $worker : [];
        $schedule = is_array($schedule) ? $schedule : [];
        return new WP_REST_Response([
            'checked_at_utc' => gmdate('Y-m-d H:i:s'),
            'plugin_version' => defined('SENDER_PLUGIN_VERSION') ? SENDER_PLUGIN_VERSION : null,
            'job' => [
                'id' => self::identifier($state['job_id'] ?? null),
                'status' => $status,
                'stage' => in_array($state['stage'] ?? null, $stages, true) ? $state['stage'] : null,
                'updated_at_site' => $updated,
                'idle_seconds' => $updatedEpoch ? max(0, time() - $updatedEpoch) : null,
                'cancellation_retry_after_seconds' => $status === 'cancelling' && $cancelledAt
                    ? max(0, $cancelledAt + Sender_Helper::SYNC_CANCELLATION_TIMEOUT - time()) : null,
            ],
            'scheduler' => [
                'scheduled_at_utc' => $scheduled ? gmdate('Y-m-d H:i:s', $scheduled) : null,
                'automatic_cron_disabled' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
                'last_worker_entered_at_utc' => Sender_Helper::validateSyncDate($worker['entered_at_utc'] ?? null),
                'last_worker_claim_result' => self::identifier($worker['claim_result'] ?? null),
                'last_schedule_attempt_at_utc' => Sender_Helper::validateSyncDate($schedule['attempted_at_utc'] ?? null),
                'last_schedule_result' => in_array($schedule['result'] ?? null, ['accepted', 'failed'], true)
                    ? $schedule['result'] : null,
                'last_schedule_error_code' => self::identifier($schedule['error_code'] ?? null),
            ],
        ], 200);
    }

    public static function cancel(array $data)
    {
        $id = self::identifier($data['job_id'] ?? null);
        if ($id === null) {
            return new WP_Error('invalid_sync_job', 'A valid job_id from get-shop-sync-status is required.', ['status' => 400]);
        }
        $state = self::snapshot();
        if (is_wp_error($state)) return $state;
        if (($state['job_id'] ?? null) !== $id) {
            return new WP_Error('sync_job_changed', 'The shop sync job changed. Read its status again.', ['status' => 409]);
        }
        if (!Sender_Helper::cancelSyncJob($id)) {
            return new WP_Error('sync_not_cancelled', 'This shop sync job cannot be cancelled. Read its status again.', ['status' => 409]);
        }
        $response = self::status();
        if (!is_wp_error($response)) {
            if ($response->get_data()['job']['id'] !== $id) {
                return new WP_Error('sync_job_changed', 'The shop sync job changed. Read its status again.', ['status' => 409]);
            }
            $response->set_status($response->get_data()['job']['status'] === 'cancelling' ? 202 : 200);
        }
        return $response;
    }
}
