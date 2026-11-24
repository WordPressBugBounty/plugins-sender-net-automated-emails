<?php

if (!defined('ABSPATH')) exit;

class Sender_Shop_Export_Failure extends RuntimeException {}

/** One durable shop export, executed as small Action Scheduler callbacks. */
class Sender_Shop_Export
{
    const ACTION = 'sender_export_shop_data_batch';
    const CRON_ACTION = 'sender_export_shop_data_batch_cron';
    const RECOVERY_ACTION = 'sender_recover_shop_export';
    const GROUP = 'sender-shop-export';
    const LEASE_SECONDS = 120;
    const MAX_FAILURES = 3;
    const PREPARE_SECONDS = 10;
    const REQUEST_BUDGET_SECONDS = 50;

    private const ATTEMPT_STOPPED = 499;
    private const CHECKPOINT_HEADROOM_BYTES = 2048;
    private const CUSTOMER_PAGE_SIZE = 200;

    private const PHASE_COMPLETED_CUSTOMERS = 'completed_customers';
    private const PHASE_OTHER_CUSTOMERS = 'other_customers';
    private const PHASE_REGISTERED_CUSTOMERS = 'registered_customers';
    private const PHASE_ORDERS = 'orders';
    private const PHASES = [
        self::PHASE_COMPLETED_CUSTOMERS,
        self::PHASE_OTHER_CUSTOMERS,
        self::PHASE_REGISTERED_CUSTOMERS,
        self::PHASE_ORDERS,
    ];

    private $exporter;
    private $api;
    private $job = [];

    public function __construct($exporter, $api)
    {
        $this->exporter = $exporter;
        $this->api = $api;
        add_action(self::ACTION, [$this, 'run'], 10, 2);
        add_action(self::CRON_ACTION, [$this, 'runCron'], 10, 2);
        add_action(self::RECOVERY_ACTION, [$this, 'recover'], 10, 1);
    }

    private static function schedulerAvailable(): bool
    {
        return function_exists('as_schedule_single_action') && function_exists('as_get_scheduled_actions')
            && function_exists('as_has_scheduled_action') && did_action('action_scheduler_init');
    }

    public static function nextScheduled(array $job)
    {
        if (empty($job['job_id'])) return false;
        $args = [$job['job_id'], (int) ($job['work_seq'] ?? 0)];
        $next = wp_next_scheduled(self::CRON_ACTION, $args);
        if (self::schedulerAvailable()) {
            $scheduled = as_next_scheduled_action(self::ACTION, $args, self::GROUP);
            if ($scheduled) $next = $next ? min($next, $scheduled === true ? time() : $scheduled)
                : ($scheduled === true ? time() : $scheduled);
        }
        return $next;
    }

    /** Adopt the old start hook, but never execute the whole export inside it. */
    public function start(): bool
    {
        $state = Sender_Helper::getSyncState();
        if (($state['format'] ?? null) === 2) {
            if (!in_array($state['status'], ['queued', 'running'], true)) return false;
            return $this->enqueue($state);
        }
        if (($state['status'] ?? '') !== 'queued') return false;
        $job = Sender_Helper::claimSyncJob($reason, [
            'format' => 2, 'phase' => self::PHASE_COMPLETED_CUSTOMERS, 'cursor' => '',
            'work_seq' => 0, 'attempt_id' => null, 'lease_until' => 0, 'operation' => 'scheduled',
            'failures' => 0, 'recoveries' => 0, 'batches_accepted' => 0,
            'customers_accepted' => 0, 'orders_accepted' => 0, 'store' => (string) get_option('sender_store_register'),
        ]);
        $this->recordWorker($reason);
        if (!$job) return false;
        wp_clear_scheduled_hook('sender_export_shop_data_cron');
        $this->exporter->logShopExport('Start', 'Resumable shop export scheduled.');
        return $this->enqueue($job);
    }

