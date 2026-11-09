<?php
/**
 * NextSafar News Sync — FIXED VERSION
 * ✅ FIX 1: schedule_cron() now called in init()
 * ✅ FIX 2: All option keys aligned with settings.php
 * ✅ FIX 3: check_missed_cron() works in admin too
 * ✅ FIX 4: Better fallback for headless architecture
 */

namespace NextSafar\API;

if (!defined('ABSPATH')) exit;

class NewsSync {
    const CRON_HOOK   = 'nextsafar_news_cron_hook';
    const HOURLY_HOOK = 'nextsafar_news_hourly_tick';
    const RETRY_HOOK  = 'nextsafar_news_retry_hook';
    const BG_AJAX_ACTION = 'ns_bg_news_sync';
    const LOCK_OPTION = 'ns_news_sync_lock';
    const LAST_RUN_KEY = 'ns_news_last_cron_run';
    const POST_TYPE   = 'travelnews';

    // ✅ FIX 2: Centralized option key constants (must match settings.php)
    const OPT_AUTO_ENABLED   = 'nextsafar_news_auto';
    const OPT_SCHEDULE_HOURS = 'nextsafar_news_sync_hours';           // ← MUST MATCH settings.php
    const OPT_MAX_PUBLISH    = 'nextsafar_news_max_publish_per_run';  // ← MUST MATCH settings.php
    const OPT_MAX_DRAFTS     = 'nextsafar_news_max_draft_per_run';    // ← MUST MATCH settings.php
    const OPT_MAX_AI_CHECKS  = 'nextsafar_news_max_ai_review_per_run';// ← MUST MATCH settings.php
    const OPT_TIME_WINDOW    = 'nextsafar_news_time_window_hours';    // ← MUST MATCH settings.php
    const OPT_AI_ENABLED     = 'nextsafar_news_ai_enabled';

    private $rss_fetcher;
    private $api_fetcher;
    private $duplicate_checker;
    private $ai_rewriter;
    private $news_filter;

    public function __construct() {
        $this->rss_fetcher       = new RSSFetcher();
        $this->api_fetcher       = new NewsApiFetcher();
        $this->duplicate_checker = new DuplicateChecker();
        $this->ai_rewriter       = new AIRewriter();
        $this->news_filter       = new NewsFilter();
    }

    public static function init(): void {
        add_action(self::CRON_HOOK,   [__CLASS__, 'run_cron']);
        add_action(self::HOURLY_HOOK, [__CLASS__, 'run_hourly_tick']);
        add_action(self::RETRY_HOOK,  [__CLASS__, 'run_retry']);

        add_action('wp_ajax_' . self::BG_AJAX_ACTION,        [__CLASS__, 'handle_bg_sync']);
        add_action('wp_ajax_nopriv_' . self::BG_AJAX_ACTION, [__CLASS__, 'handle_bg_sync']);

        add_filter('cron_schedules', function ($schedules) {
            $schedules['ns_15min'] = ['interval' => 900, 'display' => 'هر ۱۵ دقیقه (NextSafar)'];
            return $schedules;
        });

        // ✅ FIX 1: Schedule cron on init (every page load ensures it's scheduled)
        add_action('init', [__CLASS__, 'maybe_schedule_cron'], 20);

        // ✅ FIX 3: Run check_missed_cron on both admin AND frontend
        add_action('admin_init', [__CLASS__, 'check_missed_cron'], 99);
        add_action('wp_loaded',  [__CLASS__, 'check_missed_cron'], 99);
    }

    /**
     * ✅ FIX 1: Ensure cron is always scheduled
     */
    public static function maybe_schedule_cron(): void {
        self::schedule_cron();
    }

    public static function schedule_cron(): void {
        // Check if auto sync is enabled
        if (get_option(self::OPT_AUTO_ENABLED, '1') !== '1') {
            self::clear_cron();
            return;
        }

        // Schedule hourly tick (runs every hour, checks if current hour is in schedule)
        if (!wp_next_scheduled(self::HOURLY_HOOK)) {
            wp_schedule_event(time() + 60, 'hourly', self::HOURLY_HOOK);
            error_log('📅 Hourly cron scheduled: ' . self::HOURLY_HOOK);
        }

        // Schedule retry cron (every 15 minutes)
        if (!wp_next_scheduled(self::RETRY_HOOK)) {
            wp_schedule_event(time() + 120, 'ns_15min', self::RETRY_HOOK);
            error_log('📅 Retry cron scheduled: ' . self::RETRY_HOOK);
        }
    }

