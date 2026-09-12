<?php
defined('ABSPATH') || exit;

final class Landing_Bird_Scheduler
{
    private const OPTION = 'lb_scheduler_options';
    private const TABLE_VERSION = '1';
    private const STATUSES = ['pending_payment','confirmed_paid','confirmed_external','reschedule_requested','rescheduled','refund_requested','refund_pending','refunded','cancelled','expired'];

    public static function defaults(): array
    {
        return ['price' => '50.00', 'timezone' => 'America/Mexico_City', 'days' => [1,2,3,4,5], 'start' => '09:00', 'end' => '17:00', 'break_start' => '', 'break_end' => '', 'min_notice' => 4, 'max_days' => 60, 'max_duration' => 90, 'mode' => 'Online or in-person instructions will be provided after payment.', 'overrides' => []];
    }

    public static function activate(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'lb_scheduler_bookings';
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE {$table} (id bigint(20) unsigned NOT NULL AUTO_INCREMENT,user_id bigint(20) unsigned NOT NULL DEFAULT 0,email varchar(190) NOT NULL,phone varchar(40) NOT NULL DEFAULT '',start_at datetime NOT NULL,end_at datetime NOT NULL,duration smallint unsigned NOT NULL,status varchar(32) NOT NULL,timezone varchar(64) NOT NULL,mode text NOT NULL,order_id bigint(20) unsigned NOT NULL DEFAULT 0,payment_method varchar(80) NOT NULL DEFAULT '',payment_reference varchar(190) NOT NULL DEFAULT '',admin_note text NOT NULL,hold_expires_at datetime NULL,created_at datetime NOT NULL,updated_at datetime NOT NULL,PRIMARY KEY (id),KEY booking_window (start_at,end_at),KEY user_status (user_id,status),KEY order_id (order_id),KEY email (email)) {$charset};");
        update_option('lb_scheduler_db_version', self::TABLE_VERSION);
        if (false === get_option(self::OPTION, false)) update_option(self::OPTION, self::defaults());
    }

    public static function boot(): void
    {
        $plugin = new self();
        add_shortcode('landing_bird_booking', [$plugin, 'booking_shortcode']);
        add_shortcode('landing_bird_my_sessions', [$plugin, 'sessions_shortcode']);
        add_action('init', [$plugin, 'register_block']);
        add_action('admin_menu', [$plugin, 'admin_menu']);
        add_action('admin_init', [$plugin, 'register_settings']);
        add_action('rest_api_init', [$plugin, 'register_routes']);
        add_action('admin_post_lb_scheduler_admin_booking', [$plugin, 'admin_booking']);
        add_action('admin_post_lb_scheduler_admin_status', [$plugin, 'admin_status']);
        add_action('lb_scheduler_anonymize', [$plugin, 'anonymize']);
        add_action('woocommerce_payment_complete', [$plugin, 'payment_complete']);
        if (!wp_next_scheduled('lb_scheduler_anonymize')) wp_schedule_event(time() + DAY_IN_SECONDS, 'daily', 'lb_scheduler_anonymize');
    }

    public static function deactivate(): void { wp_clear_scheduled_hook('lb_scheduler_anonymize'); }

    public static function options(): array { return array_merge(self::defaults(), (array) get_option(self::OPTION, [])); }

