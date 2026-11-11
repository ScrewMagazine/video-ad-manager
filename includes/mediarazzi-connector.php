<?php
/** MEDIARAZZI account connector. No changes are required to the hosted server. */
if (!defined('ABSPATH')) { exit; }

final class VAM_Mediarazzi_Connector {
    const OPT = 'vam_mediarazzi_api_token';
    const API = 'https://mediarazzi.com/api/v1/';
    const SLUG = 'vam-mediarazzi';

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_post_vam_med_connect', array(__CLASS__, 'connect'));
        add_action('admin_post_vam_med_disconnect', array(__CLASS__, 'disconnect'));
        add_action('admin_post_vam_med_test', array(__CLASS__, 'test_connection'));
        add_action('admin_post_vam_med_create_commercial', array(__CLASS__, 'create_commercial'));
        add_action('admin_post_vam_med_create_pod', array(__CLASS__, 'create_pod'));
        add_action('admin_post_vam_med_save_pod_items', array(__CLASS__, 'save_pod_items'));
        add_action('admin_post_vam_med_create_tag', array(__CLASS__, 'create_tag'));
        add_action('admin_post_vam_med_create_rotation', array(__CLASS__, 'create_rotation'));
        add_action('admin_post_vam_med_add_rotation_ad', array(__CLASS__, 'add_rotation_ad'));
        add_action('admin_post_vam_med_remove_rotation_ad', array(__CLASS__, 'remove_rotation_ad'));
    }
    public static function menu() {
        add_submenu_page('vam-manager', __('MEDIARAZZI Connection', 'video-ad-manager'), __('MEDIARAZZI Pro', 'video-ad-manager'), 'manage_options', self::SLUG, array(__CLASS__, 'page'));
        add_submenu_page('vam-manager', __('Video Ad Manager Help', 'video-ad-manager'), __('Help & Documentation', 'video-ad-manager'), 'manage_options', 'vam-help', array(__CLASS__, 'help_page'));
    }
    private static function require_admin($action) {
        if (!current_user_can('manage_options')) { wp_die(esc_html__('Not permitted.', 'video-ad-manager'), '', array('response'=>403)); }
        check_admin_referer($action);
        // All connector forms are flat scalar-only forms; reject nested request fields.
        foreach ($_POST as $value) {
            if (!is_scalar($value) && $value !== null) {
                wp_die(esc_html__('Invalid form data.', 'video-ad-manager'), '', array('response' => 400));
            }
        }
    }
    private static function token() {
        $stored = (string)get_option(self::OPT, '');
        if ($stored === '') { return ''; }
        if (preg_match('/^fas_live_[a-f0-9]{48}$/',$stored)) {
            $sealed=self::seal($stored);
            if ($sealed !== '') { update_option(self::OPT,$sealed,false); }
            return $stored;
        }
        if (strpos($stored,'enc1:')!==0 || !function_exists('openssl_decrypt')) { return ''; }
        $bytes=base64_decode(substr($stored,5),true);
        if (!is_string($bytes) || strlen($bytes)<29) { return ''; }
        $key=hash('sha256',wp_salt('auth').wp_salt('secure_auth'),true);
        $plain=openssl_decrypt(substr($bytes,28),'aes-256-gcm',$key,OPENSSL_RAW_DATA,substr($bytes,0,12),substr($bytes,12,16));
        return is_string($plain) && preg_match('/^fas_live_[a-f0-9]{48}$/',$plain) ? $plain : '';
    }
    private static function seal($plain) {
        if (!function_exists('openssl_encrypt')) { return ''; }
        try {$iv=random_bytes(12);} catch (Throwable $e) {return '';}
        $key=hash('sha256',wp_salt('auth').wp_salt('secure_auth'),true);
        $tag='';$raw=openssl_encrypt($plain,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag);
        return is_string($raw) && strlen($tag)===16 ? 'enc1:'.base64_encode($iv.$tag.$raw) : '';
    }
    private static function redirect($status, $tab = 'overview') {
        wp_safe_redirect(add_query_arg(array('page'=>self::SLUG,'vam_med_status'=>$status,'tab'=>$tab), admin_url('admin.php')));
        exit;
    }
    private static function request($method, $path, $body=null, $token=null) {
        $token = $token === null ? self::token() : $token;
        if (!preg_match('/^fas_live_[a-f0-9]{48}$/', $token)) { return new WP_Error('not_connected', __('Connect using a valid MEDIARAZZI developer API key.', 'video-ad-manager')); }
        if (!preg_match('~^(commercials|pods|tags|statistics|rotations|usage)(?:/[0-9]+(?:/items(?:/[0-9]+)?)?)?$~', $path)) { return new WP_Error('bad_path', __('Unsupported API operation.', 'video-ad-manager')); }
        $args = array(
            'method'=>$method,
            'timeout'=>18,
            'redirection'=>0,
            'sslverify'=>true,
            'limit_response_size'=>1048576,
            'headers'=>array('Authorization'=>'Bearer '.$token,'Accept'=>'application/json','Content-Type'=>'application/json'),
        );
        if ($body !== null) { $args['body'] = wp_json_encode($body); }
        $res=wp_remote_request(self::API.$path, $args);
        if (is_wp_error($res)) { return new WP_Error('network', __('MEDIARAZZI could not be reached. Check the server connection and TLS settings.', 'video-ad-manager')); }
        $code=(int)wp_remote_retrieve_response_code($res);
        $raw=(string)wp_remote_retrieve_body($res);
        if (strlen($raw)>1048576) { return new WP_Error('large_response',__('MEDIARAZZI response exceeds limit.', 'video-ad-manager')); }
        $data=json_decode($raw, true);
        if (!is_array($data)) {
            /* translators: %d: HTTP status code returned by MEDIARAZZI. */
            return new WP_Error('invalid_response',sprintf(__('MEDIARAZZI returned an unexpected response (HTTP %d).', 'video-ad-manager'), $code));
        }
        if ($code<200 || $code>=300) {
            /* translators: %d: HTTP status code returned by MEDIARAZZI. */
            $message = sprintf(__('MEDIARAZZI returned HTTP %d. Confirm your API key permissions or contact MEDIARAZZI support.', 'video-ad-manager'), $code);
            return new WP_Error('api_error', $message);
        }
        return $data;
    }
    private static function last_error($error) {
        // Store only a short user-displayable message, never credentials or request headers.
        set_transient('vam_med_notice_'.get_current_user_id(), substr(sanitize_text_field($error),0,400), 60);
    }
    private static function fail($response, $tab='overview') {
        self::last_error(is_wp_error($response) ? $response->get_error_message() : $response);
        self::redirect('error',$tab);
    }
    public static function connect() {
        self::require_admin('vam_med_connect');
        check_admin_referer('vam_med_connect'); // Explicit nonce check for static analysis; require_admin also checks permissions.
        $key=isset($_POST['api_key']) && is_string($_POST['api_key']) ? sanitize_text_field(wp_unslash($_POST['api_key'])) : '';
        if (!preg_match('/^fas_live_[a-f0-9]{48}$/', $key)) { self::fail('Invalid API key format.'); }
        $test=self::request('GET','commercials',null,$key);
        if (is_wp_error($test)) { self::fail($test); }
        $sealed=self::seal($key);
        if ($sealed==='') { self::fail('This server needs OpenSSL support to store API keys securely.'); }
        update_option(self::OPT,$sealed,false);
        self::redirect('connected');
    }
    public static function test_connection() {
        self::require_admin('vam_med_test');
        check_admin_referer('vam_med_test'); // Explicit nonce check for static analysis; require_admin also checks permissions.
        $key = isset($_POST['api_key']) && is_string($_POST['api_key']) ? sanitize_text_field(wp_unslash($_POST['api_key'])) : '';
        if ($key === '') { $key = self::token(); }
        if (!preg_match('/^fas_live_[a-f0-9]{48}$/', $key)) { self::fail('Enter a MEDIARAZZI developer API key before testing.'); }
        $test = self::request('GET', 'commercials', null, $key);
        if (is_wp_error($test)) { self::fail($test); }
        // A test does not save credentials, create ads, or change the MEDIARAZZI account.
        self::redirect('tested');
    }
    public static function disconnect() {
        self::require_admin('vam_med_disconnect');
        check_admin_referer('vam_med_disconnect'); // Explicit nonce check for static analysis; require_admin also checks permissions.
        delete_option(self::OPT);
        self::redirect('disconnected');
    }
    public static function create_commercial() {
        self::require_admin('vam_med_create_commercial');
        check_admin_referer('vam_med_create_commercial'); // Explicit nonce check for static analysis; require_admin also checks permissions.
        $title=isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
        $mp4=esc_url_raw(wp_unslash($_POST['mp4_url']??''),array('https','http'));
        $click=esc_url_raw(wp_unslash($_POST['click_url']??''),array('https','http'));
        $duration=absint($_POST['duration_seconds']??0);
        $width=absint($_POST['width']??0);$height=absint($_POST['height']??0);
        $skip=isset($_POST['skip_after_seconds']) && $_POST['skip_after_seconds']==='never' ? null : absint($_POST['skip_after_seconds']??5);
        $host=wp_parse_url($mp4,PHP_URL_HOST);
        if (strlen($title)<2||strlen($title)>180||!$host||!preg_match('~\.mp4$~i',(string)wp_parse_url($mp4,PHP_URL_PATH))||($click&&!wp_parse_url($click,PHP_URL_HOST))||$duration<1||$duration>7200||$width<1||$width>7680||$height<1||$height>4320||($skip!==null&&($skip<1||$skip>7200))) { self::fail('Enter a valid title, direct MP4 URL, dimensions, duration and skip time.','commercials'); }
        $data=self::request('POST','commercials',array('title'=>$title,'mp4_url'=>$mp4,'click_url'=>$click,'duration_seconds'=>$duration,'width'=>$width,'height'=>$height,'skip_after_seconds'=>$skip));
        if (is_wp_error($data)) { self::fail($data,'commercials'); }
        self::redirect('commercial_created','commercials');
    }
    public static function create_pod() {
        self::require_admin('vam_med_create_pod');
        check_admin_referer('vam_med_create_pod'); // Explicit nonce check for static analysis; require_admin also checks permissions.
        $name=sanitize_text_field(wp_unslash($_POST['name']??''));
        if (strlen($name)<2||strlen($name)>150) { self::fail('Pod name must be 2–150 characters.','pods'); }
        $mode_input=isset($_POST['mode']) && is_string($_POST['mode']) ? sanitize_key(wp_unslash($_POST['mode'])) : '';
        $mode=$mode_input==='shuffle'?'shuffle':'sequence';
        $r=self::request('POST','pods',array('name'=>$name,'mode'=>$mode));
        if (is_wp_error($r)) { self::fail($r,'pods'); }
        self::redirect('pod_created','pods');
    }
    public static function save_pod_items() {
        self::require_admin('vam_med_save_pod_items');
        check_admin_referer('vam_med_save_pod_items'); // Explicit nonce check for static analysis; require_admin also checks permissions.
        $id=absint($_POST['pod_id']??0);
        $raw=isset($_POST['commercial_ids']) ? sanitize_text_field(wp_unslash($_POST['commercial_ids'])) : '';
        $ids=$raw===''?array():array_map('intval',array_map('trim',explode(',',$raw)));
        if (!$id||count($ids)>30||count($ids)!==count(array_unique($ids))||count(array_filter($ids,function($v){return $v<1;}))) { self::fail('Enter up to 30 unique, comma-separated commercial IDs.','pods'); }
        $r=self::request('PUT','pods/'.$id.'/items',array('commercial_ids'=>$ids));
        if (is_wp_error($r)) { self::fail($r,'pods'); }
        self::redirect('pod_updated','pods');
    }
    public static function create_tag() {
        self::require_admin('vam_med_create_tag');
        check_admin_referer('vam_med_create_tag'); // Explicit nonce check for static analysis; require_admin also checks permissions.
        $name=sanitize_text_field(wp_unslash($_POST['name']??''));
        $type=sanitize_key(wp_unslash($_POST['target_type']??''));
        $id=absint($_POST['target_id']??0);
        if (strlen($name)<2||strlen($name)>150||!in_array($type,array('commercial','pod','rotation'),true)||!$id) { self::fail('Choose a name and valid target.','tags'); }
        $params=array();
        foreach(array('sid','subid','platform','placement','app_id','content_id') as $field) {
            $value=sanitize_text_field(wp_unslash($_POST[$field]??''));
            if ($value!=='') {$params[$field]=substr($value,0,150);}
        }
        $r=self::request('POST','tags',array('name'=>$name,'target_type'=>$type,'target_id'=>$id,'params'=>$params));
        if (is_wp_error($r)) { self::fail($r,'tags'); }
        self::redirect('tag_created','tags');
    }
    public static function create_rotation() {
        self::require_admin('vam_med_create_rotation');
        check_admin_referer('vam_med_create_rotation'); // Explicit nonce check for static analysis; require_admin also checks permissions.
        $name = isset($_POST['name']) && is_string($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
        if (strlen($name) < 2 || strlen($name) > 150) { self::fail('Rotation name must be 2 to 150 characters.', 'rotations'); }
        $result = self::request('POST','rotations',array('name'=>$name));
        if (is_wp_error($result)) { self::fail($result,'rotations'); }
        self::redirect('rotation_created','rotations');
    }
    public static function add_rotation_ad() {
        self::require_admin('vam_med_add_rotation_ad');
        check_admin_referer('vam_med_add_rotation_ad'); // Explicit nonce check for static analysis; require_admin also checks permissions.
        $rotation = isset($_POST['rotation_id']) && is_scalar($_POST['rotation_id']) ? absint($_POST['rotation_id']) : 0;
        $commercial = isset($_POST['commercial_id']) && is_scalar($_POST['commercial_id']) ? absint($_POST['commercial_id']) : 0;
        $weight = isset($_POST['weight']) && is_scalar($_POST['weight']) ? absint($_POST['weight']) : 0;
        if (!$rotation || !$commercial || $weight < 1 || $weight > 100) { self::fail('Choose an existing rotation and ad, and a weight from 1 to 100.','rotations'); }
        $result=self::request('PUT','rotations/'.$rotation.'/items',array('commercial_id'=>$commercial,'weight'=>$weight));
        if (is_wp_error($result)) { self::fail($result,'rotations'); }
        self::redirect('rotation_updated','rotations');
    }
    public static function remove_rotation_ad() {
        self::require_admin('vam_med_remove_rotation_ad');
        check_admin_referer('vam_med_remove_rotation_ad'); // Explicit nonce check for static analysis; require_admin also checks permissions.
        $rotation=isset($_POST['rotation_id']) && is_scalar($_POST['rotation_id']) ? absint($_POST['rotation_id']) : 0;
        $commercial=isset($_POST['commercial_id']) && is_scalar($_POST['commercial_id']) ? absint($_POST['commercial_id']) : 0;
        if (!$rotation || !$commercial) { self::fail('Choose a valid rotation and ad.','rotations'); }
        $result=self::request('DELETE','rotations/'.$rotation.'/items/'.$commercial);
        if (is_wp_error($result)) { self::fail($result,'rotations'); }
        self::redirect('rotation_updated','rotations');
    }
    private static function form($action) {
        echo '<form class="vam-med-form" method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        echo '<input type="hidden" name="action" value="'.esc_attr('vam_med_'.$action).'">';
        wp_nonce_field('vam_med_'.$action);
    }
    private static function end_form($label) { submit_button(esc_html($label));echo '</form>'; }
    private static function text_field($name,$label,$placeholder='',$type='text',$required=true) {
        echo '<p><label><strong>'.esc_html($label).'</strong><br><input class="regular-text" type="'.esc_attr($type).'" name="'.esc_attr($name).'" placeholder="'.esc_attr($placeholder).'" '.($required?'required':'').'></label></p>';
    }
    private static function ad_listing($data) {
        if (is_wp_error($data)) {
            echo '<div class="notice notice-error"><p>'.esc_html($data->get_error_message()).'</p></div>';
            return;
        }
        $rows = $data['data'] ?? array();
        if (!$rows || !is_array($rows)) { echo '<p>' . esc_html__('No ads found.', 'video-ad-manager') . '</p>'; return; }
        echo '<div class="vam-med-table-wrap"><table class="widefat striped"><thead><tr><th>' . esc_html__('Ad', 'video-ad-manager') . '</th><th>' . esc_html__('Duration', 'video-ad-manager') . '</th><th>' . esc_html__('Status', 'video-ad-manager') . '</th><th>' . esc_html__('Actions', 'video-ad-manager') . '</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            if (!is_array($row)) { continue; }
            $key = isset($row['public_key']) && is_string($row['public_key']) ? $row['public_key'] : '';
            $url = preg_match('/^[a-f0-9]{24}$/', $key) ? 'https://ads.mediarazzi.com/vast/'.$key : '';
            echo '<tr><td>'.esc_html(is_scalar($row['title'] ?? null) ? (string)$row['title'] : '').'</td>';
            echo '<td>'.esc_html(is_scalar($row['duration_seconds'] ?? null) ? (string)$row['duration_seconds'] : '').' sec</td>';
            echo '<td>'.esc_html(is_scalar($row['status'] ?? null) ? (string)$row['status'] : '').'</td><td>';
            if ($url !== '') {
                echo '<button type="button" class="button vam-med-copy" data-vast="'.esc_attr($url).'">' . esc_html__('Copy VAST Tag', 'video-ad-manager') . '</button> ';
                echo '<a class="button" href="'.esc_url($url).'" target="_blank" rel="noopener noreferrer">' . esc_html__('View VAST', 'video-ad-manager') . '</a>';
            } else { echo '<span class="description">VAST URL unavailable</span>'; }
            echo '</td></tr>';
        }
        echo '</tbody></table></div><p class="description">To edit an existing hosted ad, use your MEDIARAZZI dashboard. WordPress editing will be added when the API supports it.</p>';

    }
    private static function listing($data,$columns) {
        if (is_wp_error($data)) { echo '<div class="notice notice-error"><p>'.esc_html($data->get_error_message()).'</p></div>';return; }
        $rows=isset($data['data']) && is_array($data['data']) ? $data['data'] : array();
        if (!$rows) { echo '<p>' . esc_html__('Nothing here yet.', 'video-ad-manager') . '</p>';return; }
        echo '<div class="vam-med-table-wrap"><table class="widefat striped"><thead><tr>';
        foreach($columns as $column=>$title){echo '<th>'.esc_html($title).'</th>';}
        echo '</tr></thead><tbody>';
        foreach($rows as $row) {
            if (!is_array($row)) { continue; }
            echo '<tr>';
            foreach($columns as $col=>$label) {
                $value=$row[$col]??'';
                if ($col==='vast_url' && is_string($value) && wp_http_validate_url($value)) {echo '<td><button type="button" class="button vam-med-copy" data-vast="'.esc_attr($value).'">' . esc_html__('Copy VAST Tag', 'video-ad-manager') . '</button> <a class="button" href="'.esc_url($value).'" target="_blank" rel="noopener noreferrer">' . esc_html__('View VAST', 'video-ad-manager') . '</a></td>';}
                elseif ($col==='public_key' && is_string($value) && preg_match('/^[a-f0-9]{24}$/',$value)) { $vast='https://ads.mediarazzi.com/vast/'.$value;echo '<td><button type="button" class="button vam-med-copy" data-vast="'.esc_attr($vast).'">' . esc_html__('Copy VAST Tag', 'video-ad-manager') . '</button> <a class="button" href="'.esc_url($vast).'" target="_blank" rel="noopener noreferrer">' . esc_html__('View VAST', 'video-ad-manager') . '</a></td>'; }
                else { echo '<td>'.esc_html(is_scalar($value)?(string)$value:'').'</td>'; }
            }
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }
    private static function statistics_table($response) {
        if (is_wp_error($response)) {
            echo '<div class="notice notice-error"><p>'.esc_html($response->get_error_message()).'</p></div>';
            return;
        }
        $raw = isset($response['data']) && is_array($response['data']) ? $response['data'] : array();
        if (!$raw) { echo '<p>' . esc_html__('No statistics available yet.', 'video-ad-manager') . '</p>'; return; }
        // MEDIARAZZI returns one row per ad AND event type. Pivot those rows
        // into one row per ad, with event types running horizontally.
        $ads = array(); $event_types = array(); $totals = array();
        foreach ($raw as $item) {
            if (!is_array($item) || !isset($item['id'])) { continue; }
            $id = (string)absint($item['id']);
            if (!isset($ads[$id])) {
                $ads[$id] = array('title' => sanitize_text_field((string)($item['title'] ?? 'Untitled ad')), 'counts' => array());
            }
            $type = isset($item['event_type']) && is_scalar($item['event_type']) ? trim((string)$item['event_type']) : '';
            if ($type === '') { continue; } // LEFT JOIN row for ads with no events.
            $count = max(0, (int)($item['events'] ?? 0));
            $event_types[$type] = true;
            $ads[$id]['counts'][$type] = ($ads[$id]['counts'][$type] ?? 0) + $count;
            $totals[$type] = ($totals[$type] ?? 0) + $count;
        }
        if (!$ads) { echo '<p>' . esc_html__('No ads found.', 'video-ad-manager') . '</p>';return; }
        $order = array('request', 'vast', 'vast_request', 'impression', 'start', 'firstQuartile', 'first_quartile', 'midpoint', 'thirdQuartile', 'third_quartile', 'complete', 'skip', 'click', 'error');
        $types = array_keys($event_types);
        usort($types, static function($a,$b) use($order) {
            $ai = array_search($a,$order,true);$bi = array_search($b,$order,true);
            $ai = $ai === false ? 999 : $ai;$bi = $bi === false ? 999 : $bi;
            return $ai === $bi ? strcasecmp($a,$b) : $ai-$bi;
        });
        $labels = array('request'=>__('Requests','video-ad-manager'),'vast'=>__('VAST Requests','video-ad-manager'),'vast_request'=>__('VAST Requests','video-ad-manager'),'impression'=>__('Impressions','video-ad-manager'),'start'=>__('Starts','video-ad-manager'),'firstQuartile'=>'25%','first_quartile'=>'25%','midpoint'=>'50%','thirdQuartile'=>'75%','third_quartile'=>'75%','complete'=>__('Completes','video-ad-manager'),'skip'=>__('Skips','video-ad-manager'),'click'=>__('Clicks','video-ad-manager'),'error'=>__('Errors','video-ad-manager'));
        if ($types) {
            echo '<div class="vam-med-summary" aria-label="Account event totals">';
            foreach ($types as $type) {
                echo '<div class="vam-med-stat"><strong>'.esc_html(number_format_i18n($totals[$type] ?? 0)).'</strong><span>'.esc_html($labels[$type] ?? ucwords(str_replace('_',' ',$type))).'</span></div>';
            }
            echo '</div>';
        }
        echo '<div class="vam-med-stats-scroll"><table class="widefat striped vam-med-stats-table"><thead><tr><th scope="col">' . esc_html__('Your Ads', 'video-ad-manager') . '</th>';
        foreach ($types as $type) { echo '<th scope="col">'.esc_html($labels[$type] ?? ucwords(str_replace('_',' ',$type))).'</th>'; }
        echo '</tr></thead><tbody>';
        foreach ($ads as $ad) {
            echo '<tr><th scope="row">'.esc_html($ad['title']).'</th>';
            foreach ($types as $type) { echo '<td>'.esc_html(number_format_i18n($ad['counts'][$type] ?? 0)).'</td>'; }
            echo '</tr>';
        }
        echo '</tbody></table></div>';
        echo '<p class="description">Metrics are reported by the MEDIARAZZI API. Each ad occupies one row, with its event counts displayed across the columns. Zero means no reported events of that type.</p>';
        if (count($raw) >= 500) { echo '<div class="notice notice-warning inline"><p>' . esc_html__('The MEDIARAZZI API returned its current 500-row limit. Some event totals may be incomplete.', 'video-ad-manager') . '</p></div>'; }
    }
    private static function count_from_api($resource) {
        $response = self::request('GET', $resource);
        if (is_wp_error($response)) { return array('value'=>null,'error'=>$response->get_error_message()); }
        $rows = isset($response['data']) && is_array($response['data']) ? $response['data'] : array();
        return array('value'=>count($rows),'error'=>'');
    }
    private static function overview_card($label, $value, $detail='', $is_pro=false) {
        echo '<div class="vam-overview-card"><span class="vam-overview-label">'.esc_html($label).'</span>';
        if ($is_pro) {
            echo '<strong class="vam-overview-pro">' . esc_html__('Available in PRO', 'video-ad-manager') . '</strong>';
        } elseif ($value === null) {
            echo '<strong class="vam-overview-value">—</strong><span class="vam-overview-detail">' . esc_html__('Not available from API', 'video-ad-manager') . '</span>';
        } else {
            echo '<strong class="vam-overview-value">'.esc_html(number_format_i18n((int)$value)).'</strong>';
        }
        if ($detail !== '') { echo '<span class="vam-overview-detail">'.esc_html($detail).'</span>'; }
        echo '</div>';
    }
    private static function usage_card($label,$info,$detail) {
        echo '<div class="vam-overview-card"><span class="vam-overview-label">'.esc_html($label).'</span>';
        if (!is_array($info) || !isset($info['limit_gb']) || !is_numeric($info['limit_gb'])) {
            echo '<strong class="vam-overview-value">' . esc_html__('Unavailable', 'video-ad-manager') . '</strong><span class="vam-overview-detail">'.esc_html($detail).'</span></div>';
            return;
        }
        $limit=max(0,(float)$info['limit_gb']);
        $used=isset($info['used_gb']) && is_numeric($info['used_gb']) ? max(0,(float)$info['used_gb']) : null;
        if ($used===null) {
            echo '<strong class="vam-overview-value">' . esc_html__('Not measured', 'video-ad-manager') . '</strong>';
        } else {
            echo '<strong class="vam-overview-value">'.esc_html(number_format_i18n($used,2)).' GB</strong>';
        }
        echo '<span class="vam-overview-detail">'.esc_html(number_format_i18n($limit,0)).' GB included · '.esc_html($detail).'</span>';
        if ($used!==null && $limit>0) {
            $percent=100*$used/$limit;
            $fill=min(100,max(0,$percent));
            echo '<div class="vam-usage-track" role="progressbar" aria-label="'.esc_attr($label).' allowance" aria-valuemin="0" aria-valuemax="100" aria-valuenow="'.esc_attr(number_format($fill,1,'.','')).'">';
            echo '<span class="vam-usage-fill" style="width:'.esc_attr(number_format($fill,2,'.','')).'%"></span></div>';
            echo '<span class="vam-overview-detail">'.esc_html(number_format_i18n($percent,1)).'% of allowance used</span>';
        }
        echo '</div>';
    }
    private static function overview() {
        // The existing API exposes collection rows and commercial event counts but not
        // storage/bandwidth entitlements. Never substitute dummy usage numbers.
        $ads=self::count_from_api('commercials');
        $pods=self::count_from_api('pods');
        $rotations=self::count_from_api('rotations');
        $tags=self::count_from_api('tags');
        echo '<div class="vam-overview-heading"><div><h2>' . esc_html__('Account Overview', 'video-ad-manager') . '</h2><p>' . esc_html__('Live data from your connected MEDIARAZZI account.', 'video-ad-manager') . '</p></div><a class="button" href="https://mediarazzi.com/" target="_blank" rel="noopener noreferrer">' . esc_html__('Open MEDIARAZZI Dashboard', 'video-ad-manager') . '</a></div>';
        echo '<div class="vam-overview-grid">';
        self::overview_card(__('Your Ads', 'video-ad-manager'),$ads['value'],'Hosted commercials');
        self::overview_card(__('Ad Pods', 'video-ad-manager'),$pods['value'],'Configured pods');
        self::overview_card(__('Ad Rotations', 'video-ad-manager'),$rotations['value'],'Weighted rotations');
        self::overview_card(__('Advanced Tags', 'video-ad-manager'),$tags['value'],'Saved tags');
        // Usage is private account-scoped data. Do not infer zero when the server cannot measure it.
        $usage=self::request('GET','usage');
        if (is_wp_error($usage) || empty($usage['data']) || !is_array($usage['data'])) {
            self::usage_card(__('Storage', 'video-ad-manager'),null, is_wp_error($usage) ? 'Usage API unavailable; server patch required' : 'No verified usage data');
            self::usage_card(__('Bandwidth', 'video-ad-manager'),null, 'Usage API unavailable; server patch required');
        } else {
            $d=$usage['data'];
            $storage=isset($d['storage']) && is_array($d['storage']) ? $d['storage'] : array();
            $bandwidth=isset($d['bandwidth']) && is_array($d['bandwidth']) ? $d['bandwidth'] : array();
            self::usage_card(__('Storage', 'video-ad-manager'),$storage,'Recorded video storage');
            self::usage_card(__('Bandwidth', 'video-ad-manager'),$bandwidth,'Calculated from player events, not CDN meter');
        }
        echo '</div>';
        if ($rotations['value']===null) { echo '<div class="notice notice-warning inline"><p>' . esc_html__('Rotation data is unavailable. Check that the MEDIARAZZI rotation API patch is installed and working.', 'video-ad-manager') . '</p></div>'; }
        echo '<section class="vam-med-panel"><h2>Commercial Performance</h2><p class="description">Reported tracking events for your connected account. These are not unique viewers.</p>';
        self::statistics_table(self::request('GET','statistics'));
        echo '</section>';
        echo '<div class="vam-overview-footer"><div><strong>' . esc_html__('Account connection', 'video-ad-manager') . '</strong><p>' . esc_html__('Your API key is stored encrypted in WordPress. Test or disconnect your account below.', 'video-ad-manager') . '</p></div><div class="vam-overview-actions">';
        self::form('test');self::end_form(__('Test Connection', 'video-ad-manager'));
        self::form('disconnect');self::end_form(__('Disconnect', 'video-ad-manager'));
        echo '</div></div>';
    }
    private static function connection_steps() {
        echo '<p>MEDIARAZZI Pro is a separate $49.95/month subscription. Your free WordPress commercial works even when not connected.</p>';
        echo '<ol><li><a href="https://mediarazzi.com/" target="_blank" rel="noopener noreferrer">' . esc_html__('Sign up or log in to MEDIARAZZI', 'video-ad-manager') . '</a>.</li>';
        echo '<li>' . esc_html__('In your MEDIARAZZI dashboard, open', 'video-ad-manager') . ' <strong>' . esc_html__('Advanced Tag Builder', 'video-ad-manager') . '</strong> ' . esc_html__('and find', 'video-ad-manager') . ' <strong>' . esc_html__('Developer API / API Keys', 'video-ad-manager') . '</strong>.</li>';
        echo '<li>' . esc_html__('Create a key named', 'video-ad-manager') . ' <strong>' . esc_html__('WordPress Video Ad Manager', 'video-ad-manager') . '</strong> ' . esc_html__('and enable', 'video-ad-manager') . ' <strong>' . esc_html__('read', 'video-ad-manager') . '</strong> ' . esc_html__('and', 'video-ad-manager') . ' <strong>' . esc_html__('write', 'video-ad-manager') . '</strong> ' . esc_html__('permissions.', 'video-ad-manager') . '</li>';
        echo '<li>' . esc_html__('Copy the key when it appears. Paste it below, then choose', 'video-ad-manager') . ' <strong>' . esc_html__('Test Connection', 'video-ad-manager') . '</strong> ' . esc_html__('or', 'video-ad-manager') . ' <strong>' . esc_html__('Save & Connect', 'video-ad-manager') . '</strong>.</li>';
        echo '<li>' . esc_html__('After connecting, use the MEDIARAZZI Pro tabs for ads, rotations, pods, saved VAST tags, and account statistics.', 'video-ad-manager') . '</li></ol>';
        echo '<p><strong>' . esc_html__('Security:', 'video-ad-manager') . '</strong> ' . esc_html__('Keep your key private. Do not put it in a public page, VAST URL, or support screenshot. If exposed, revoke it in MEDIARAZZI and create another.', 'video-ad-manager') . '</p>';
    }
    public static function help_page() {
        if (!current_user_can('manage_options')) { return; }
        echo '<div class="wrap vam-wrap" style="max-width:920px"><h1>Video Ad Manager: Help &amp; Documentation</h1>';
        echo '<h2>' . esc_html__('1. Free: Create your first commercial', 'video-ad-manager') . '</h2><ol><li>' . esc_html__('Open', 'video-ad-manager') . ' <strong>' . esc_html__('Video Ad Manager', 'video-ad-manager') . '</strong> ' . esc_html__('in the WordPress sidebar.', 'video-ad-manager') . '</li><li>' . esc_html__('Choose an MP4 in your WordPress Media Library or enter a directly playable public MP4 URL.', 'video-ad-manager') . '</li><li>' . esc_html__('Set a name, click destination, video duration and size, and the skip delay (5 seconds by default).', 'video-ad-manager') . '</li><li>' . esc_html__('Enable the commercial and save it. Copy its VAST URL into a compatible player.', 'video-ad-manager') . '</li></ol>';
        echo '<p>' . esc_html__('The free edition supports', 'video-ad-manager') . ' <strong>' . esc_html__('one active commercial', 'video-ad-manager') . '</strong>' . esc_html__('. Replacing the creative preserves its VAST URL. A VAST-capable video player, not this plugin, renders the click overlay and skip button.', 'video-ad-manager') . '</p>';
        echo '<h2>' . esc_html__('2. Connect MEDIARAZZI Pro', 'video-ad-manager') . '</h2>';self::connection_steps();
        echo '<p><a class="button button-primary" href="'.esc_url(admin_url('admin.php?page='.self::SLUG)).'">' . esc_html__('Open MEDIARAZZI Connection', 'video-ad-manager') . '</a></p>';
        echo '<h2>' . esc_html__('3. Your Ads, pods, and advanced tags', 'video-ad-manager') . '</h2><p>' . esc_html__('Once connected, create ads with public MP4 URLs, create pods, set their ordered commercial IDs, and generate saved VAST tags using existing MEDIARAZZI API operations. Hosted file uploads remain available through the MEDIARAZZI website.', 'video-ad-manager') . '</p>';
        echo '<h2>' . esc_html__('4. Statistics', 'video-ad-manager') . '</h2><p>' . esc_html__('Free statistics are stored locally in WordPress. MEDIARAZZI statistics come from the connected account. VAST requests, impressions, starts, skips, clicks and completions are distinct events. Reporting accuracy depends on a player firing the relevant trackers. Rotation management requires the separate MEDIARAZZI rotations API patch; advanced report filtering remains dependent on future API work.', 'video-ad-manager') . '</p>';
        echo '<h2>' . esc_html__('5. Connection troubleshooting', 'video-ad-manager') . '</h2><ul><li><strong>' . esc_html__('Invalid key:', 'video-ad-manager') . '</strong> ' . esc_html__('Create a developer key in MEDIARAZZI and paste the entire key, without spaces.', 'video-ad-manager') . '</li><li><strong>401/403:</strong> ' . esc_html__('Check that the key is active and has read/write permissions; revoke and recreate if needed.', 'video-ad-manager') . '</li><li><strong>' . esc_html__('Connection error:', 'video-ad-manager') . '</strong> ' . esc_html__('Check WordPress outbound HTTPS access, firewall settings, and MEDIARAZZI availability.', 'video-ad-manager') . '</li><li><strong>' . esc_html__('VAST ad does not play:', 'video-ad-manager') . '</strong> ' . esc_html__('Confirm the MP4 can be fetched, the media type is supported, the player supports VAST 3.0 and skipping, and the endpoint is reachable.', 'video-ad-manager') . '</li><li><strong>' . esc_html__('Missing playback statistics:', 'video-ad-manager') . '</strong> ' . esc_html__('Ensure your player calls the VAST tracking URLs. Merely fetching VAST XML does not indicate a playback impression.', 'video-ad-manager') . '</li></ul>';
        echo '<p><strong>' . esc_html__('Note:', 'video-ad-manager') . '</strong> ' . esc_html__('The Test Connection button verifies API read access. It does not prove write permissions, billing status, or successful ad playback.', 'video-ad-manager') . '</p></div>';
    }
    public static function page() {
        if (!current_user_can('manage_options')) {return;}
        $connected=(bool)self::token();
        // Read-only navigation parameter; no nonce is needed for GET display.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $tab=isset($_GET['tab']) && is_string($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'overview';
        if(!in_array($tab,array('overview','commercials','rotations','pods','tags','statistics'),true)) {$tab='overview';}
        // Read-only notice parameter; writes require check_admin_referer in the POST handler.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $status=isset($_GET['vam_med_status']) && is_string($_GET['vam_med_status']) ? sanitize_key(wp_unslash($_GET['vam_med_status'])) : '';
        $messages=array('tested'=>__('Connection test passed. The API accepted your key. No account changes were made.','video-ad-manager'),'connected'=>__('Connected to MEDIARAZZI.','video-ad-manager'),'disconnected'=>__('Disconnected. Existing hosted ads have not been altered.','video-ad-manager'),'commercial_created'=>__('Ad created.','video-ad-manager'),'pod_created'=>__('Ad pod created.','video-ad-manager'),'pod_updated'=>__('Ad pod items updated.','video-ad-manager'),'tag_created'=>__('Saved tag created.','video-ad-manager'),'rotation_created'=>__('Rotation created.','video-ad-manager'),'rotation_updated'=>__('Rotation updated.','video-ad-manager'));
        echo '<div class="wrap vam-wrap"><h1>' . esc_html__('MEDIARAZZI Pro', 'video-ad-manager') . '</h1><p>' . esc_html__('Use your MEDIARAZZI VAST Ad Server account from WordPress. Your free locally served commercial remains independent.', 'video-ad-manager') . '</p>';
        if (isset($messages[$status])){echo '<div class="notice notice-success"><p>'.esc_html($messages[$status]).'</p></div>';}
        if ($status==='error') { $notice=get_transient('vam_med_notice_'.get_current_user_id());delete_transient('vam_med_notice_'.get_current_user_id());echo '<div class="notice notice-error"><p>'.esc_html($notice?:__('Operation failed.', 'video-ad-manager')).'</p></div>'; }
        if (!$connected) {
            echo '<div class="card" style="max-width:750px;padding:16px"><h2>Connect your MEDIARAZZI account</h2>';
            self::connection_steps();
            self::form('connect');self::text_field('api_key',__('Developer API key', 'video-ad-manager'),'fas_live_…','password');self::end_form(__('Save & Connect MEDIARAZZI', 'video-ad-manager'));
            echo '<p>' . esc_html__('Want to check your key first? The test below checks read access without saving the key.', 'video-ad-manager') . '</p>';
            self::form('test');self::text_field('api_key',__('API key to test', 'video-ad-manager'),'fas_live_…','password');self::end_form(__('Test Connection (does not save)', 'video-ad-manager'));
            echo '<p><a class="button" href="https://mediarazzi.com/" target="_blank" rel="noopener noreferrer">Get MEDIARAZZI Pro ($49.95/month)</a> &nbsp; <a href="'.esc_url(admin_url('admin.php?page=vam-help')).'">' . esc_html__('Read full help', 'video-ad-manager') . '</a></p></div></div>';return;
        }
        echo '<div class="notice notice-info"><p><strong>' . esc_html__('Connected.', 'video-ad-manager') . '</strong> ' . esc_html__('The API key is encrypted before storage in WordPress options using keys derived from WordPress security salts. Restrict administrator and database access, use HTTPS, and revoke the key in MEDIARAZZI if compromised. Connection does not modify billing.', 'video-ad-manager') . '</p></div>';
        echo '<nav class="nav-tab-wrapper">';
        foreach(array('overview'=>__('Overview','video-ad-manager'),'commercials'=>__('Your Ads','video-ad-manager'),'rotations'=>__('Ad Rotations','video-ad-manager'),'pods'=>__('Ad Pods','video-ad-manager'),'tags'=>__('Advanced Tag Builder','video-ad-manager'),'statistics'=>__('Statistics','video-ad-manager')) as $t=>$label) {
            $url=add_query_arg(array('page'=>self::SLUG,'tab'=>$t),admin_url('admin.php'));
            echo '<a class="nav-tab '.($tab===$t?'nav-tab-active':'').'" href="'.esc_url($url).'">'.esc_html($label).'</a>';
        }
        echo '</nav><div class="vam-med-content">';
        if ($tab==='overview') {
            self::overview();
        } elseif ($tab==='commercials') {
            echo '<h2>' . esc_html__('Your Ads','video-ad-manager') . '</h2>';self::ad_listing(self::request('GET','commercials'));
            echo '<h2>' . esc_html__('Create New Ad','video-ad-manager') . '</h2><p>' . esc_html__('Use a publicly accessible direct MP4 URL. MEDIARAZZI video uploads remain available in its hosted dashboard.', 'video-ad-manager') . '</p>';
            self::form('create_commercial');self::text_field('title',__('Name', 'video-ad-manager'),'New commercial');self::text_field('mp4_url',__('MP4 URL', 'video-ad-manager'),'https://cdn.example.com/ad.mp4','url');self::text_field('click_url',__('Click-through URL', 'video-ad-manager'),'https://advertiser.example.com','url',false);
            self::text_field('duration_seconds',__('Duration (seconds)', 'video-ad-manager'),'30','number');self::text_field('width',__('Width (px)', 'video-ad-manager'),'1280','number');self::text_field('height',__('Height (px)', 'video-ad-manager'),'720','number');
            echo '<p><label><strong>' . esc_html__('Skip after', 'video-ad-manager') . '</strong><br><select name="skip_after_seconds"><option value="5">' . esc_html__('5 seconds', 'video-ad-manager') . '</option><option value="10">' . esc_html__('10 seconds', 'video-ad-manager') . '</option><option value="15">' . esc_html__('15 seconds', 'video-ad-manager') . '</option><option value="30">' . esc_html__('30 seconds', 'video-ad-manager') . '</option><option value="never">' . esc_html__('Never', 'video-ad-manager') . '</option></select></label></p>';self::end_form(__('Create Commercial', 'video-ad-manager'));
        } elseif ($tab==='rotations') {
            echo '<h2>' . esc_html__('Ad Rotations','video-ad-manager') . '</h2><p>' . esc_html__('Rotate among hosted ads using MEDIARAZZI weighted delivery. Requires the matching MEDIARAZZI rotations API extension on your server.', 'video-ad-manager') . '</p>';
            $result=self::request('GET','rotations');
            if (is_wp_error($result)) {
                echo '<div class="notice notice-warning inline"><p>'.esc_html($result->get_error_message()).' If the API responds 404, install the separate server patch first.</p></div>';
            } else {
                $rows=$result['data']??array();
                echo '<div class="vam-med-table-wrap"><table class="widefat striped"><thead><tr><th>' . esc_html__('Rotation', 'video-ad-manager') . '</th><th>' . esc_html__('ID', 'video-ad-manager') . '</th><th>' . esc_html__('Ad weights', 'video-ad-manager') . '</th><th>' . esc_html__('Actions', 'video-ad-manager') . '</th></tr></thead><tbody>';
                foreach ($rows as $row) {
                    if (!is_array($row)) { continue; }
                    $key=is_string($row['public_key']??null)?$row['public_key']:'';
                    $url=preg_match('/^[a-f0-9]{24}$/',$key)?'https://ads.mediarazzi.com/vast/'.$key:'';
                    $items=array();foreach (($row['items']??array()) as $item) { if (is_array($item)) {$items[]=sanitize_text_field((string)($item['title']??'Ad')).' ('.absint($item['weight']??0).')';} }
                    echo '<tr><td>'.esc_html(is_scalar($row['name']??null)?(string)$row['name']:'').'</td><td>'.esc_html((string)absint($row['id']??0)).'</td><td>'.esc_html(implode(', ',$items)).'</td><td>';
                    if ($url) { echo '<button type="button" class="button vam-med-copy" data-vast="'.esc_attr($url).'">' . esc_html__('Copy VAST Tag', 'video-ad-manager') . '</button> <a class="button" href="'.esc_url($url).'" target="_blank" rel="noopener noreferrer">' . esc_html__('View VAST', 'video-ad-manager') . '</a>';  }
                    echo '</td></tr>';
                }
                echo '</tbody></table></div>';
                echo '<h3>' . esc_html__('Create rotation','video-ad-manager') . '</h3>';self::form('create_rotation');self::text_field('name',__('Rotation name', 'video-ad-manager'),'Homepage rotation');self::end_form(__('Create Rotation', 'video-ad-manager'));
                echo '<h3>' . esc_html__('Add or update an ad weight', 'video-ad-manager') . '</h3><p>' . esc_html__('Use the ID of the rotation above and the ad ID listed on Your Ads. Higher weights are selected more often.', 'video-ad-manager') . '</p>';
                self::form('add_rotation_ad');self::text_field('rotation_id',__('Rotation ID', 'video-ad-manager'),'1','number');self::text_field('commercial_id',__('Ad ID', 'video-ad-manager'),'2','number');self::text_field('weight',__('Weight (1 to 100)', 'video-ad-manager'),'50','number');self::end_form(__('Save Ad Weight', 'video-ad-manager'));
                echo '<h3>' . esc_html__('Remove ad from rotation','video-ad-manager') . '</h3>';self::form('remove_rotation_ad');self::text_field('rotation_id',__('Rotation ID', 'video-ad-manager'),'1','number');self::text_field('commercial_id',__('Ad ID', 'video-ad-manager'),'2','number');self::end_form(__('Remove Ad', 'video-ad-manager'));
            }
        } elseif ($tab==='pods') {
            echo '<h2>' . esc_html__('Ad Pods','video-ad-manager') . '</h2>';self::listing(self::request('GET','pods'),array('name'=>__('Pod','video-ad-manager'),'mode'=>__('Mode','video-ad-manager'),'status'=>__('Status','video-ad-manager'),'public_key'=>__('Actions','video-ad-manager')));
            echo '<h2>' . esc_html__('Create pod','video-ad-manager') . '</h2>';self::form('create_pod');self::text_field('name',__('Pod name', 'video-ad-manager'),'Pre-roll break');echo '<p><select name="mode"><option value="sequence">' . esc_html__('Sequence', 'video-ad-manager') . '</option><option value="shuffle">' . esc_html__('Shuffle', 'video-ad-manager') . '</option></select></p>';self::end_form(__('Create Pod', 'video-ad-manager'));
            echo '<h2>' . esc_html__('Manage Pod Ads','video-ad-manager') . '</h2><p>' . esc_html__('Use the ID of a pod above and the ordered ad IDs from Your Ads. Submitting an empty commercial list clears the pod.', 'video-ad-manager') . '</p>';
            self::form('save_pod_items');self::text_field('pod_id',__('Pod ID', 'video-ad-manager'),'1','number');self::text_field('commercial_ids',__('Commercial IDs (comma-separated)', 'video-ad-manager'),'5, 4, 8','text',false);self::end_form(__('Save Pod Items', 'video-ad-manager'));
        } elseif ($tab==='tags') {
            echo '<h2>' . esc_html__('Advanced Tag Builder','video-ad-manager') . '</h2>';self::listing(self::request('GET','tags'),array('name'=>__('Tag','video-ad-manager'),'target_type'=>__('Type','video-ad-manager'),'target_id'=>__('Target ID','video-ad-manager'),'vast_url'=>__('Actions','video-ad-manager'),'status'=>__('Status','video-ad-manager')));
            echo '<h2>' . esc_html__('Create Advanced Tag','video-ad-manager') . '</h2><p>' . esc_html__('Create a permanent MEDIARAZZI tag targeting one of your hosted commercials or pods. Tag parameters are applied server-side.', 'video-ad-manager') . '</p>';
            self::form('create_tag');self::text_field('name',__('Tag name', 'video-ad-manager'),'Homepage pre-roll');echo '<p><label><strong>' . esc_html__('Target type', 'video-ad-manager') . '</strong><br><select name="target_type"><option value="commercial">' . esc_html__('Commercial', 'video-ad-manager') . '</option><option value="pod">' . esc_html__('Ad Pod', 'video-ad-manager') . '</option><option value="rotation">' . esc_html__('Ad Rotation', 'video-ad-manager') . '</option></select></label></p>';
            self::text_field('target_id',__('Target ID', 'video-ad-manager'),'1','number');
            foreach(array('sid'=>__('Site ID','video-ad-manager'),'subid'=>__('Sub ID','video-ad-manager'),'platform'=>__('Platform','video-ad-manager'),'placement'=>__('Placement','video-ad-manager'),'app_id'=>__('App ID','video-ad-manager'),'content_id'=>__('Content ID','video-ad-manager')) as $field=>$label){self::text_field($field,$label,'','text',false);}
            self::end_form(__('Create VAST Tag', 'video-ad-manager'));
        } elseif ($tab==='statistics') {
            echo '<h2>' . esc_html__('Commercial Performance','video-ad-manager') . '</h2><p>' . esc_html__('These are MEDIARAZZI player-reported events, not deduplicated unique viewers. Metrics are fetched from your account.', 'video-ad-manager') . '</p>';
            self::statistics_table(self::request('GET','statistics'));
            echo '<p>' . esc_html__('Additional date, domain, geography, and device reporting requires further MEDIARAZZI API endpoints. No unsupported metrics are fabricated here.', 'video-ad-manager') . '</p>';
        }
        echo '</div></div>';

    }
}