    private function enqueue(array $job, int $delay = 0): bool
    {
        if (!in_array($job['status'], ['queued', 'running'], true)) return false;
        $due = max(time() + $delay, (int) ($job['not_before'] ?? 0));
        $args = [$job['job_id'], (int) $job['work_seq']];
        if (self::schedulerAvailable()) {
            $exists = as_has_scheduled_action(self::ACTION, $args, self::GROUP);
            // Older AS releases ignore arguments for $unique. Our explicit
            // lookup plus the atomic attempt claim also fences concurrent enqueues.
            $scheduled = $exists || ($due <= time() && function_exists('as_enqueue_async_action')
                ? as_enqueue_async_action(self::ACTION, $args, self::GROUP, false)
                : as_schedule_single_action($due, self::ACTION, $args, self::GROUP, false));
        } else {
            $scheduled = wp_next_scheduled(self::CRON_ACTION, $args)
                || wp_schedule_single_event($due, self::CRON_ACTION, $args);
        }
        $watchdog = $this->ensureRecovery($job);
        if (!$scheduled || !$watchdog) {
            Sender_Helper::changeSyncJob($job['job_id'], static function ($current) {
                $current['last_error'] = 'scheduling_failed';
                return $current;
            });
        }
        return (bool) ($scheduled && $watchdog);
    }

    private function ensureRecovery(array $job, bool $fromWatchdog = false): bool
    {
        if (self::schedulerAvailable()) {
            if ($fromWatchdog) {
                $pending = as_get_scheduled_actions(['hook' => self::RECOVERY_ACTION, 'args' => [$job['job_id']],
                    'group' => self::GROUP, 'status' => 'pending', 'per_page' => 1], 'ids');
                // The current watchdog is in progress. Schedule its successor
                // before recovery, without a recurring action resurrecting a closed job.
                return (bool) ($pending || as_schedule_single_action(time() + self::LEASE_SECONDS,
                    self::RECOVERY_ACTION, [$job['job_id']], self::GROUP, false));
            }
            return (bool) (as_has_scheduled_action(self::RECOVERY_ACTION, [$job['job_id']], self::GROUP)
                || as_schedule_single_action(time() + self::LEASE_SECONDS,
                    self::RECOVERY_ACTION, [$job['job_id']], self::GROUP, false));
        }
        return (bool) (wp_next_scheduled(self::RECOVERY_ACTION, [$job['job_id']])
            || wp_schedule_single_event(time() + self::LEASE_SECONDS, self::RECOVERY_ACTION, [$job['job_id']]));
    }

    public static function clearScheduled(array $job): void
    {
        if (empty($job['job_id'])) return;
        $args = [$job['job_id'], (int) ($job['work_seq'] ?? 0)];
        wp_clear_scheduled_hook(self::ACTION, $args);
        wp_clear_scheduled_hook(self::CRON_ACTION, $args);
        wp_clear_scheduled_hook(self::RECOVERY_ACTION, [$job['job_id']]);
        if (self::schedulerAvailable()) {
            as_unschedule_all_actions(self::ACTION, $args, self::GROUP);
            as_unschedule_all_actions(self::RECOVERY_ACTION, [$job['job_id']], self::GROUP);
        }
    }

    /** A stale attempt loses ownership before a recovery action is scheduled. */
    public function recover($jobId): void
    {
        $current = Sender_Helper::getSyncState();
        if (($current['job_id'] ?? '') !== (string) $jobId) {
            self::clearScheduled(['job_id' => (string) $jobId, 'work_seq' => 0]);
            return;
        }
        if (in_array($current['status'], ['queued', 'running', 'cancelling'], true)) {
            $this->ensureRecovery($current, true);
        }
        $job = Sender_Helper::changeSyncJob((string) $jobId, static function ($current) {
            if (($current['format'] ?? null) !== 2) return false;
            if ($current['status'] === 'cancelling') return false;
            if (!in_array($current['status'], ['queued', 'running'], true)) return $current;
            if (!empty($current['attempt_id'])) {
                if ((int) $current['lease_until'] > time()) return false;
                $current['recoveries'] = (int) ($current['recoveries'] ?? 0) + 1;
                $current['work_seq']++;
                $current['attempt_id'] = null;
                $current['lease_until'] = 0;
                $current['last_error'] = 'worker_interrupted';
                $current['operation'] = 'recovering';
                $current['updated_at'] = current_time('mysql');
                if ($current['recoveries'] > self::MAX_FAILURES) {
                    $current['status'] = 'failed';
                    $current['last_error'] = 'repeated_worker_interruption';
                }
            }
            return $current;
        });
        if (!$job) return;
        if (!in_array($job['status'], ['queued', 'running'], true)) {
            self::clearScheduled($job);
            return;
        }
        $this->enqueue($job);
        // The successor watchdog was scheduled before recovery work began.
    }