    public static function sanitize_options($input): array
    {
        $d = self::defaults(); $input = is_array($input) ? $input : [];
        $timezone_input = $input['timezone'] ?? '';
        $tz = is_scalar($timezone_input) ? (string) $timezone_input : '';
        try { new DateTimeZone($tz); } catch (Exception $e) { $tz = $d['timezone']; }
        $has_days = array_key_exists('days', $input);
        $days = array_values(array_intersect([1,2,3,4,5,6,7], array_map('intval', (array) ($input['days'] ?? []))));
        $time = static function ($v, $fallback) { return is_string($v) && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $v) ? $v : $fallback; };
        $price = is_numeric($input['price'] ?? null) ? max(0.01, round((float) $input['price'], 2)) : (float) $d['price'];
        $start = $time($input['start'] ?? '', $d['start']); $end = $time($input['end'] ?? '', $d['end']);
        if ($start >= $end) { $start = $d['start']; $end = $d['end']; }
        $break_start = $time($input['break_start'] ?? '', ''); $break_end = $time($input['break_end'] ?? '', '');
        if (!$break_start || !$break_end || $break_start >= $break_end || $break_start < $start || $break_end > $end) { $break_start = ''; $break_end = ''; }
        $overrides = [];
        $raw = isset($input['overrides_json']) ? json_decode(wp_unslash((string) $input['overrides_json']), true) : ($input['overrides'] ?? []);
        foreach ((array) $raw as $date => $open) {
            $parts = explode('-', (string) $date);
            if (count($parts) === 3 && checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0])) {
                if (is_bool($open)) $overrides[$date] = $open;
                elseif (is_numeric($open)) $overrides[$date] = (bool) $open;
                elseif (is_string($open) && in_array(strtolower($open), ['true', 'false'], true)) $overrides[$date] = 'true' === strtolower($open);
            }
        }
        $max_duration = min(480, max(30, absint($input['max_duration'] ?? $d['max_duration'])));
        $max_duration -= $max_duration % 30;
        return ['price' => number_format($price, 2, '.', ''), 'timezone' => $tz, 'days' => $has_days ? $days : $d['days'], 'start' => $start, 'end' => $end, 'break_start' => $break_start, 'break_end' => $break_end, 'min_notice' => min(168, max(0, absint($input['min_notice'] ?? $d['min_notice']))), 'max_days' => min(365, max(1, absint($input['max_days'] ?? $d['max_days']))), 'max_duration' => max(30, $max_duration), 'mode' => sanitize_textarea_field($input['mode'] ?? $d['mode']), 'overrides' => $overrides];
    }

    public function register_settings(): void { register_setting('lb_scheduler', self::OPTION, ['type'=>'array','sanitize_callback'=>[self::class,'sanitize_options'],'default'=>self::defaults()]); }
    public function admin_menu(): void { add_options_page('Landing Bird Scheduler','Scheduler','manage_options','landing-bird-scheduler',[$this,'settings_page']); add_menu_page('Bookings','Bookings','manage_options','lb-scheduler-bookings',[$this,'bookings_page'],'dashicons-calendar-alt'); }
    public function settings_page(): void
    {
        if (!current_user_can('manage_options')) return; $o = self::options();
        echo '<div class="wrap"><h1>Landing Bird Scheduler</h1>';
        if (!$this->woocommerce_ready()) echo '<div class="notice notice-error"><p>WooCommerce and an active payment gateway are required before paid bookings can be accepted.</p></div>';
        echo '<form method="post" action="options.php">'; settings_fields('lb_scheduler');
        $fields = ['price'=>'Session price','timezone'=>'IANA timezone','start'=>'Start time','end'=>'End time','break_start'=>'Optional break start','break_end'=>'Optional break end','min_notice'=>'Minimum notice (hours)','max_days'=>'Maximum days ahead','max_duration'=>'Maximum duration (minutes)','mode'=>'Mode / instructions'];
        foreach ($fields as $key=>$label) { printf('<p><label><strong>%s</strong><br><input class="regular-text" name="%s[%s]" value="%s"></label></p>',esc_html($label),esc_attr(self::OPTION),esc_attr($key),esc_attr($o[$key])); }
        printf('<p><label><strong>Date overrides</strong><br><textarea class="large-text" name="%s[overrides_json]" rows="3">%s</textarea><br>JSON map of YYYY-MM-DD to true (open) or false (closed).</label></p>',esc_attr(self::OPTION),esc_textarea(wp_json_encode($o['overrides'])));
        printf('<p><label><strong>Enabled days</strong><br><input name="%s[days][]" value="1" type="checkbox" %s> Mon <input name="%s[days][]" value="2" type="checkbox" %s> Tue <input name="%s[days][]" value="3" type="checkbox" %s> Wed <input name="%s[days][]" value="4" type="checkbox" %s> Thu <input name="%s[days][]" value="5" type="checkbox" %s> Fri <input name="%s[days][]" value="6" type="checkbox" %s> Sat <input name="%s[days][]" value="7" type="checkbox" %s> Sun</label></p>',self::OPTION,checked(in_array(1,$o['days'],true),true,false),self::OPTION,checked(in_array(2,$o['days'],true),true,false),self::OPTION,checked(in_array(3,$o['days'],true),true,false),self::OPTION,checked(in_array(4,$o['days'],true),true,false),self::OPTION,checked(in_array(5,$o['days'],true),true,false),self::OPTION,checked(in_array(6,$o['days'],true),true,false),self::OPTION,checked(in_array(7,$o['days'],true),true,false)); submit_button(); echo '</form></div>';
    }
    public function register_block(): void { register_block_type('landing-bird/scheduler', ['render_callback'=>[$this,'booking_shortcode']]); register_block_type('landing-bird/my-sessions', ['render_callback'=>[$this,'sessions_shortcode']]); }
    public function assets(): void { if (!wp_style_is('lb-scheduler', 'registered')) wp_register_style('lb-scheduler', plugins_url('assets/css/scheduler.css', LB_SCHEDULER_FILE), [], LB_SCHEDULER_VERSION); if (!wp_script_is('lb-scheduler', 'registered')) wp_register_script('lb-scheduler', plugins_url('assets/js/scheduler.js', LB_SCHEDULER_FILE), [], LB_SCHEDULER_VERSION, true); wp_enqueue_style('lb-scheduler'); wp_enqueue_script('lb-scheduler'); wp_localize_script('lb-scheduler','LBScheduler',['url'=>esc_url_raw(rest_url('lb-scheduler/v1/')),'nonce'=>wp_create_nonce('wp_rest'),'loggedIn'=>is_user_logged_in(),'loginUrl'=>wp_login_url(add_query_arg([],$_SERVER['REQUEST_URI']??''))]); }

    public function register_routes(): void
    {
        register_rest_route('lb-scheduler/v1','/slots', ['methods'=>'GET','callback'=>[$this,'slots'],'permission_callback'=>'__return_true']);
        register_rest_route('lb-scheduler/v1','/bookings', ['methods'=>'POST','callback'=>[$this,'create_booking'],'permission_callback'=>function(){return is_user_logged_in();}]);
        register_rest_route('lb-scheduler/v1','/bookings/(?P<id>\d+)/cancel', ['methods'=>'POST','callback'=>[$this,'cancel'],'permission_callback'=>[$this,'owns']]);
        register_rest_route('lb-scheduler/v1','/bookings/(?P<id>\d+)/reschedule', ['methods'=>'POST','callback'=>[$this,'reschedule'],'permission_callback'=>[$this,'owns']]);
        register_rest_route('lb-scheduler/v1','/bookings/(?P<id>\d+)/refund', ['methods'=>'POST','callback'=>[$this,'refund_request'],'permission_callback'=>[$this,'owns']]);
        register_rest_route('lb-scheduler/v1','/sessions', ['methods'=>'GET','callback'=>[$this,'sessions'],'permission_callback'=>function(){return is_user_logged_in();}]);
        register_rest_route('lb-scheduler/v1','/admin/bookings', ['methods'=>'POST','callback'=>[$this,'admin_create_booking'],'permission_callback'=>function(){return current_user_can('manage_options');}]);
        register_rest_route('lb-scheduler/v1','/admin/bookings/(?P<id>\d+)', ['methods'=>'PATCH','callback'=>[$this,'admin_update_booking'],'permission_callback'=>function(){return current_user_can('manage_options');}]);
    }

    private function woocommerce_ready(): bool { if ((float) self::options()['price'] <= 0 || !class_exists('WooCommerce') || !function_exists('wc_create_order') || !class_exists('WC_Payment_Gateways')) return false; foreach (WC_Payment_Gateways::instance()->get_payment_gateways() as $gateway) if (isset($gateway->enabled) && 'yes' === $gateway->enabled) return true; return false; }
    private function table(): string { global $wpdb; return $wpdb->prefix . 'lb_scheduler_bookings'; }
    private function lock(): bool { global $wpdb; return (bool) $wpdb->get_var("SELECT GET_LOCK('lb_scheduler_booking',5)"); }
    private function unlock(): void { global $wpdb; $wpdb->query("SELECT RELEASE_LOCK('lb_scheduler_booking')"); }
    private function now(): DateTimeImmutable { return new DateTimeImmutable('now', new DateTimeZone(self::options()['timezone'])); }
    private function parsed(string $value): ?DateTimeImmutable
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T]\d{2}:\d{2}(?::\d{2})?(?:[+-]\d{2}:?\d{2})?)?$/', $value, $matches) || !checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])) return null;
        try {
            $timezone = new DateTimeZone(self::options()['timezone']);
            return (new DateTimeImmutable($value, $timezone))->setTimezone($timezone);
        } catch (Exception $e) {
            return null;
        }
    }
    private function valid_window(DateTimeImmutable $start, int $duration): bool { $o=self::options(); $now=$this->now(); $date=$start->format('Y-m-d'); $open=array_key_exists($date,$o['overrides']) ? $o['overrides'][$date] : in_array((int)$start->format('N'),$o['days'],true); return $duration >= 30 && $duration % 30 === 0 && $duration <= (int)$o['max_duration'] && (int)$start->format('i') % 30 === 0 && '00' === $start->format('s') && $start >= $now->modify('+' . (int)$o['min_notice'] . ' hours') && $start <= $now->modify('+' . (int)$o['max_days'] . ' days') && $open && $start->format('H:i') >= $o['start'] && $start->modify('+' . $duration . ' minutes')->format('H:i') <= $o['end'] && !($o['break_start'] && $o['break_end'] && $start->format('H:i') < $o['break_end'] && $start->modify('+' . $duration . ' minutes')->format('H:i') > $o['break_start']); }
    private function conflicts(string $start,string $end,int $ignore=0): bool { global $wpdb; return (bool)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->table()} WHERE id != %d AND (status IN ('confirmed_paid','confirmed_external','rescheduled') OR (status='pending_payment' AND (hold_expires_at IS NULL OR hold_expires_at >= UTC_TIMESTAMP()))) AND start_at < %s AND end_at > %s LIMIT 1",$ignore,$end,$start)); }
    public function slots(WP_REST_Request $request): WP_REST_Response { $date=sanitize_text_field((string)$request->get_param('date')); $base=$this->parsed($date.' 00:00:00'); $o=self::options(); $out=[]; if (!$base || (array_key_exists($date,$o['overrides']) ? !$o['overrides'][$date] : !in_array((int)$base->format('N'),$o['days'],true))) return new WP_REST_Response(['slots'=>[]]); $first=explode(':',$o['start']); $last=explode(':',$o['end']); $from=((int)$first[0])*60+(int)$first[1]; $to=((int)$last[0])*60+(int)$last[1]; for($m=$from;$m<$to;$m+=30){$s=$base->setTime(0,0)->modify('+' . $m . ' minutes'); for($d=30;$d<=(int)$o['max_duration'];$d+=30){if($this->valid_window($s,$d)&&!$this->conflicts($s->format('Y-m-d H:i:s'),$s->modify('+' . $d . ' minutes')->format('Y-m-d H:i:s'))) $out[]=['start'=>$s->format(DateTimeInterface::ATOM),'duration'=>$d];}} return new WP_REST_Response(['slots'=>$out]); }
    public function create_booking(WP_REST_Request $request): WP_REST_Response
    {
        if (!$this->woocommerce_ready()) return new WP_REST_Response(['message' => 'WooCommerce with an active gateway is required.'], 503);
        $payload = (array) $request->get_json_params();
        $start = $this->parsed(sanitize_text_field($payload['start'] ?? ''));
        $duration = absint($payload['duration'] ?? 0);
        if (!$start || !$this->valid_window($start, $duration)) return new WP_REST_Response(['message' => 'This slot is not available.'], 400);
        $end = $start->modify('+' . $duration . ' minutes');
        if (!$this->lock()) return new WP_REST_Response(['message' => 'Booking is temporarily busy; try again.'], 503);
        try {
            global $wpdb;
            if ($this->conflicts($start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'))) return new WP_REST_Response(['message' => 'This slot was just taken.'], 409);
            $user = wp_get_current_user();
            $now = current_time('mysql', true);
            $hold_expires = gmdate('Y-m-d H:i:s', time() + 900);
            $inserted = $wpdb->insert($this->table(), ['user_id' => $user->ID, 'email' => sanitize_email($user->user_email), 'start_at' => $start->format('Y-m-d H:i:s'), 'end_at' => $end->format('Y-m-d H:i:s'), 'duration' => $duration, 'status' => 'pending_payment', 'timezone' => self::options()['timezone'], 'mode' => self::options()['mode'], 'hold_expires_at' => $hold_expires, 'created_at' => $now, 'updated_at' => $now], ['%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s']);
            if (!$inserted) return new WP_REST_Response(['message' => 'Unable to reserve this slot.'], 500);
            $id = (int) $wpdb->insert_id;
            $order = wc_create_order(['customer_id' => $user->ID]);
            if (is_wp_error($order)) {
                $wpdb->update($this->table(), ['status' => 'expired', 'updated_at' => $now], ['id' => $id], ['%s', '%s'], ['%d']);
                return new WP_REST_Response(['message' => 'Unable to start payment.'], 500);
            }
            $fee = new WC_Order_Item_Fee();
            $fee->set_name('Session ' . $duration . ' minutes');
            $fee->set_total((float) self::options()['price']);
            $order->add_item($fee);
            $order->set_billing_email($user->user_email);
            $order->update_meta_data('_lb_scheduler_booking_id', $id);
            $order->calculate_totals();
            $order->save();
            if (!$wpdb->update($this->table(), ['order_id' => $order->get_id()], ['id' => $id], ['%d'], ['%d'])) {
                $order->add_order_note('Scheduler could not link the booking record; review before fulfilling.');
                return new WP_REST_Response(['message' => 'Unable to link payment.'], 500);
            }
            do_action('lb_scheduler_booking_created', $id);
            return new WP_REST_Response(['id' => $id, 'status' => 'pending_payment', 'order_id' => $order->get_id(), 'payment_url' => $order->get_checkout_payment_url(), 'hold_expires_at' => gmdate(DateTimeInterface::ATOM, time() + 900)], 201);
        } finally {
            $this->unlock();
        }
    }

    public function payment_complete($order_id): void
    {
        $order = wc_get_order($order_id);
        if (!$order) return;
        $id = (int) $order->get_meta('_lb_scheduler_booking_id');
        if (!$id || !$this->lock()) return;
        try {
            global $wpdb;
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table()} WHERE id=%d", $id));
            if (!$row || in_array($row->status, ['confirmed_paid', 'refund_pending', 'refunded'], true) || !in_array($row->status, ['pending_payment', 'cancelled', 'expired'], true)) return;
            $expired = 'pending_payment' !== $row->status || empty($row->hold_expires_at) || strtotime($row->hold_expires_at . ' UTC') < time() || $this->conflicts($row->start_at, $row->end_at, $id);
            $status = $expired ? 'refund_pending' : 'confirmed_paid';
            $wpdb->update($this->table(), ['status' => $status, 'payment_method' => sanitize_text_field($order->get_payment_method_title()), 'updated_at' => current_time('mysql', true)], ['id' => $id], ['%s', '%s', '%s'], ['%d']);
            if ($expired) $order->add_order_note('Scheduler payment arrived after the booking hold expired, was cancelled, or the slot was occupied; review refund.');
            do_action('lb_scheduler_operational_notice', $id);
        } finally {
            $this->unlock();
        }
    }
    public function owns(WP_REST_Request $request): bool { global $wpdb; return is_user_logged_in() && (int)$wpdb->get_var($wpdb->prepare("SELECT user_id FROM {$this->table()} WHERE id=%d",absint($request['id'])))===get_current_user_id(); }
    public function sessions(): WP_REST_Response { global $wpdb; return new WP_REST_Response(['sessions'=>$wpdb->get_results($wpdb->prepare("SELECT id,start_at,end_at,duration,status,mode FROM {$this->table()} WHERE user_id=%d ORDER BY start_at DESC",get_current_user_id()),ARRAY_A)]); }
    public function cancel(WP_REST_Request $request): WP_REST_Response
    {
        $id = absint($request['id']);
        if (!$this->lock()) return new WP_REST_Response(['message' => 'Booking is temporarily busy; try again.'], 503);
        try {
            global $wpdb;
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table()} WHERE id=%d", $id));
            $start = $row ? $this->parsed($row->start_at) : null;
            if (!$row || !$start || $start < $this->now()->modify('+24 hours') || in_array($row->status, ['cancelled', 'refunded', 'refund_requested', 'refund_pending'], true)) return new WP_REST_Response(['message' => 'Cancellation closes 24 hours before the session.'], 400);
            $status = in_array($row->status, ['confirmed_paid', 'confirmed_external', 'rescheduled'], true) ? 'refund_requested' : 'cancelled';
            $wpdb->update($this->table(), ['status' => $status, 'updated_at' => current_time('mysql', true)], ['id' => $id], ['%s', '%s'], ['%d']);
            if ('refund_requested' === $status) do_action('lb_scheduler_operational_notice', $id);
            return new WP_REST_Response(['status' => $status]);
        } finally {
            $this->unlock();
        }
    }
    public function reschedule(WP_REST_Request $request): WP_REST_Response
    {
        $id = absint($request['id']);
        $body = (array) $request->get_json_params();
        $requested_start = $body['start'] ?? $request->get_param('start');
        $new_start = $this->parsed(sanitize_text_field($requested_start));
        if (!$new_start || !$this->lock()) return new WP_REST_Response(['message' => 'This slot is not available.'], 409);
        try {
            global $wpdb;
            $old = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table()} WHERE id=%d", $id));
            $old_start = $old ? $this->parsed($old->start_at) : null;
            if (!$old || !$old_start || !in_array($old->status, ['confirmed_paid', 'confirmed_external'], true) || $old_start < $this->now()->modify('+24 hours')) return new WP_REST_Response(['message' => 'This booking cannot be rescheduled.'], 400);
            $new_end = $new_start->modify('+' . (int) $old->duration . ' minutes');
            if (!$this->valid_window($new_start, (int) $old->duration) || $this->conflicts($new_start->format('Y-m-d H:i:s'), $new_end->format('Y-m-d H:i:s'), $id)) return new WP_REST_Response(['message' => 'This slot is not available.'], 409);
            $wpdb->update($this->table(), ['start_at' => $new_start->format('Y-m-d H:i:s'), 'end_at' => $new_end->format('Y-m-d H:i:s'), 'status' => 'rescheduled', 'updated_at' => current_time('mysql', true)], ['id' => $id], ['%s', '%s', '%s', '%s'], ['%d']);
            return new WP_REST_Response(['status' => 'rescheduled']);
        } finally {
            $this->unlock();
        }
    }
    public function refund_request(WP_REST_Request $request): WP_REST_Response
    {
        $id = absint($request['id']);
        if (!$this->lock()) return new WP_REST_Response(['message' => 'Booking is temporarily busy; try again.'], 503);
        try {
            global $wpdb;
            $row = $wpdb->get_row($wpdb->prepare("SELECT status,start_at FROM {$this->table()} WHERE id=%d", $id));
            $start = $row ? $this->parsed($row->start_at) : null;
            if (!$row || !$start || $start < $this->now()->modify('+24 hours') || !in_array($row->status, ['confirmed_paid', 'confirmed_external', 'rescheduled'], true)) return new WP_REST_Response(['message' => 'Refund request is unavailable.'], 400);
            $wpdb->update($this->table(), ['status' => 'refund_requested', 'updated_at' => current_time('mysql', true)], ['id' => $id], ['%s', '%s'], ['%d']);
            do_action('lb_scheduler_operational_notice', $id);
            return new WP_REST_Response(['status' => 'refund_requested']);
        } finally {
            $this->unlock();
        }
    }
    public function admin_create_booking(WP_REST_Request $request): WP_REST_Response
    {
        $p = (array) $request->get_json_params();
        if (!$p) $p = (array) $request->get_params();
        $s = $this->parsed(sanitize_text_field($p['start'] ?? ''));
        $d = absint($p['duration'] ?? 0);
        $override = !empty($p['override']);
        $max_duration = (int) self::options()['max_duration'];
        if (!$s || '00' !== $s->format('s') || $d < 30 || $d % 30 !== 0 || $d > $max_duration || (!$override && !$this->valid_window($s, $d))) return new WP_REST_Response(['message' => 'Invalid or conflicting booking.'], 400);
        $email = sanitize_email($p['email'] ?? '');
        if (!$email || !is_email($email)) return new WP_REST_Response(['message' => 'A valid client email is required.'], 400);
        $user = get_user_by('email', $email);
        if (!$user) {
            $base = sanitize_user(substr((string) strstr($email, '@', true), 0, 40), true) ?: 'client';
            $username = $base;
            for ($i = 1; username_exists($username); $i++) $username = $base . $i;
            $uid = wp_create_user($username, wp_generate_password(24), $email);
            if (!is_wp_error($uid)) wp_new_user_notification($uid, null, 'user');
        } else {
            $uid = $user->ID;
        }
        if (is_wp_error($uid) || !$uid) return new WP_REST_Response(['message' => 'Unable to create client account.'], 500);
        $end = $s->modify('+' . $d . ' minutes');
        if (!$this->lock()) return new WP_REST_Response(['message' => 'Booking is temporarily busy; try again.'], 503);
        try {
            global $wpdb;
            if (!$override && $this->conflicts($s->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'))) return new WP_REST_Response(['message' => 'Invalid or conflicting booking.'], 409);
            $now = current_time('mysql', true);
            $note = sanitize_textarea_field($p['admin_note'] ?? '');
            if ($override) $note = trim($note . ' Admin override by user ' . get_current_user_id());
            $inserted = $wpdb->insert($this->table(), ['user_id' => $uid, 'email' => $email, 'phone' => sanitize_text_field($p['phone'] ?? ''), 'start_at' => $s->format('Y-m-d H:i:s'), 'end_at' => $end->format('Y-m-d H:i:s'), 'duration' => $d, 'status' => 'confirmed_external', 'timezone' => self::options()['timezone'], 'mode' => self::options()['mode'], 'payment_method' => sanitize_text_field($p['payment_method'] ?? ''), 'payment_reference' => sanitize_text_field($p['payment_reference'] ?? ''), 'admin_note' => $note, 'created_at' => $now, 'updated_at' => $now], ['%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']);
            if (!$inserted) return new WP_REST_Response(['message' => 'Unable to save the booking.'], 500);
            $id = (int) $wpdb->insert_id;
            do_action('lb_scheduler_operational_notice', $id);
            return new WP_REST_Response(['id' => $id, 'status' => 'confirmed_external'], 201);
        } finally {
            $this->unlock();
        }
    }
    private function admin_transitions(): array
    {
        return ['pending_payment' => ['expired', 'cancelled', 'confirmed_external'], 'confirmed_paid' => ['refund_requested'], 'confirmed_external' => ['cancelled', 'refund_requested'], 'refund_requested' => ['refund_pending', 'refunded'], 'refund_pending' => ['refunded'], 'rescheduled' => ['refund_requested', 'cancelled']];
    }
    public function admin_update_booking(WP_REST_Request $request): WP_REST_Response
    {
        $id = absint($request['id']);
        $p = (array) $request->get_json_params();
        if (!$p) $p = (array) $request->get_params();
        $status = sanitize_key($p['status'] ?? '');
        if (!in_array($status, self::STATUSES, true)) return new WP_REST_Response(['message' => 'Invalid status transition.'], 400);
        if (!$this->lock()) return new WP_REST_Response(['message' => 'Booking is temporarily busy; try again.'], 503);
        try {
            global $wpdb;
            $row = $wpdb->get_row($wpdb->prepare("SELECT status FROM {$this->table()} WHERE id=%d", $id));
            $allowed = $this->admin_transitions();
            if (!$row || !isset($allowed[$row->status]) || !in_array($status, $allowed[$row->status], true)) return new WP_REST_Response(['message' => 'Invalid status transition.'], 400);
            $values = ['status' => $status, 'updated_at' => current_time('mysql', true)];
            $formats = ['%s', '%s'];
            if (array_key_exists('admin_note', $p)) {
                $values['admin_note'] = sanitize_textarea_field($p['admin_note']);
                $formats[] = '%s';
            }
            $updated = $wpdb->update($this->table(), $values, ['id' => $id], $formats, ['%d']);
            if (false === $updated) return new WP_REST_Response(['message' => 'Unable to update the booking.'], 500);
            if (in_array($status, ['refund_requested', 'refund_pending', 'refunded'], true)) do_action('lb_scheduler_operational_notice', $id);
            return new WP_REST_Response(['id' => $id, 'status' => $status]);
        } finally {
            $this->unlock();
        }
    }
    public function booking_shortcode(): string { $this->assets(); return '<section class="lb-scheduler" data-lb-scheduler><h2>Reserva tu sesión</h2><p class="lb-scheduler__message">Elige una fecha y horario. Necesitarás iniciar sesión para reservar.</p><label>Fecha <input type="date" data-lb-date></label><div data-lb-slots></div></section>'; }
    public function sessions_shortcode(): string { if(!is_user_logged_in()) return '<p>Inicia sesión para ver tus sesiones.</p>'; $this->assets(); return '<section class="lb-scheduler-sessions" data-lb-sessions><h2>Mis sesiones</h2><div data-lb-session-list>Cargando…</div></section>'; }
    public function bookings_page(): void
    {
        if (!current_user_can('manage_options')) return;
        global $wpdb;
        $rows = $wpdb->get_results("SELECT * FROM {$this->table()} ORDER BY start_at DESC LIMIT 100");
        $transitions = $this->admin_transitions();
        echo '<div class="wrap"><h1>Bookings</h1>';
        if (isset($_GET['created'])) echo '<div class="notice notice-success"><p>Booking created.</p></div>';
        if (isset($_GET['updated'])) echo '<div class="notice notice-success"><p>Booking updated.</p></div>';
        echo '<p>External bookings use conflict checks by default. Enable the override only when the business has explicitly approved a closed or occupied time.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="lb_scheduler_admin_booking">';
        wp_nonce_field('lb_scheduler_admin_booking');
        echo '<input required type="email" name="email" placeholder="Client email"> <input type="text" name="phone" placeholder="Phone (optional)"> <input required type="datetime-local" name="start"> <input required type="number" min="30" step="30" name="duration" value="30"> <input name="payment_method" placeholder="Payment method"> <input name="payment_reference" placeholder="Reference"> <input name="admin_note" placeholder="Admin note"> <label><input type="checkbox" name="override" value="1"> Override availability/conflict</label> <button class="button button-primary">Create external booking</button></form><br>';
        echo '<table class="widefat"><thead><tr><th>ID</th><th>Email</th><th>Start</th><th>Status</th><th>Order</th><th>Actions</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            echo '<tr><td>' . (int) $r->id . '</td><td>' . esc_html($r->email) . '</td><td>' . esc_html($r->start_at) . '</td><td>' . esc_html($r->status) . '</td><td>' . (int) $r->order_id . '</td><td>';
            foreach ($transitions[$r->status] ?? [] as $next) {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin:0 .25rem .25rem 0"><input type="hidden" name="action" value="lb_scheduler_admin_status"><input type="hidden" name="id" value="' . (int) $r->id . '"><input type="hidden" name="status" value="' . esc_attr($next) . '">';
                echo wp_nonce_field('lb_scheduler_admin_status', '_wpnonce', true, false);
                echo '<button class="button" type="submit">' . esc_html(ucwords(str_replace('_', ' ', $next))) . '</button></form>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    public function admin_booking(): void
    {
        if (!current_user_can('manage_options') || !check_admin_referer('lb_scheduler_admin_booking')) wp_die('Forbidden', '', ['response' => 403]);
        $r = new WP_REST_Request('POST', '/lb-scheduler/v1/admin/bookings');
        $r->set_header('content-type', 'application/json');
        $r->set_body(wp_json_encode(['email' => sanitize_email($_POST['email'] ?? ''), 'phone' => sanitize_text_field($_POST['phone'] ?? ''), 'start' => sanitize_text_field($_POST['start'] ?? ''), 'duration' => absint($_POST['duration'] ?? 0), 'payment_method' => sanitize_text_field($_POST['payment_method'] ?? ''), 'payment_reference' => sanitize_text_field($_POST['payment_reference'] ?? ''), 'admin_note' => sanitize_textarea_field($_POST['admin_note'] ?? ''), 'override' => !empty($_POST['override'])]));
        $response = $this->admin_create_booking($r);
        if ($response->get_status() >= 400) {
            $data = (array) $response->get_data();
            wp_die(esc_html($data['message'] ?? 'Unable to create booking.'), '', ['response' => $response->get_status()]);
        }
        wp_safe_redirect(add_query_arg('created', '1', admin_url('admin.php?page=lb-scheduler-bookings')));
        exit;
    }
    public function admin_status(): void
    {
        if (!current_user_can('manage_options') || !check_admin_referer('lb_scheduler_admin_status')) wp_die('Forbidden', '', ['response' => 403]);
        $r = new WP_REST_Request('PATCH', '/lb-scheduler/v1/admin/bookings/' . absint($_POST['id'] ?? 0));
        $r->set_header('content-type', 'application/json');
        $r->set_body(wp_json_encode(['status' => sanitize_key($_POST['status'] ?? ''), 'admin_note' => sanitize_textarea_field($_POST['admin_note'] ?? '')]));
        $response = $this->admin_update_booking($r);
        if ($response->get_status() >= 400) {
            $data = (array) $response->get_data();
            wp_die(esc_html($data['message'] ?? 'Unable to update booking.'), '', ['response' => $response->get_status()]);
        }
        wp_safe_redirect(add_query_arg('updated', '1', admin_url('admin.php?page=lb-scheduler-bookings')));
        exit;
    }
    public function anonymize(): void { global $wpdb; $cutoff=gmdate('Y-m-d H:i:s',time()-YEAR_IN_SECONDS); $wpdb->query($wpdb->prepare("UPDATE {$this->table()} SET email='',phone='',mode='',admin_note='' WHERE created_at < %s AND (email <> '' OR phone <> '')",$cutoff)); }
}
