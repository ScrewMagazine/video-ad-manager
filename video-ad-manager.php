<?php
/**
 * Plugin Name: Video Ad Manager
 * Description: Create and serve one clickable, skippable VAST 3.0 video commercial from WordPress, with basic event reporting. Upgrade path to MEDIARAZZI.
 * Version: 1.2.12
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Author: Video Ad Manager
 * License: GPL-2.0-or-later
 * Text Domain: video-ad-manager
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) { exit; }

final class VAM_Free_Plugin {
    const VERSION = '1.2.12';
    const OPTION_AD = 'vam_free_commercial';
    const DB_VERSION = '1';
    const OPTION_DB_VERSION = 'vam_db_version';
    const ROUTE_BASE = 'vam/v1';
    private static $instance;

    public static function instance() {
        if (!self::$instance) { self::$instance = new self(); }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'admin_assets'));
        add_action('admin_post_vam_save_ad', array($this, 'save_ad'));
        add_action('rest_api_init', array($this, 'register_routes'));
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'plugin_links'));
        add_action('admin_init', array($this, 'maybe_upgrade_db'));
        add_action('admin_init', array($this, 'privacy_policy'));
    }


    public function privacy_policy() {
        if (!function_exists('wp_add_privacy_policy_content')) { return; }
        wp_add_privacy_policy_content('Video Ad Manager', wp_kses_post(
            '<p>' . esc_html__('Video Ad Manager records daily aggregate ad requests and video-player tracking events in your WordPress database. The plugin does not store viewer IP addresses in its statistics table, although normal server access logs may do so. If you voluntarily connect to MEDIARAZZI, the site administrator sends an API key and advertising configuration data to the MEDIARAZZI service over HTTPS. External video hosting and advertisement destinations may collect visitor data under their own policies.', 'video-ad-manager') . '</p>'
        ));
    }

    public static function activate() {
        self::create_tables();
        if (!get_option(self::OPTION_AD)) {
            add_option(self::OPTION_AD, array(
                'id' => wp_generate_uuid4(),
                'title' => 'My Video Commercial',
                'video_url' => 'https://cdn.example.com/commercial.mp4',
                'click_url' => 'https://advertiser.example.com',
                'duration' => 30,
                'skip_after' => 5,
                'width' => 1280,
                'height' => 720,
                'active' => 0,
            ), '', false);
        }
    }

    public function maybe_upgrade_db() {
        if (get_option(self::OPTION_DB_VERSION) !== self::DB_VERSION) { self::create_tables(); }
    }

    private static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'vam_daily_stats';
    }

    private static function create_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table_name();
        $collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            ad_id varchar(36) NOT NULL,
            stat_date date NOT NULL,
            requests bigint(20) unsigned NOT NULL DEFAULT 0,
            impressions bigint(20) unsigned NOT NULL DEFAULT 0,
            starts bigint(20) unsigned NOT NULL DEFAULT 0,
            first_quartile bigint(20) unsigned NOT NULL DEFAULT 0,
            midpoint bigint(20) unsigned NOT NULL DEFAULT 0,
            third_quartile bigint(20) unsigned NOT NULL DEFAULT 0,
            completes bigint(20) unsigned NOT NULL DEFAULT 0,
            skips bigint(20) unsigned NOT NULL DEFAULT 0,
            clicks bigint(20) unsigned NOT NULL DEFAULT 0,
            errors bigint(20) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY ad_date (ad_id,stat_date),
            KEY stat_date (stat_date)
        ) $collate;";
        dbDelta($sql);
        update_option(self::OPTION_DB_VERSION, self::DB_VERSION, false);
    }

    private function ad() {
        $ad = get_option(self::OPTION_AD, array());
        return is_array($ad) ? $ad : array();
    }

    private function ad_matches($id) {
        $ad = $this->ad();
        return !empty($ad['id']) && hash_equals((string)$ad['id'], (string)$id) ? $ad : null;
    }

    private static function valid_external_url($value) {
        $value = is_string($value) ? trim($value) : '';
        if (strlen($value) > 2048 || preg_match('/[\x00-\x1F\x7F]/', $value)) { return ''; }
        $url = esc_url_raw($value, array('http', 'https'));
        $parts = wp_parse_url($url);
        if (!$url || !is_array($parts) || empty($parts['host']) || !in_array(strtolower($parts['scheme'] ?? ''), array('http','https'), true)) {
            return '';
        }
        if (isset($parts['user']) || isset($parts['pass'])) { return ''; }
        return $url;
    }

    private static function sample_url($url) {
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        return $host === 'example.com' || substr($host, -12) === '.example.com' || $host === 'example.org' || substr($host, -12) === '.example.org';
    }

    public function admin_menu() {
        add_menu_page(__('Video Ad Manager', 'video-ad-manager'), __('Video Ad Manager', 'video-ad-manager'), 'manage_options', 'vam-manager', array($this, 'page'), 'dashicons-format-video', 58);
    }

    public function plugin_links($links) {
        array_unshift($links, '<a href="' . esc_url(admin_url('admin.php?page=vam-manager')) . '">' . esc_html__('Settings', 'video-ad-manager') . '</a>');
        return $links;
    }

    public function admin_assets($hook) {
        // WordPress supplies the registered admin-page hook. No request parameters are needed.
        if (!is_string($hook) || !preg_match('/(?:^|_)page_vam-(manager|mediarazzi|help)$/', $hook, $matches)) {
            return;
        }
        $page = 'vam-' . $matches[1];
        if (!in_array($page, array('vam-manager', 'vam-mediarazzi', 'vam-help'), true)) { return; }
        if ($page === 'vam-manager') { wp_enqueue_media(); }
        wp_enqueue_style('vam-admin', plugin_dir_url(__FILE__) . 'assets/admin.css', array(), self::VERSION);
        if ($page === 'vam-mediarazzi') {
            wp_enqueue_script('vam-mediarazzi', plugin_dir_url(__FILE__) . 'assets/mediarazzi.js', array(), self::VERSION, true);
            wp_localize_script('vam-mediarazzi', 'vamMedI18n', array('copied'=>__('Copied!','video-ad-manager'),'copyPrompt'=>__('Copy VAST tag:','video-ad-manager')));
        }
        if ($page === 'vam-manager') {
            wp_enqueue_script('vam-admin', plugin_dir_url(__FILE__) . 'assets/admin.js', array(), self::VERSION, true);
            wp_localize_script('vam-admin', 'vamI18n', array('selectMp4'=>__('Select MP4 commercial','video-ad-manager'),'useVideo'=>__('Use this video','video-ad-manager'),'copied'=>__('Copied!','video-ad-manager'),'selectCopy'=>__('Select and copy URL','video-ad-manager')));
        }
    }

    public function save_ad() {
        if (!current_user_can('manage_options')) { wp_die(esc_html__('You are not permitted to manage video ads.', 'video-ad-manager'), '', array('response' => 403)); }
        check_admin_referer('vam_save_ad');
        $old = $this->ad();
        foreach (array('vam_title','vam_video_url','vam_click_url','vam_duration','vam_skip_after','vam_width','vam_height') as $field) {
            if (isset($_POST[$field]) && !is_scalar($_POST[$field])) {
                wp_safe_redirect(add_query_arg(array('page'=>'vam-manager','vam_status'=>'invalid'), admin_url('admin.php')));
                exit;
            }
        }
        $title = sanitize_text_field(wp_unslash($_POST['vam_title'] ?? ''));
        $video = self::valid_external_url(esc_url_raw(wp_unslash($_POST['vam_video_url'] ?? ''), array('http', 'https')));
        $click = self::valid_external_url(esc_url_raw(wp_unslash($_POST['vam_click_url'] ?? ''), array('http', 'https')));
        $duration = filter_var(wp_unslash($_POST['vam_duration'] ?? ''), FILTER_VALIDATE_INT, array('options'=>array('min_range'=>1,'max_range'=>86400))) ?: 0;
        $skip_input = sanitize_text_field(wp_unslash($_POST['vam_skip_after'] ?? '5'));
        $skip_after = $skip_input === 'never' ? -1 : (in_array($skip_input, array('0','5','10','15','30'), true) ? (int)$skip_input : -2);
        $width = filter_var(wp_unslash($_POST['vam_width'] ?? ''), FILTER_VALIDATE_INT, array('options'=>array('min_range'=>1,'max_range'=>8192))) ?: 0;
        $height = filter_var(wp_unslash($_POST['vam_height'] ?? ''), FILTER_VALIDATE_INT, array('options'=>array('min_range'=>1,'max_range'=>8192))) ?: 0;
        $activate = isset($_POST['vam_active']) && is_string($_POST['vam_active']) && $_POST['vam_active'] === '1';

        if ($title === '' || strlen($title) > 200 || !$video || !$click || !$duration || !$width || !$height || $skip_after < -1 || ($skip_after >= $duration && $skip_after !== -1)) {
            $status = 'invalid';
        } elseif ($activate && (self::sample_url($video) || self::sample_url($click))) {
            $status = 'example';
        } else {
            $ad = array(
                'id' => !empty($old['id']) ? $old['id'] : wp_generate_uuid4(),
                'title' => $title,
                'video_url' => $video,
                'click_url' => $click,
                'duration' => $duration,
                'skip_after' => $skip_after,
                'width' => $width,
                'height' => $height,
                'active' => $activate ? 1 : 0,
            );
            update_option(self::OPTION_AD, $ad, false);
            $status = 'saved';
        }
        wp_safe_redirect(add_query_arg(array('page'=>'vam-manager','vam_status'=>$status), admin_url('admin.php')));
        exit;
    }

    private function endpoint($type, $ad) {
        return rest_url(self::ROUTE_BASE . '/' . $type . '/' . rawurlencode($ad['id']));
    }

    public function page() {
        if (!current_user_can('manage_options')) { return; }
        $ad = $this->ad();
        $id = isset($ad['id']) ? $ad['id'] : '';
        $vast_url = $id ? $this->endpoint('vast', $ad) : '';
        // Status is an informational query parameter following a separately nonce-verified POST.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only status code; no mutation or privileged action.
        $status = isset($_GET['vam_status']) && is_string($_GET['vam_status']) ? sanitize_key(wp_unslash($_GET['vam_status'])) : '';
        $stats = $this->stats($id);
        ?>
        <div class="wrap vam-wrap">
            <h1><?php esc_html_e('Video Ad Manager', 'video-ad-manager'); ?> <span class="vam-version">Free v<?php echo esc_html(self::VERSION); ?></span></h1>
            <p class="vam-lead"><?php esc_html_e('Create one clickable, skippable VAST 3.0 commercial and track its performance.', 'video-ad-manager'); ?></p>
            <?php if ($status === 'saved'): ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e('Commercial saved.', 'video-ad-manager'); ?></p></div><?php endif; ?>
            <?php if ($status === 'invalid'): ?><div class="notice notice-error"><p><?php esc_html_e('Enter a name and valid HTTP(S) media and destination URLs. The skip offset must be shorter than the video duration.', 'video-ad-manager'); ?></p></div><?php endif; ?>
            <?php if ($status === 'example'): ?><div class="notice notice-error"><p><?php esc_html_e('Replace both example.com addresses with real URLs before activating the commercial.', 'video-ad-manager'); ?></p></div><?php endif; ?>
            <div class="vam-columns">
                <div class="vam-main">
                    <section class="vam-panel">
                        <h2><?php esc_html_e('Commercial Editor', 'video-ad-manager'); ?></h2>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <?php wp_nonce_field('vam_save_ad'); ?>
                            <input type="hidden" name="action" value="vam_save_ad">
                            <label for="vam_title"><?php esc_html_e('Commercial name', 'video-ad-manager'); ?></label>
                            <input class="regular-text" id="vam_title" name="vam_title" type="text" required maxlength="200" value="<?php echo esc_attr($ad['title'] ?? 'My Video Commercial'); ?>">
                            <label for="vam_video_url"><?php esc_html_e('MP4 video URL', 'video-ad-manager'); ?></label>
                            <div class="vam-inline"><input class="regular-text" id="vam_video_url" name="vam_video_url" type="url" required value="<?php echo esc_attr($ad['video_url'] ?? ''); ?>"><button type="button" class="button" id="vam-media-select"><?php esc_html_e('Media Library', 'video-ad-manager'); ?></button></div>
                            <p class="description"><?php esc_html_e('Upload an MP4 into WordPress or paste a direct, publicly accessible MP4 URL. This is not a YouTube watch-page link.', 'video-ad-manager'); ?></p>
                            <label for="vam_click_url"><?php esc_html_e('Click-through destination URL', 'video-ad-manager'); ?></label>
                            <input class="regular-text" id="vam_click_url" name="vam_click_url" type="url" required value="<?php echo esc_attr($ad['click_url'] ?? ''); ?>">
                            <div class="vam-fields">
                                <div><label for="vam_duration"><?php esc_html_e('Video duration (seconds)', 'video-ad-manager'); ?></label><input id="vam_duration" type="number" min="1" max="86400" step="1" name="vam_duration" required value="<?php echo esc_attr($ad['duration'] ?? 30); ?>"></div>
                                <div><label for="vam_skip_after"><?php esc_html_e('Skip after', 'video-ad-manager'); ?></label><select id="vam_skip_after" name="vam_skip_after">
                                    <?php foreach (array('never'=>__('Not skippable','video-ad-manager'),'0'=>__('Immediately','video-ad-manager'),'5'=>__('5 seconds','video-ad-manager'),'10'=>__('10 seconds','video-ad-manager'),'15'=>__('15 seconds','video-ad-manager'),'30'=>__('30 seconds','video-ad-manager')) as $value=>$label): ?>
                                    <option value="<?php echo esc_attr($value); ?>" <?php selected((string)($ad['skip_after'] ?? 5), (string)($value === 'never' ? -1 : $value)); ?>><?php echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select></div>
                                <div><label for="vam_width"><?php esc_html_e('Media width (px)', 'video-ad-manager'); ?></label><input id="vam_width" name="vam_width" type="number" min="1" max="8192" required value="<?php echo esc_attr($ad['width'] ?? 1280); ?>"></div>
                                <div><label for="vam_height"><?php esc_html_e('Media height (px)', 'video-ad-manager'); ?></label><input id="vam_height" name="vam_height" type="number" min="1" max="8192" required value="<?php echo esc_attr($ad['height'] ?? 720); ?>"></div>
                            </div>
                            <p class="description"><?php esc_html_e('Set the actual media duration and dimensions. Advertised VAST metadata must match the file.', 'video-ad-manager'); ?></p>
                            <label class="vam-checkbox"><input type="checkbox" name="vam_active" value="1" <?php checked(!empty($ad['active'])); ?>><?php esc_html_e('Enable commercial', 'video-ad-manager'); ?></label>
                            <?php submit_button(__('Save Commercial', 'video-ad-manager')); ?>
                        </form>
                    </section>
                    <section class="vam-panel"><h2><?php esc_html_e('VAST Tag', 'video-ad-manager'); ?></h2>
                        <p><?php esc_html_e('Status:','video-ad-manager'); ?> <strong><?php echo !empty($ad['active']) ? esc_html__('Active','video-ad-manager') : esc_html__('Inactive','video-ad-manager'); ?></strong>. <?php esc_html_e('The VAST endpoint returns HTTP 204 while inactive.', 'video-ad-manager'); ?></p>
                        <div class="vam-inline"><input class="regular-text" id="vam-vast-url" readonly value="<?php echo esc_attr($vast_url); ?>"><button type="button" class="button" id="vam-copy-tag"><?php esc_html_e('Copy URL', 'video-ad-manager'); ?></button></div>
                        <p><a href="<?php echo esc_url($vast_url); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('View VAST XML', 'video-ad-manager'); ?></a> · <a href="https://www.iabtechlab.com/standards/vast/" target="_blank" rel="noopener noreferrer"><?php esc_html_e('About VAST', 'video-ad-manager'); ?></a></p>
                        <p class="description"><?php esc_html_e("For use in a compatible VAST ad player. Skipping and clickable overlays depend on the player's capabilities. The built-in media preview below is not a VAST player.", 'video-ad-manager'); ?></p>
                        <?php if (!empty($ad['video_url']) && !self::sample_url($ad['video_url'])): ?><video class="vam-preview" controls preload="metadata" playsinline src="<?php echo esc_url($ad['video_url']); ?>"></video><?php endif; ?>
                    </section>
                    <section class="vam-panel"><h2><?php esc_html_e('Basic Statistics', 'video-ad-manager'); ?></h2>
                        <div class="vam-metrics">
                            <?php foreach (array('requests'=>__('Requests','video-ad-manager'),'impressions'=>__('Impressions','video-ad-manager'),'starts'=>__('Starts','video-ad-manager'),'completes'=>__('Completions','video-ad-manager'),'skips'=>__('Skips','video-ad-manager'),'clicks'=>__('Clicks','video-ad-manager')) as $key=>$label): ?>
                            <div class="vam-metric"><strong><?php echo esc_html(number_format_i18n((int)($stats['totals'][$key] ?? 0))); ?></strong><span><?php echo esc_html($label); ?></span></div>
                            <?php endforeach; ?>
                        </div>
                        <p class="description"><?php esc_html_e('Requests count VAST endpoint calls. Impressions and other playback milestones are recorded only when a player calls their tracking URLs. These are not unique-person counts.', 'video-ad-manager'); ?></p>
                        <div class="vam-table-scroll"><table class="widefat striped"><thead><tr><th><?php esc_html_e('Date (UTC)', 'video-ad-manager'); ?></th><th><?php esc_html_e('Requests', 'video-ad-manager'); ?></th><th><?php esc_html_e('Impressions', 'video-ad-manager'); ?></th><th><?php esc_html_e('Starts', 'video-ad-manager'); ?></th><th>25%</th><th>50%</th><th>75%</th><th><?php esc_html_e('Complete', 'video-ad-manager'); ?></th><th><?php esc_html_e('Skips', 'video-ad-manager'); ?></th><th><?php esc_html_e('Clicks', 'video-ad-manager'); ?></th><th><?php esc_html_e('Errors', 'video-ad-manager'); ?></th></tr></thead><tbody>
                        <?php if (empty($stats['days'])): ?><tr><td colspan="11"><?php esc_html_e('No tracked activity yet.', 'video-ad-manager'); ?></td></tr><?php else: foreach ($stats['days'] as $day): ?><tr>
                        <td><?php echo esc_html($day['stat_date']); ?></td>
                        <?php foreach (array('requests','impressions','starts','first_quartile','midpoint','third_quartile','completes','skips','clicks','errors') as $key): ?><td><?php echo esc_html(number_format_i18n((int)$day[$key])); ?></td><?php endforeach; ?></tr><?php endforeach; endif; ?>
                        </tbody></table></div>
                    </section>
                </div>
                <aside class="vam-side">
                    <section class="vam-panel vam-upgrade"><h2><?php esc_html_e('Grow with MEDIARAZZI', 'video-ad-manager'); ?></h2><div class="vam-price">$49.95<span>/month</span></div>
                        <p><?php esc_html_e('Upgrade to the hosted MEDIARAZZI VAST Ad Server for multiple commercials, rotations, ad pods, Advanced Tag Builder and comprehensive reporting.', 'video-ad-manager'); ?></p>
                        <a class="button button-primary vam-upgrade-btn" href="https://mediarazzi.com/" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Explore MEDIARAZZI', 'video-ad-manager'); ?></a>
                        <p class="description"><?php esc_html_e('MEDIARAZZI connection is available under Video Ad Manager > MEDIARAZZI Pro. Billing is managed separately by MEDIARAZZI.', 'video-ad-manager'); ?></p>
                    </section>
                    <section class="vam-panel"><h2><?php esc_html_e('Before you publish', 'video-ad-manager'); ?></h2><ul class="vam-help"><li><?php esc_html_e('Replace the example URLs with working HTTPS links.', 'video-ad-manager'); ?></li><li><?php esc_html_e('Ensure the MP4 supports cross-site playback and byte-range requests.', 'video-ad-manager'); ?></li><li><?php esc_html_e('Verify the true video duration.', 'video-ad-manager'); ?></li><li><?php esc_html_e('Test the VAST tag in your intended player.', 'video-ad-manager'); ?></li><li><?php esc_html_e('Use HTTPS on the WordPress site.', 'video-ad-manager'); ?></li></ul></section>
                </aside>
            </div>
        </div>
        <?php
    }

    private function stats($id) {
        global $wpdb;
        $empty = array('totals'=>array(), 'days'=>array());
        if (!$id) { return $empty; }
        $cache_key = 'stats_' . md5((string) $id);
        $cached = wp_cache_get($cache_key, 'vam_statistics');
        if (is_array($cached)) { return $cached; }
        $table = self::table_name();
        // Aggregated event counts are held in a dedicated plugin table, not post metadata.
        // Query uses %i identifier and %s value placeholders; results are cached below.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Purpose-built analytics table with a prepared statement and object caching.
        $totals = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT SUM(requests) AS requests, SUM(impressions) AS impressions, SUM(starts) AS starts, SUM(first_quartile) AS first_quartile, SUM(midpoint) AS midpoint, SUM(third_quartile) AS third_quartile, SUM(completes) AS completes, SUM(skips) AS skips, SUM(clicks) AS clicks, SUM(errors) AS errors FROM %i WHERE ad_id = %s',
                $table, $id
            ), ARRAY_A
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Purpose-built analytics table with a prepared statement and object caching.
        $days = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT stat_date, requests, impressions, starts, first_quartile, midpoint, third_quartile, completes, skips, clicks, errors FROM %i WHERE ad_id = %s ORDER BY stat_date DESC LIMIT 30',
                $table, $id
            ), ARRAY_A
        );
        $result = array('totals'=>$totals ?: array(), 'days'=>$days ?: array());
        wp_cache_set($cache_key, $result, 'vam_statistics', 30);
        return $result;
    }

    private function increment($id, $event) {
        global $wpdb;
        $fields = array(
            'request'=>'requests', 'impression'=>'impressions', 'start'=>'starts',
            'firstQuartile'=>'first_quartile', 'midpoint'=>'midpoint', 'thirdQuartile'=>'third_quartile',
            'complete'=>'completes', 'skip'=>'skips', 'click'=>'clicks', 'error'=>'errors',
        );
        if (!isset($fields[$event])) { return; }
        $field = $fields[$event]; // One of the hardcoded column names in the whitelist.
        $table = self::table_name();
        // A single atomic upsert is required for concurrent public playback-event callbacks.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Atomic upsert into plugin-owned stats table using prepared identifiers and values.
        $wpdb->query($wpdb->prepare(
            'INSERT INTO %i (ad_id, stat_date, %i) VALUES (%s, %s, 1) ON DUPLICATE KEY UPDATE %i = %i + 1',
            $table, $field, $id, gmdate('Y-m-d'), $field, $field
        ));
        wp_cache_delete('stats_' . md5((string) $id), 'vam_statistics');
    }

    public function register_routes() {
        register_rest_route(self::ROUTE_BASE, '/vast/(?P<id>[a-f0-9-]{36})', array('methods'=>'GET', 'callback'=>array($this,'serve_vast'), 'permission_callback'=>'__return_true'));
        register_rest_route(self::ROUTE_BASE, '/event/(?P<id>[a-f0-9-]{36})/(?P<event>[A-Za-z]+)', array('methods'=>'GET', 'callback'=>array($this,'track_event'), 'permission_callback'=>'__return_true'));
        register_rest_route(self::ROUTE_BASE, '/click/(?P<id>[a-f0-9-]{36})', array('methods'=>'GET', 'callback'=>array($this,'click'), 'permission_callback'=>'__return_true'));
    }

    private static function raw_headers($content_type) {
        status_header(200);
        header('Content-Type: ' . $content_type);
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Access-Control-Allow-Origin: *');
        if (function_exists('header_remove')) { header_remove('Access-Control-Allow-Credentials'); }
        header('X-Content-Type-Options: nosniff');
    }

    private function active_ad($request) {
        $ad = $this->ad_matches((string)$request['id']);
        return $ad && !empty($ad['active']) ? $ad : null;
    }

    public function serve_vast($request) {
        $ad = $this->active_ad($request);
        if (!$ad) { status_header(204); header('Cache-Control: no-store'); exit; }
        if (!class_exists('DOMDocument')) {
            return new WP_Error('vam_no_dom', __('PHP DOM extension is required for VAST generation.', 'video-ad-manager'), array('status'=>500));
        }
        $this->increment($ad['id'], 'request');
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;
        $vast = $dom->createElement('VAST'); $vast->setAttribute('version', '3.0'); $dom->appendChild($vast);
        $root_ad = $dom->createElement('Ad'); $root_ad->setAttribute('id', $ad['id']); $vast->appendChild($root_ad);
        $inline = $dom->createElement('InLine'); $root_ad->appendChild($inline);
        $this->node($dom, $inline, 'AdSystem', 'Video Ad Manager');
        $this->node($dom, $inline, 'AdTitle', $ad['title']);
        $this->node($dom, $inline, 'Error', $this->endpoint('event', $ad) . '/error?code=[ERRORCODE]', true);
        $this->node($dom, $inline, 'Impression', $this->endpoint('event', $ad) . '/impression', true);
        $creatives = $dom->createElement('Creatives'); $inline->appendChild($creatives);
        $creative = $dom->createElement('Creative'); $creative->setAttribute('id', '1'); $creatives->appendChild($creative);
        $linear = $dom->createElement('Linear');
        if ((int)$ad['skip_after'] >= 0) { $linear->setAttribute('skipoffset', $this->time_string((int)$ad['skip_after'])); }
        $creative->appendChild($linear);
        $this->node($dom, $linear, 'Duration', $this->time_string((int)$ad['duration']));
        $events = $dom->createElement('TrackingEvents'); $linear->appendChild($events);
        foreach (array('start','firstQuartile','midpoint','thirdQuartile','complete','skip') as $event) {
            $track = $dom->createElement('Tracking'); $track->setAttribute('event', $event);
            $track->appendChild($dom->createCDATASection($this->endpoint('event', $ad) . '/' . $event));
            $events->appendChild($track);
        }
        $clicks = $dom->createElement('VideoClicks'); $linear->appendChild($clicks);
        $this->node($dom, $clicks, 'ClickThrough', $this->endpoint('click', $ad), true);
        $media_files = $dom->createElement('MediaFiles'); $linear->appendChild($media_files);
        $media = $dom->createElement('MediaFile');
        foreach (array('delivery'=>'progressive','type'=>'video/mp4','width'=>(string)$ad['width'],'height'=>(string)$ad['height'],'scalable'=>'true','maintainAspectRatio'=>'true') as $attr=>$val) { $media->setAttribute($attr,$val); }
        $media->appendChild($dom->createCDATASection($ad['video_url'])); $media_files->appendChild($media);
        self::raw_headers('application/xml; charset=UTF-8');
        echo $dom->saveXML(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- DOMDocument escapes XML values.
        exit;
    }

    private function node($dom, $parent, $name, $content, $cdata = false) {
        $item = $dom->createElement($name);
        // Remove characters forbidden in XML 1.0, including from legacy stored options.
        $content = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', (string)$content);
        if ($content === null) { $content = ''; }
        if ($cdata) {
            $parts = explode(']]>', (string)$content);
            foreach ($parts as $index=>$part) {
                if ($index) { $item->appendChild($dom->createTextNode(']]>')); }
                $item->appendChild($dom->createCDATASection($part));
            }
        }
        else { $item->appendChild($dom->createTextNode((string)$content)); }
        $parent->appendChild($item);
    }

    private function time_string($seconds) {
        $seconds = max(0, (int)$seconds);
        return sprintf('%02d:%02d:%02d', floor($seconds / 3600), floor(($seconds % 3600) / 60), $seconds % 60);
    }

    private function track_allowed($event) {
        $ip = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        if (!filter_var($ip, FILTER_VALIDATE_IP)) { $ip = ''; }
        $bucket = (string)floor(time() / 60);
        $key = 'vam_rl_' . substr(hash_hmac('sha256', $ip . ':' . $bucket, wp_salt('auth')), 0, 32);
        $count = (int)get_transient($key);
        if ($count >= 180) { return false; }
        set_transient($key, $count + 1, 90);
        return true;
    }

    public function track_event($request) {
        $ad = $this->active_ad($request);
        $event = (string)$request['event'];
        if (!in_array($event, array('impression','start','firstQuartile','midpoint','thirdQuartile','complete','skip','error'), true)) { return new WP_Error('vam_invalid_event', __('Invalid tracking event.', 'video-ad-manager'), array('status' => 400)); }
        if ($ad && $this->track_allowed($event)) { $this->increment($ad['id'], $event); }
        self::raw_headers('image/gif');
        // Transparent 1x1 tracking image.
        echo base64_decode('R0lGODlhAQABAAD/ACwAAAAAAQABAAACAUwAOw=='); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    public function click($request) {
        $ad = $this->active_ad($request);
        if (!$ad || !self::valid_external_url($ad['click_url'])) { status_header(404); exit; }
        if ($this->track_allowed('click')) { $this->increment($ad['id'], 'click'); }
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Referrer-Policy: no-referrer');
        wp_redirect($ad['click_url'], 302, 'Video Ad Manager'); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- External advertiser landing pages are required; URL is admin-controlled and strictly validated. // Validated, admin-controlled advertiser URL. wp_safe_redirect would reject external domains.
        exit;
    }
}

register_activation_hook(__FILE__, array('VAM_Free_Plugin', 'activate'));
VAM_Free_Plugin::instance();
require_once plugin_dir_path(__FILE__) . 'includes/mediarazzi-connector.php';
VAM_Mediarazzi_Connector::init();