    public static function clear_cron(): void {
        wp_clear_scheduled_hook(self::CRON_HOOK);
        wp_clear_scheduled_hook(self::HOURLY_HOOK);
        wp_clear_scheduled_hook(self::RETRY_HOOK);
        delete_transient(self::LAST_RUN_KEY);
        error_log('📅 All cron schedules cleared');
    }

    /**
     * ✅ FIX 3: Heartbeat — Detect missed hours (now works in admin too!)
     */
    public static function check_missed_cron(): void {
        if (get_option(self::OPT_AUTO_ENABLED, '1') !== '1') return;

        $hours = array_map('intval', (array) get_option(self::OPT_SCHEDULE_HOURS, [8, 14, 20]));
        
        if (empty($hours)) return;

        $now_hour = (int) wp_date('H');

        if (!in_array($now_hour, $hours, true)) return;

        $last_run_hour = get_transient(self::LAST_RUN_KEY);
        $current_hour_key = wp_date('Y-m-d-H');

        if ($last_run_hour === $current_hour_key) return;

        // Check only once every 3 minutes
        $check_key = 'ns_missed_check_' . $current_hour_key;

        if (get_transient($check_key)) return;

        set_transient($check_key, 1, 3 * MINUTE_IN_SECONDS);

        // If in wp-cron.php (Task Scheduler) → direct execution
        if (defined('DOING_CRON') && DOING_CRON) {
            error_log("🕐 Missed cron (hour {$now_hour}) — running directly in wp-cron");

            set_transient(self::LAST_RUN_KEY, $current_hour_key, HOUR_IN_SECONDS);

            @ini_set('max_execution_time', 300);
            @ini_set('memory_limit', '256M');

            self::run_cron();

            return;
        }

        // From frontend/admin → schedule an immediate cron
        error_log("🕐 Missed cron (hour {$now_hour}) — scheduling immediate cron");

        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_single_event(time() + 5, self::CRON_HOOK);
        }