    public function repair(): void
    {
        $job = Sender_Helper::getSyncState();
        if (($job['format'] ?? null) === 2 && !empty($job['job_id'])) $this->recover($job['job_id']);
    }

    private function recordWorker(string $reason): void
    {
        update_option(Sender_Helper::SYNC_WORKER_DEBUG_OPTION, ['entered_at_utc' => gmdate('Y-m-d H:i:s'),
            'source' => defined('DOING_CRON') && DOING_CRON ? 'wp_cron' : 'action_scheduler',
            'claim_result' => $reason], false);
    }

    private function save(array $values): array
    {
        $token = $this->job['attempt_id'];
        $next = Sender_Helper::changeSyncJob($this->job['job_id'], static function ($current) use ($token, $values) {
            if ($token === null || ($current['attempt_id'] ?? null) !== $token
                || !in_array($current['status'], ['running', 'cancelling'], true)) return false;
            if ($current['status'] === 'cancelling') {
                $values = ['status' => 'cancelled', 'operation' => 'cancelled', 'pending_batch' => null,
                    'attempt_id' => null, 'lease_until' => 0];
            }
            return array_merge($current, $values, ['updated_at' => current_time('mysql')]);
        });
        if (!$next) throw new RuntimeException('Shop export attempt no longer owns this job.', self::ATTEMPT_STOPPED);
        if ($next['status'] === 'cancelled') {
            self::clearScheduled($next);
            throw new RuntimeException('Shop export cancelled.', self::ATTEMPT_STOPPED);
        }
        return $this->job = $next;
    }

    public function runCron($jobId, $sequence): void
    {
        try { $this->run($jobId, $sequence); }
        catch (Sender_Shop_Export_Failure $error) {
            // The job already records this failure. Let unrelated WP-Cron events run.
        }
    }

    public function run($jobId, $sequence): void
    {
        $token = wp_generate_uuid4();
        $job = Sender_Helper::changeSyncJob((string) $jobId, static function ($current) use ($sequence, $token) {
            if (($current['format'] ?? null) !== 2 || (int) $current['work_seq'] !== (int) $sequence
                || !in_array($current['status'], ['queued', 'running'], true)
                || !empty($current['attempt_id']) || ($current['not_before'] ?? 0) > time()) return false;
            return array_merge($current, ['status' => 'running', 'attempt_id' => $token,
                'lease_until' => time() + self::LEASE_SECONDS, 'updated_at' => current_time('mysql')]);
        });
        $this->recordWorker($job ? 'claimed' : 'not_claimed');
        if (!$job) return;
        $this->job = $job;
        $released = false;
        register_shutdown_function(function () use (&$released, $jobId, $token) {
            if ($released) return;
            // An ordinary PHP fatal can release immediately. A hard kill is
            // recovered by the watchdog which was scheduled before work began.
            $stopped = Sender_Helper::changeSyncJob((string) $jobId, static function ($current) use ($token) {
                if (($current['attempt_id'] ?? null) !== $token) return false;
                $current['lease_until'] = 0;
                return $current;
            });
            if ($stopped) $this->recover($jobId);
        });
        try {
            if ((string) get_option('sender_store_register') !== $job['store']
                || !get_option('sender_api_key') || get_option('sender_account_disconnected')) {
                $this->stop('connection_changed');
                return;
            }
            $phaseIndex = array_search($job['phase'] ?? null, self::PHASES, true);
            if ($phaseIndex === false) {
                $this->stop('unknown_phase');
                return;
            }
            if (!$this->ensureRecovery($job)) {
                $this->defer('scheduling_failed', 60);
                return;
            }
            $this->initialize();
            if (get_transient(Sender_Helper::TRANSIENT_SENDER_X_RATE)) {
                $this->defer('rate_limited', 60);
                return;
            }
            if (empty($this->job['pending_batch'])) $this->prepare(self::PHASES[$phaseIndex + 1] ?? null);
            if ($this->job['status'] === 'completed') return;
            if (empty($this->job['attempt_id'])) return;
            if (empty($this->job['pending_batch'])) {
                $this->yieldJob();
                return;
            }
            if ($this->latestRequestStart() <= microtime(true) + 1) {
                $this->deferForWorkerTime();
                return;
            }
            $this->save(['operation' => 'sending_batch']);
            $pending = $this->job['pending_batch'];
            $result = $this->api->senderExportBatch($pending['payload']);
            $lastResponse = ['http_status' => $result['http_status'], 'reason' => $result['reason'],
                'at_utc' => gmdate('Y-m-d H:i:s')];
            if (!$result['accepted']) {
                $this->save(['last_response' => $lastResponse]);
                if ($result['http_status'] === 0 || $result['http_status'] === 429 || $result['http_status'] >= 500) {
                    $this->defer($result['reason'], $result['retry_after']);
                } else $this->stop($result['reason']);
                return;
            }
            $kind = isset($pending['payload']['orders']) ? 'orders' : 'customers';
            $this->save(['last_response' => $lastResponse, 'cursor' => $pending['cursor'], 'pending_batch' => null,
                $kind . '_accepted' => $this->job[$kind . '_accepted'] + count($pending['payload'][$kind]),
                'batches_accepted' => $this->job['batches_accepted'] + 1,
                'failures' => 0, 'recoveries' => 0, 'time_deferrals' => 0, 'last_error' => null, 'operation' => 'batch_accepted']);
            $this->exporter->logShopExport('Batch accepted', $kind . ' | rows: ' . count($pending['payload'][$kind])
                . ' | batch: ' . $this->job['batches_accepted']);
            $this->yieldJob();
        } catch (Throwable $error) {
            if ($error instanceof Sender_Shop_Export_Failure) throw $error;
            if ($error->getCode() !== self::ATTEMPT_STOPPED) {
                try { $this->defer('batch_preparation_failed', 60); }
                catch (Sender_Shop_Export_Failure $terminal) { throw $terminal; }
                catch (Throwable $ignored) {}
            }
        } finally {
            $released = true;
        }
    }