        spawn_cron();
    }

    /**
     * ✅ Hourly tick — Called by wp-cron every hour
     */
    public static function run_hourly_tick(): void {
        if (get_option(self::OPT_AUTO_ENABLED, '1') !== '1') return;

        $hours    = array_map('intval', (array) get_option(self::OPT_SCHEDULE_HOURS, [8, 14, 20]));
        $now_hour = (int) wp_date('H');

        // ✅ Only run at scheduled hours
        if (!in_array($now_hour, $hours, true)) {
            error_log("⏰ Hourly tick at {$now_hour}:00 — not in schedule [" . implode(',', $hours) . "]");
            return;
        }

        $last_run_hour = get_transient(self::LAST_RUN_KEY);
        $current_hour_key = wp_date('Y-m-d-H');

        if ($last_run_hour === $current_hour_key) {
            error_log("⏰ Hourly tick at {$now_hour}:00 — already ran this hour");
            return;
        }

        set_transient(self::LAST_RUN_KEY, $current_hour_key, HOUR_IN_SECONDS);

        error_log("🕐 Schedule hour ({$now_hour}:00) — running sync");

        @ini_set('max_execution_time', 300);
        @ini_set('memory_limit', '256M');

        self::run_cron();
    }

    public static function handle_bg_sync(): void {
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], self::BG_AJAX_ACTION)) {
            wp_die('Invalid nonce');
        }

        if (get_transient(self::LOCK_OPTION)) {
            wp_send_json_error('Sync در حال اجراست. چند دقیقه صبر کنید.');
        }

        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_single_event(time() - 1, self::CRON_HOOK);
        }

        spawn_cron();

        wp_send_json_success([
            'message' => '✅ فرآیند همگام‌سازی در پس‌زمینه زمان‌بندی شد.',
            'time' => current_time('mysql'),
        ]);
    }

    public static function run_retry(): void {
        if (get_option(self::OPT_AUTO_ENABLED, '1') !== '1') return;
        if (get_option(self::OPT_AI_ENABLED, '0') !== '1') return;

        $last_retry = get_transient('ns_news_last_retry_run');

        if ($last_retry && (time() - $last_retry) < 900) return;

        set_transient('ns_news_last_retry_run', time(), 900);

        $sync   = new self();
        $budget = max(1, (int) get_option(self::OPT_MAX_PUBLISH, 5));

        $n = $sync->retry_pending_rewrites($budget);

        if ($n > 0) error_log("🔄 Retry cron: {$n} pending draft(s) rewritten & published");
    }

    public static function run_cron(): void {
        error_log('🕐 NextSafar News Cron started at ' . current_time('mysql'));

        $sync = new self();

        try {
            $r = $sync->sync_news();

            error_log('✅ News Cron completed: ' . json_encode([
                'fetched'    => $r['total_fetched'] ?? 0,
                'rss'        => $r['fetched_rss'] ?? 0,
                'api'        => $r['fetched_api'] ?? 0,
                'duplicates' => $r['duplicates'] ?? 0,
                'filtered'   => $r['filtered_out'] ?? 0,
                'created'    => $r['created'] ?? 0,
                'drafts'     => $r['drafts'] ?? 0,
                'ai_checks'  => $r['ai_checks'] ?? 0,
                'ai_used'    => $r['ai_used'] ?? 0,
            ], JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
            error_log('❌ News Cron failed: ' . $e->getMessage());
        }
    }

    private function acquire_lock(): bool {
        $lock_time = get_transient(self::LOCK_OPTION);

        if ($lock_time && (time() - $lock_time) > 600) {
            error_log('🧹 Removing stale lock (older than 10 minutes)');
            delete_transient(self::LOCK_OPTION);
        }

        if (get_transient(self::LOCK_OPTION)) {
            return false;
        }

        return set_transient(self::LOCK_OPTION, time(), 600);
    }

    private function release_lock(): void {
        delete_transient(self::LOCK_OPTION);
    }

    public function sync_news(): array {
        if (!$this->acquire_lock()) {
            error_log('🔒 News sync already running — skipping');

            return $this->build_result([
                'fetched_rss'=>0, 'fetched_api'=>0, 'total_fetched'=>0, 'duplicates'=>0,
                'filtered_out'=>0, 'created'=>0, 'drafts'=>0, 'updated'=>0, 'failed'=>0,
                'ai_used'=>0, 'ai_checks'=>0, 'retry_rewrites'=>0, 'errors'=>['locked']
            ]);
        }

        try {
            return $this->do_sync();
        } finally {
            $this->release_lock();
        }
    }

    private function do_sync(): array {
        $log_id    = $this->start_sync_log();
        $window    = max(1, (int) get_option(self::OPT_TIME_WINDOW, 24));
        $max_pub   = max(0, (int) get_option(self::OPT_MAX_PUBLISH, 5));
        $max_draft = max(0, (int) get_option(self::OPT_MAX_DRAFTS, 3));
        $max_ai    = max(0, (int) get_option(self::OPT_MAX_AI_CHECKS, 2));
        $ai_on     = get_option(self::OPT_AI_ENABLED, '0') === '1';

        error_log("📋 Sync config: window={$window}h, max_pub={$max_pub}, max_draft={$max_draft}, max_ai={$max_ai}, ai_on=" . ($ai_on ? 'yes' : 'no'));

        $stats = [
            'fetched_rss'=>0, 'fetched_api'=>0, 'total_fetched'=>0, 'duplicates'=>0,
            'filtered_out'=>0, 'created'=>0, 'drafts'=>0, 'updated'=>0, 'failed'=>0,
            'ai_used'=>0, 'ai_checks'=>0, 'retry_rewrites'=>0, 'errors'=>[]
        ];

        $published_by_retry = 0;

        if ($ai_on) {
            $published_by_retry = $this->retry_pending_rewrites($max_pub);
            $stats['retry_rewrites'] = $published_by_retry;
        }

        $publish_budget = max(0, $max_pub - $published_by_retry);

        $pending = get_posts([
            'post_type'=>self::POST_TYPE, 'post_status'=>'draft',
            'meta_key'=>'_ns_needs_rewrite', 'meta_value'=>'1',
            'numberposts'=>-1, 'fields'=>'ids'
        ]);

        $draft_budget = max(0, $max_draft - count($pending));

        // ── Stage 1: Fetch RSS ──
        $rss_items = [];

        try {
            $rss_items = (array) $this->rss_fetcher->fetch_all($window);
            $stats['fetched_rss'] = count($rss_items);
        } catch (\Throwable $e) {
            error_log('❌ RSS fetch failed: ' . $e->getMessage());
            $stats['errors'][] = 'RSS: ' . $e->getMessage();
        }

        // ── Stage 2: Fetch API ──
        $api_items = [];

        try {
            $api_items = (array) $this->api_fetcher->fetch_all();
            $stats['fetched_api'] = count($api_items);
        } catch (\Throwable $e) {
            error_log('❌ API fetch failed: ' . $e->getMessage());
            $stats['errors'][] = 'API: ' . $e->getMessage();
        }

        // ── Stage 3: Merge + time window + dedup ──
        $all = $this->apply_time_window(array_merge($rss_items, $api_items), $window);
        $stats['total_fetched'] = count($all);

        if (empty($all)) {
            $this->finish_sync_log($log_id, 'completed', $stats);
            return $this->build_result($stats);
        }

        try {
            $unique = $this->duplicate_checker->filter_duplicates($all);
            $stats['duplicates'] = count($all) - count($unique);
        } catch (\Throwable $e) {
            error_log('❌ Duplicate checker failed: ' . $e->getMessage());
            $unique = $all;
        }

        if (empty($unique)) {
            $this->finish_sync_log($log_id, 'completed', $stats);
            return $this->build_result($stats);
        }

        // ── Stage 4: Keyword filter ──
        try {
            $passed = $this->news_filter->filter_items($unique);
        } catch (\Throwable $e) {
            error_log('❌ NewsFilter failed: ' . $e->getMessage());

            $passed = [];

            foreach ($unique as $item) {
                $r = $this->emergency_filter($item);
                $item['_filter_result'] = $r;

                if (($r['decision'] ?? 'delete') !== 'delete') $passed[] = $item;
            }
        }

        $stats['filtered_out'] = count($unique) - count($passed);

        if ($stats['filtered_out'] > 0) {
            $filtered_titles = [];

            foreach ($unique as $item) {
                $decision = $item['_filter_result']['decision'] ?? 'unknown';

                if ($decision === 'delete') {
                    $filtered_titles[] = mb_substr($item['title'] ?? '', 0, 50);
                }
            }

            if (!empty($filtered_titles)) {
                error_log('🗑️ Filtered ' . count($filtered_titles) . ' items: ' . implode(' | ', array_slice($filtered_titles, 0, 5)));
            }
        }

        update_option('nextsafar_news_total_filtered', (int) get_option('nextsafar_news_total_filtered', 0) + $stats['filtered_out']);

        if (empty($passed)) {
            $this->finish_sync_log($log_id, 'completed', $stats);
            return $this->build_result($stats);
        }

        // ── Stage 5: Separate queues ──
        $queue = [];
        $review = [];

        foreach ($passed as $item) {
            if (($item['_filter_result']['decision'] ?? 'review') === 'publish') {
                $queue[] = $item;
            } else {
                $review[] = $item;
            }
        }

        if (!empty($review) && $ai_on) {
            usort($review, function ($a, $b) {
                return strcmp($b['pub_date'] ?? '', $a['pub_date'] ?? '');
            });

            $checks = 0;

            foreach ($review as $item) {
                if ($checks >= $max_ai) break;

                $checks++;

                $ok = $this->ai_rewriter->check_relevance($item);

                if ($ok === null) {
                    $score = $item['_filter_result']['score'] ?? 0;

                    if ($score >= 10) {
                        $item['_filter_result']['decision'] = 'publish';
                        $item['_filter_result']['reason'] .= ' | AI unavailable, score-based publish';
                        $queue[] = $item;

                        error_log("🤖 AI unavailable — publishing based on score={$score}: " . mb_substr($item['title'] ?? '', 0, 50));
                    }

                    continue;
                }

                if ($ok) {
                    $item['_filter_result']['decision']   = 'publish';
                    $item['_filter_result']['ai_checked'] = true;
                    $item['_filter_result']['reason']    .= ' | AI: مرتبط';
                    $queue[] = $item;
                } else {
                    $item['_filter_result']['ai_checked'] = true;
                    $item['_filter_result']['reason']    .= ' | AI: نامرتبط';
                }
            }

            $stats['ai_checks'] = $checks;
        }

        if (empty($queue)) {
            $this->finish_sync_log($log_id, 'completed', $stats);
            return $this->build_result($stats);
        }

        usort($queue, function ($a, $b) {
            return ($b['_filter_result']['score'] ?? 0) <=> ($a['_filter_result']['score'] ?? 0);
        });

        // ── Stage 6: Save with budget ──
        foreach ($queue as $item) {
            if ($publish_budget <= 0 && $draft_budget <= 0) break;

            try {
                $r = $this->save_news($item, $publish_budget, $draft_budget, $ai_on);

                if (($r['status'] ?? '') === 'created')  { $stats['created']++; $publish_budget--; }
                elseif (($r['status'] ?? '') === 'draft') { $stats['drafts']++;  $draft_budget--; }

                if (!empty($r['ai_used'])) $stats['ai_used']++;
            } catch (\Throwable $e) {
                error_log('❌ Failed to save news: ' . $e->getMessage());
                $stats['failed']++;
            }
        }

        $this->finish_sync_log($log_id, 'completed', $stats);

        error_log('✅ News sync completed: Created=' . $stats['created'] . ', Drafts=' . $stats['drafts']);

        return $this->build_result($stats);
    }

    // ... بقیه متدها (apply_time_window, slug_exists, save_news, extract_video, retry_pending_rewrites, etc.) بدون تغییر باقی می‌مانند

    private function apply_time_window(array $items, int $hours): array {
        $cutoff = time() - $hours * HOUR_IN_SECONDS;
        $out = [];

        foreach ($items as $it) {
            $ts = strtotime($it['pub_date'] ?? '');

            if (!$ts || $ts >= $cutoff) $out[] = $it;
        }

        return $out;
    }

    private function slug_exists(string $slug): bool {
        global $wpdb;

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type = %s LIMIT 1",
            $slug, self::POST_TYPE
        ));

        return !empty($exists);
    }

    private function build_result(array $stats): array {
        return [
            'completed' => true, 'total' => $stats['total_fetched'],
            'fetched_rss' => $stats['fetched_rss'], 'fetched_api' => $stats['fetched_api'],
            'duplicates' => $stats['duplicates'], 'filtered_out' => $stats['filtered_out'],
            'created' => $stats['created'], 'drafts' => $stats['drafts'],
            'updated' => $stats['updated'], 'failed' => $stats['failed'],
            'ai_used' => $stats['ai_used'], 'ai_checks' => $stats['ai_checks'],
            'retry_rewrites' => $stats['retry_rewrites'] ?? 0, 'errors' => $stats['errors'],
            'progress_percent' => 100, 'batch_size' => $stats['total_fetched'],
            'total_batches' => 1, 'current_batch' => 1, 'processed' => $stats['total_fetched'],
            'batch_result' => [
                'completed' => true, 'total' => $stats['total_fetched'],
                'created' => $stats['created'], 'updated' => $stats['updated'],
                'failed' => $stats['failed'], 'filtered_out' => $stats['filtered_out'],
                'drafts' => $stats['drafts'],
            ],
        ];
    }

    private function start_sync_log(): int {
        global $wpdb;

        $table = $wpdb->prefix . 'api_sync_log';

        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            if (method_exists('\NextSafar\Activator', 'create_sync_log_table')) {
                \NextSafar\Activator::create_sync_log_table();
            }
        }

        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) return 0;

        $wpdb->insert($table, [
            'source' => 'news_aggregator', 'entity_type' => 'travelnews',
            'status' => 'running', 'started_at' => current_time('mysql'),
        ]);

        return $wpdb->insert_id ?: 0;
    }

    private function finish_sync_log(int $log_id, string $status, array $stats, ?string $error = null): void {
        if ($log_id <= 0) return;

        global $wpdb;

        $wpdb->update($wpdb->prefix . 'api_sync_log', [
            'status'          => $status,
            'records_fetched' => $stats['total_fetched'],
            'records_created' => $stats['created'],
            'records_updated' => $stats['updated'],
            'records_failed'  => $stats['failed'],
            'error_message'   => $error ? json_encode($stats['errors']) : null,
            'completed_at'    => current_time('mysql'),
        ], ['id' => $log_id]);
    }

    public static function ensure_tables_exist(): void {
        if (version_compare(get_option('nextsafar_news_tables_version', '0'), '3.1.0', '<')) {
            \NextSafar\Database\NewsTables::create_sources_table();
            \NextSafar\Database\NewsTables::create_duplicates_table();
            \NextSafar\Database\NewsTables::create_ai_log_table();
            \NextSafar\Database\NewsTables::create_keywords_table();

            if (method_exists('\NextSafar\Activator', 'create_filter_log_table')) {
                \NextSafar\Activator::create_filter_log_table();
            }

            \NextSafar\Database\NewsTables::migrate_duplicates_table();
            \NextSafar\Database\NewsTables::seed_default_keywords();
            \NextSafar\Database\NewsTables::seed_default_sources();

            update_option('nextsafar_news_tables_version', '3.1.0');
        }
    }

    public static function reset_sources(): array {
        global $wpdb;

        $table = $wpdb->prefix . 'ns_news_sources';

        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            return ['success' => false, 'message' => 'Table not found'];
        }

        $wpdb->query("TRUNCATE TABLE {$table}");

        \NextSafar\Database\NewsTables::seed_default_sources();

        $count = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");

        return ['success' => true, 'message' => "{$count} منبع بازنشانی شد", 'count' => (int) $count];
    }

    public static function get_sources_stats(): array {
        self::ensure_tables_exist();

        global $wpdb;

        $table = $wpdb->prefix . 'ns_news_sources';

        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) return [];

        return $wpdb->get_results(
            "SELECT id, name, type, group_name, priority, is_active, last_fetch, total_fetched,
                    total_duplicates, LEFT(error_message, 100) as error_message
             FROM {$table} ORDER BY priority DESC, name ASC LIMIT 50"
        ) ?: [];
    }

    public static function get_overview_stats(): array {
        self::ensure_tables_exist();

        global $wpdb;

        $cached = get_transient('nextsafar_news_overview_stats');

        if ($cached !== false) return $cached;

        $counts = wp_count_posts(self::POST_TYPE);
        $total  = $counts ? (int) ($counts->publish ?? 0) : 0;

        $tz   = wp_timezone();
        $now  = new \DateTime('now', $tz);

        $today = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'
             AND post_date BETWEEN %s AND %s",
            self::POST_TYPE, $now->format('Y-m-d 00:00:00'), $now->format('Y-m-d 23:59:59')
        ));

        $week = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' AND post_date >= %s",
            self::POST_TYPE, (clone $now)->modify('-7 days')->format('Y-m-d H:i:s')
        ));

        $dup_table = $wpdb->prefix . 'ns_news_duplicates';

        $dups = ($wpdb->get_var("SHOW TABLES LIKE '{$dup_table}'") === $dup_table)
            ? (int) $wpdb->get_var("SELECT COUNT(*) FROM {$dup_table}") : 0;

        $ai_table = $wpdb->prefix . 'ns_news_ai_log';
        $cost = 0; $tokens = 0;

        if ($wpdb->get_var("SHOW TABLES LIKE '{$ai_table}'") === $ai_table) {
            $row = $wpdb->get_row("SELECT COALESCE(SUM(cost_usd),0) c, COALESCE(SUM(tokens_used),0) t FROM {$ai_table}");
            $cost = (float) ($row->c ?? 0); $tokens = (int) ($row->t ?? 0);
        }

        $stats = [
            'total_posts' => $total, 'today_posts' => $today, 'week_posts' => $week,
            'total_duplicates_blocked' => $dups,
            'total_filtered_out' => (int) get_option('nextsafar_news_total_filtered', 0),
            'ai_total_cost_usd' => $cost, 'ai_total_tokens' => $tokens,
        ];

        set_transient('nextsafar_news_overview_stats', $stats, 60);

        return $stats;
    }
}