    private function stop(string $reason): void
    {
        $this->save(['status' => 'failed', 'operation' => 'failed', 'last_error' => $reason,
            'attempt_id' => null, 'lease_until' => 0]);
        self::clearScheduled($this->job);
        $this->exporter->logShopExport('Export Failed', $reason);
        throw new Sender_Shop_Export_Failure($reason);
    }

    private function defer(string $reason, int $delay): void
    {
        $count = (int) $this->job['failures'] + 1;
        if ($count > self::MAX_FAILURES) { $this->stop($reason); return; }
        $this->save(['failures' => $count, 'last_error' => $reason]);
        $this->yieldJob(max($delay, min(900, 30 * (2 ** $count))));
    }

    private function yieldJob(int $delay = 0): void
    {
        $this->save(['attempt_id' => null, 'lease_until' => 0,
            'work_seq' => $this->job['work_seq'] + 1, 'not_before' => time() + $delay,
            'operation' => 'scheduled']);
        $this->enqueue($this->job, $delay);
    }

    private function deferForWorkerTime(): void
    {
        $count = (int) ($this->job['time_deferrals'] ?? 0);
        if ($count >= self::MAX_FAILURES) {
            $this->stop('insufficient_worker_time');
            return;
        }
        $this->save(['time_deferrals' => $count + 1, 'last_error' => 'insufficient_worker_time']);
        $this->yieldJob(5 * (3 ** $count));
    }

    private static function orderTable(): array
    {
        global $wpdb;
        return Sender_WooCommerce::usesHpos() ? [$wpdb->prefix . 'wc_orders', 'id', 'type', 'status', 'billing_email']
            : [$wpdb->posts, 'ID', 'post_type', 'post_status', null];
    }

    private function initialize(): void
    {
        if (isset($this->job['bounds'])) return;
        $this->save(['operation' => 'initializing']);
        global $wpdb;
        list($table, $id, $type) = self::orderTable();
        $lastOrderId = $wpdb->get_var("SELECT MAX($id) FROM $table WHERE $type = 'shop_order'");
        if ($wpdb->last_error) {
            throw new RuntimeException('Could not read the order export boundary.');
        }
        $lastUserId = $wpdb->get_var("SELECT MAX(ID) FROM {$wpdb->users}");
        if ($wpdb->last_error) {
            throw new RuntimeException('Could not read the user export boundary.');
        }
        $this->save(['bounds' => ['orders' => (int) $lastOrderId, 'users' => (int) $lastUserId], 'stage' => 'customers']);
    }

    private function databasePacketLimit(): ?int
    {
        global $wpdb;
        $packetLimit = (int) $wpdb->get_var('SELECT @@SESSION.max_allowed_packet');
        return $packetLimit > 0 ? $packetLimit : null;
    }

    private function prepare(?string $nextPhase): void
    {
        $deadline = min(microtime(true) + self::PREPARE_SECONDS, $this->latestRequestStart());
        if ($deadline <= microtime(true) + 1) {
            $this->deferForWorkerTime();
            return;
        }
        $this->save(['operation' => 'loading_page']);
        $page = $this->page();
        if (!$page) {
            if ($nextPhase === null) {
                $this->save(['status' => 'completed', 'operation' => 'completed', 'attempt_id' => null,
                    'lease_until' => 0, 'synced_at' => current_time('mysql')]);
                self::clearScheduled($this->job);
                update_option('sender_wocommerce_sync', true);
                update_option('sender_synced_data_date', $this->job['synced_at']);
                update_option(Sender_Helper::TRANSIENT_SYNC_FINISHED, true, false);
                delete_transient(Sender_Helper::TRANSIENT_SYNC_IN_PROGRESS);
                $this->exporter->logShopExport('Completed', 'Sender export finished successfully');
                return;
            }
            $this->save(['phase' => $nextPhase, 'time_deferrals' => 0, 'cursor' => $nextPhase === self::PHASE_OTHER_CUSTOMERS ? '' : 0,
                'stage' => $nextPhase === self::PHASE_ORDERS ? 'orders' : 'customers']);
            return;
        }
        $payload = [];
        $processed = 0;
        $cursor = $this->job['cursor'];
        $this->save(['operation' => 'preparing_batch']);
        $packetLimit = $this->databasePacketLimit();
        // Save the batch before sending it. Allow for SQL escaping and later progress updates.
        $bytesLeft = $packetLimit === null ? null
            : intdiv($packetLimit, 2) - strlen(serialize($this->job)) - self::CHECKPOINT_HEADROOM_BYTES;
        $measureSingleRecord = false;
        foreach ($page as $row) {
            if ($processed && microtime(true) >= $deadline) break;
            if ($this->job['phase'] === self::PHASE_ORDERS) {
                $order = wc_get_order($row['id']);
                if (!$order) throw new RuntimeException('Order disappeared during export.');
                $records = [$this->exporter->prepareOrder($order)];
            } elseif ($this->job['phase'] === self::PHASE_REGISTERED_CUSTOMERS) {
                $records = $this->exporter->prepareRegisteredCustomers([$row['id']]);
            } else {
                $order = wc_get_order($row['id']);
                if (!$order) throw new RuntimeException('Customer order disappeared during export.');
                $customer = ['email' => $order->get_billing_email(), 'first_name' => $order->get_billing_first_name(),
                    'last_name' => $order->get_billing_last_name(), 'phone' => $order->get_billing_phone()];
                foreach (['sender_newsletter' => 'newsletter', Sender_Helper::EMAIL_MARKETING_META_KEY => Sender_Helper::EMAIL_MARKETING_META_KEY] as $meta => $key) {
                    $value = $order->get_meta($meta, true);
                    if ($value !== '') $customer[$key] = $value;
                }
                $list = $this->job['phase'] === self::PHASE_COMPLETED_CUSTOMERS ? get_option('sender_customers_list') : null;
                $records = $this->exporter->prepareWooCustomers([$customer], $list);
            }
            if ($records && $bytesLeft !== null) {
                $bytes = strlen(serialize($records)) + 16;
                if ($bytes > $bytesLeft) {
                    if ($payload) break;
                    $measureSingleRecord = true;
                }
                $bytesLeft -= $bytes;
            }
            foreach ($records as $record) $payload[] = $record;
            $cursor = $row['cursor'];
            $processed++;
            if ($measureSingleRecord) break;
            // If the host hides the limit, checkpoint one eligible row before sending it.
            if ($bytesLeft === null && $payload) break;
        }
        $kind = $this->job['phase'] === self::PHASE_ORDERS ? 'orders' : 'customers';
        if (!$payload) { $this->save(['cursor' => $cursor, 'time_deferrals' => 0]); return; }
        $checkpoint = ['pending_batch' => ['id' => wp_generate_uuid4(), 'cursor' => $cursor,
            'payload' => [$kind => $payload]], 'operation' => 'batch_prepared', 'time_deferrals' => 0];
        if ($measureSingleRecord && (strlen(serialize($payload)) >= $packetLimit ||
            Sender_Helper::syncStateWriteBytes(array_merge($this->job, $checkpoint)) + self::CHECKPOINT_HEADROOM_BYTES >= $packetLimit)) {
            $this->stop('export_record_too_large');
            return;
        }
        $this->save($checkpoint);
    }

    protected function latestRequestStart(): float
    {
        if (PHP_SAPI === 'cli' || !isset($_SERVER['REQUEST_TIME_FLOAT'])) return INF;
        return (float) $_SERVER['REQUEST_TIME_FLOAT'] + self::REQUEST_BUDGET_SECONDS - Sender_API::SHOP_EXPORT_REQUEST_TIMEOUT;
    }

    private function page(): array
    {
        global $wpdb;
        $phase = $this->job['phase'];
        if ($phase === self::PHASE_REGISTERED_CUSTOMERS) {
            $args = ['number' => self::CUSTOMER_PAGE_SIZE, 'fields' => 'ID', 'count_total' => false, 'orderby' => 'ID', 'order' => 'ASC'];
            $args['sender_export_job'] = $this->job['job_id'];
            $roles = array_filter((array) get_option('sender_role_group_map'));
            if (get_option('sender_enable_role_group_mapping') && $roles) $args['role__in'] = array_keys($roles);
            else $args['role'] = 'customer';
            $filter = function ($query) use ($wpdb) {
                if (($query->query_vars['sender_export_job'] ?? null) !== $this->job['job_id']) return;
                $query->query_where .= $wpdb->prepare(" AND {$wpdb->users}.ID > %d AND {$wpdb->users}.ID <= %d",
                    (int) $this->job['cursor'], $this->job['bounds']['users']);
            };
            add_action('pre_user_query', $filter);
            try { $ids = (new WP_User_Query($args))->get_results(); }
            finally { remove_action('pre_user_query', $filter); }
            if ($wpdb->last_error) throw new RuntimeException('Could not read registered customer page.');
            return array_map(static function ($id) { return ['id' => (int) $id, 'cursor' => (int) $id]; }, $ids);
        }
        list($table, $id, $type, $status, $email) = self::orderTable();
        if ($phase === self::PHASE_ORDERS) {
            $ids = $wpdb->get_col($wpdb->prepare("SELECT $id FROM $table WHERE $type = 'shop_order'
                AND $id > %d AND $id <= %d AND $status NOT IN ('trash', 'auto-draft') ORDER BY $id ASC LIMIT 50",
                (int) $this->job['cursor'], $this->job['bounds']['orders']));
            $rows = array_map(static function ($id) { return ['id' => (int) $id, 'cursor' => (int) $id]; }, $ids);
        } else {
            if ($email === null) {
                $join = " JOIN {$wpdb->postmeta} pm ON pm.post_id = o.$id AND pm.meta_key = '_billing_email'";
                $email = 'pm.meta_value';
            } else {
                $join = " LEFT JOIN {$wpdb->prefix}wc_order_addresses billing ON billing.order_id = o.$id
                    AND billing.address_type = 'billing' AND (o.$email IS NULL OR o.$email = '')";
                $email = "COALESCE(NULLIF(o.$email, ''), billing.email)";
            }
            $operator = $phase === self::PHASE_COMPLETED_CUSTOMERS ? 'IN' : 'NOT IN';
            $rows = $wpdb->get_results($wpdb->prepare("SELECT MAX(o.$id) AS id, LOWER($email) AS page_key
                FROM $table o $join WHERE o.$type = 'shop_order' AND o.$id <= %d
                AND o.$status $operator ('wc-completed','wc-on-hold','wc-processing')
                AND LOWER($email) > %s
                GROUP BY LOWER($email) ORDER BY LOWER($email) ASC LIMIT %d",
                $this->job['bounds']['orders'], (string) $this->job['cursor'], self::CUSTOMER_PAGE_SIZE), ARRAY_A);
            $rows = array_map(static function ($row) {
                return ['id' => (int) $row['id'], 'cursor' => $row['page_key']];
            }, $rows);
        }
        if ($wpdb->last_error) throw new RuntimeException('Could not read shop export page.');
        return $rows;
    }
}
