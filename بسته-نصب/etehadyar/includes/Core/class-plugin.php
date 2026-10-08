<?php
defined('ABSPATH') || exit;

class EAIW_Plugin {
    private static $instance = null;
    public static function instance(){
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }
    private function __construct(){
        $this->init_hooks();
    }
    private function init_hooks(){
        add_action('init', [$this, 'init']);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
        add_action('wp_enqueue_scripts', [$this, 'frontend_assets']);
        add_action('eaiw_agents_cron', [$this, 'run_agents_cron']);
        add_action('eaiw_jobs_cron', [$this, 'run_jobs_cron']);
        add_action('eaiw_support_audio_cleanup', [$this, 'cleanup_support_audio']);
        add_action('eaiw_guardian_cron', [$this, 'run_guardian_cron']);
        // AJAX
        add_action('wp_ajax_eaiw_supernatural_toggle', [$this, 'ajax_toggle_supernatural']);
        add_action('wp_ajax_eaiw_theme_save', [$this, 'ajax_theme_save']);
        add_action('wp_ajax_eaiw_brain_index', [$this, 'ajax_brain_index']);
        add_action('wp_ajax_eaiw_brain_search', [$this, 'ajax_brain_search']);
        add_action('wp_ajax_eaiw_agent_toggle', [$this, 'ajax_agent_toggle']);
        add_action('wp_ajax_eaiw_agent_run', [$this, 'ajax_agent_run']);
        add_action('wp_ajax_eaiw_vision_generate', [$this, 'ajax_vision_generate']);
        add_action('wp_ajax_eaiw_flux_generate', [$this, 'ajax_flux_generate']);
        add_action('wp_ajax_eaiw_architect_generate', [$this, 'ajax_architect_generate']);
        add_action('wp_ajax_eaiw_nexus_test', [$this, 'ajax_nexus_test']);
        add_action('wp_ajax_eaiw_portal_seen', [$this, 'ajax_portal_seen']);
        add_action('wp_ajax_eaiw_soul_save_name', [$this, 'ajax_soul_save_name']);
        // 6.1 Factory + Social + TTS
        add_action('wp_ajax_eaiw_factory_generate', [$this, 'ajax_factory_generate']);
        add_action('wp_ajax_eaiw_factory_publish_telegram', [$this, 'ajax_factory_publish_telegram']);
        add_action('wp_ajax_eaiw_factory_publish_instagram', [$this, 'ajax_factory_publish_instagram']);
        add_action('wp_ajax_eaiw_tts_generate', [$this, 'ajax_tts_generate']);
        add_action('wp_ajax_eaiw_social_test_telegram', [$this, 'ajax_social_test_telegram']);
        add_action('wp_ajax_eaiw_social_save', [$this, 'ajax_social_save']);
        // 6.2 Video Pro + Woo Autopilot
        add_action('wp_ajax_eaiw_video_build', [$this, 'ajax_video_build']);
        add_action('wp_ajax_eaiw_woo_enhance_one', [$this, 'ajax_woo_enhance_one']);
        add_action('wp_ajax_eaiw_woo_bulk_enhance', [$this, 'ajax_woo_bulk_enhance']);
        add_action('wp_ajax_eaiw_woo_create_product', [$this, 'ajax_woo_create_product']);
        add_action('wp_ajax_eaiw_woo_find_weak', [$this, 'ajax_woo_find_weak']);
        // 6.3 Automation
        add_action('wp_ajax_eaiw_nexus_toggle', [$this, 'ajax_nexus_toggle']);
        add_action('wp_ajax_eaiw_nexus_run', [$this, 'ajax_nexus_run']);
        add_action('wp_ajax_eaiw_nexus_create', [$this, 'ajax_nexus_create']);
        add_action('wp_ajax_eaiw_nexus_delete', [$this, 'ajax_nexus_delete']);
        add_action('eaiw_automation_cron', [$this, 'run_automation_cron']);
        add_action('transition_post_status', [$this, 'on_post_status'], 10, 3);
        add_action('woocommerce_new_order', [$this, 'on_woocommerce_order'], 10, 1);
        add_action('woocommerce_checkout_order_processed', [$this, 'on_woocommerce_order'], 10, 1);
        add_action('eaiw_tg_order_event', [$this, 'handle_tg_order'], 10, 1);
        // 6.4 Reports
        add_action('wp_ajax_eaiw_report_pdf', [$this, 'ajax_report_pdf']);
        add_action('wp_ajax_eaiw_report_excel', [$this, 'ajax_report_excel']);
        // 6.6 AI test
        add_action('wp_ajax_eaiw_ai_test', [$this, 'ajax_ai_test']);
        add_action('wp_ajax_eaiw_health_check', [$this, 'ajax_health_check']);
        add_action('wp_ajax_eaiw_health_repair_cron', [$this, 'ajax_health_repair_cron']);
        add_action('admin_post_eaiw_health_export', [$this, 'export_health_report']);
        add_action('wp_ajax_eaiw_activity_clear', [$this, 'ajax_activity_clear']);
        add_action('wp_ajax_eaiw_job_retry', [$this, 'ajax_job_retry']);
        add_action('wp_ajax_eaiw_job_cancel', [$this, 'ajax_job_cancel']);
        add_action('wp_ajax_eaiw_job_status', [$this, 'ajax_job_status']);
        add_action('wp_ajax_eaiw_support_update', [$this, 'ajax_support_update']);
        add_action('wp_ajax_eaiw_feedback_faq', [$this, 'ajax_feedback_faq']);
        add_action('rest_api_init', [$this, 'register_rest']);
    }

    public function init(){
        if (is_admin()) {
            new EAIW_Admin_Menu();
        }
        add_filter('cron_schedules', function($s){ if(!isset($s['fifteen_minutes'])) $s['fifteen_minutes']=['interval'=>900,'display'=>'هر ۱۵ دقیقه']; if(!isset($s['minutely'])) $s['minutely']=['interval'=>60,'display'=>'هر دقیقه']; return $s; });
        // سازگاری با سایت‌هایی که قبل از اصلاح Cron فعال شده‌اند.
        if (!wp_next_scheduled('eaiw_agents_cron')) wp_schedule_event(time()+900, 'fifteen_minutes', 'eaiw_agents_cron');
        if (!wp_next_scheduled('eaiw_automation_cron')) wp_schedule_event(time()+900, 'fifteen_minutes', 'eaiw_automation_cron');
        if (!wp_next_scheduled('eaiw_guardian_cron')) wp_schedule_event(time()+3600, 'hourly', 'eaiw_guardian_cron');
        if (!wp_next_scheduled('eaiw_jobs_cron')) wp_schedule_event(time()+60, 'minutely', 'eaiw_jobs_cron');
        if (!wp_next_scheduled('eaiw_support_audio_cleanup')) wp_schedule_event(time()+DAY_IN_SECONDS, 'daily', 'eaiw_support_audio_cleanup');
        // ensure defaults + upgrade 6.0 -> 6.0.1
        if (!get_option('eaiw_chatsoul_name')) update_option('eaiw_chatsoul_name','پشتیبان هوشمند');
        if (!get_option('eaiw_theme')) update_option('eaiw_theme','dark');
        if (get_option('eaiw_chatsoul_use_site_context')===false) update_option('eaiw_chatsoul_use_site_context',0);
        if (!get_option('eaiw_chatsoul_greeting')) update_option('eaiw_chatsoul_greeting','سلام! من پشتیبان هوشمند هستم — هر سوالی داری بپرس، کل سایت رو بلدم ✨');
        if (!get_option('eaiw_chatsoul_color')) update_option('eaiw_chatsoul_color','#6d28ff');
        $dbv = (int)get_option('eaiw_db_version', 600);
        if ($dbv < 673) {
            global $wpdb;
            $t=$wpdb->prefix.'eaiw_agents';
            if($wpdb->get_var("SHOW TABLES LIKE '$t'")==$t){
                $wpdb->update($t, ['title'=>'بهبود سئو'], ['agent_key'=>'seo_watcher']);
                $wpdb->update($t, ['title'=>'به‌روزرسان محتوا'], ['agent_key'=>'gardener']);
                $wpdb->update($t, ['title'=>'لینک‌ساز هوشمند'], ['agent_key'=>'link_weaver']);
                $wpdb->update($t, ['title'=>'ایده‌یاب'], ['agent_key'=>'trend_hunter']);
            }
            add_option('eaiw_telegram_token','');
            add_option('eaiw_telegram_chat','');
            add_option('eaiw_instagram_token','');
            add_option('eaiw_instagram_user','');
            add_option('eaiw_flux_key','');
            $charset=$wpdb->get_charset_collate();
            $table_runs=$wpdb->prefix.'eaiw_automation_runs';
            $sql6="CREATE TABLE $table_runs (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            automation_id bigint(20) unsigned NOT NULL,
            status enum('success','failed') NOT NULL,
            result longtext NULL,
            error_text text NULL,
            elapsed float DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY automation_id (automation_id),
            KEY created_at (created_at)
        ) $charset;";
            require_once ABSPATH.'wp-admin/includes/upgrade.php';
            dbDelta($sql6);
            if(!wp_next_scheduled('eaiw_automation_cron')){
                wp_schedule_event(time()+900,'fifteen_minutes','eaiw_automation_cron');
            }
            delete_transient('eaiw_weak_cache_6');
            delete_transient('eaiw_weak_cache_50');
            update_option('eaiw_db_version', 673);
        }
        // 6.8.9 activity log migration
        if ($dbv < 689) {
            global $wpdb;
            $table_activity=$wpdb->prefix.'eaiw_activity_log';
            require_once ABSPATH.'wp-admin/includes/upgrade.php';
            // The `level` column must be listed here too. Without it dbDelta
            // compares this definition against the table the activator built,
            // misaligns the column list and emits a corrupt statement —
            // observed on real MySQL as:
            //   ALTER TABLE wp_eaiw_activity_log ALTER COLUMN `id` SET DEFAULT 'info'
            // which tries to give the AUTO_INCREMENT primary key the default
            // belonging to `level`. MySQL rejects it on every page load,
            // because this runs on `init`.
            $sql7="CREATE TABLE $table_activity (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            trace_id varchar(36) NOT NULL,
            level varchar(12) NOT NULL DEFAULT 'info',
            event_name varchar(160) NOT NULL,
            context longtext NULL,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY event_name (event_name),
            KEY created_at (created_at),
            KEY user_id (user_id)
        ) {$wpdb->get_charset_collate()};";
            dbDelta($sql7);
            update_option('eaiw_db_version',689);
        }
        if ($dbv < 6119) { global $wpdb; require_once ABSPATH.'wp-admin/includes/upgrade.php';$t=$wpdb->prefix.'eaiw_support_requests';$h=$wpdb->prefix.'eaiw_support_history';$sql="CREATE TABLE $t (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            ticket_key varchar(36) NOT NULL,
            access_token_hash varchar(64) NOT NULL,
            session_id varchar(48) NOT NULL,
            name varchar(120) NULL,
            contact varchar(180) NULL,
            message text NOT NULL,
            priority tinyint unsigned NOT NULL DEFAULT 2,
            audio_url varchar(500) NOT NULL DEFAULT '',
            transcript longtext NULL,
            response longtext NULL,
            assigned_user bigint(20) unsigned NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'new',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY ticket_key (ticket_key),
            KEY status (status),
            KEY priority (priority),
            KEY created_at (created_at)
        ) {$wpdb->get_charset_collate()};";dbDelta($sql);$sql2="CREATE TABLE $h (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            request_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            response longtext NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY request_id (request_id)
        ) {$wpdb->get_charset_collate()};";dbDelta($sql2);update_option('eaiw_db_version',6119); }
        if ($dbv < 6113) { global $wpdb; require_once ABSPATH.'wp-admin/includes/upgrade.php'; $t=$wpdb->prefix.'eaiw_chat_feedback'; $sql="CREATE TABLE $t (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            session_id varchar(48) NOT NULL,
            rating tinyint NOT NULL,
            message varchar(240) NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY session_id (session_id),
            KEY rating (rating),
            KEY created_at (created_at)
        ) {$wpdb->get_charset_collate()};";dbDelta($sql);update_option('eaiw_db_version',6113); }
        if ($dbv < 6109) { global $wpdb; require_once ABSPATH.'wp-admin/includes/upgrade.php'; $t=$wpdb->prefix.'eaiw_support_requests'; $sql="CREATE TABLE $t (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            ticket_key varchar(36) NOT NULL,
            access_token_hash varchar(64) NOT NULL,
            session_id varchar(48) NOT NULL,
            name varchar(120) NULL,
            contact varchar(180) NULL,
            message text NOT NULL,
            response longtext NULL,
            assigned_user bigint(20) unsigned NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'new',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY ticket_key (ticket_key),
            KEY status (status),
            KEY created_at (created_at)
        ) {$wpdb->get_charset_collate()};"; dbDelta($sql); update_option('eaiw_db_version',6109); }
        if ($dbv < 6106) { global $wpdb; require_once ABSPATH.'wp-admin/includes/upgrade.php';$t=$wpdb->prefix.'eaiw_support_requests';$sql="CREATE TABLE $t (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            ticket_key varchar(36) NOT NULL,
            session_id varchar(48) NOT NULL,
            name varchar(120) NULL,
            contact varchar(180) NULL,
            message text NOT NULL,
            response longtext NULL,
            assigned_user bigint(20) unsigned NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'new',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY ticket_key (ticket_key),
            KEY status (status),
            KEY created_at (created_at)
        ) {$wpdb->get_charset_collate()};";dbDelta($sql);update_option('eaiw_db_version',6106); }
        if ($dbv < 6102) { global $wpdb; require_once ABSPATH.'wp-admin/includes/upgrade.php'; $t=$wpdb->prefix.'eaiw_usage'; $sql="CREATE TABLE $t (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            provider varchar(20) NOT NULL,
            model varchar(80) NOT NULL,
            prompt_tokens int unsigned NOT NULL DEFAULT 0,
            completion_tokens int unsigned NOT NULL DEFAULT 0,
            total_tokens int unsigned NOT NULL DEFAULT 0,
            estimated_cost decimal(12,8) NOT NULL DEFAULT 0,
            job_id bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY provider (provider),
            KEY created_at (created_at)
        ) {$wpdb->get_charset_collate()};"; dbDelta($sql); update_option('eaiw_db_version',6102); }
        if ($dbv < 6101 && (int)get_option('eaiw_db_version',0) < 6102) {
            global $wpdb; require_once ABSPATH.'wp-admin/includes/upgrade.php'; $table_jobs=$wpdb->prefix.'eaiw_jobs';
            $sql_jobs="CREATE TABLE $table_jobs (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            job_type varchar(40) NOT NULL,
            payload longtext NOT NULL,
            priority tinyint unsigned NOT NULL DEFAULT 10,
            available_at datetime NULL,
            elapsed decimal(10,3) NOT NULL DEFAULT 0,
            result longtext NULL,
            status enum('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
            attempts tinyint unsigned DEFAULT 0,
            trace_id varchar(36) NOT NULL,
            provider varchar(20) NULL,
            model varchar(60) NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            started_at datetime NULL,
            finished_at datetime NULL,
            error_text text NULL,
            PRIMARY KEY (id),
            KEY status (status,job_type),
            KEY priority (priority),
            KEY available_at (available_at),
            KEY trace_id (trace_id)
        ) {$wpdb->get_charset_collate()};";
            dbDelta($sql_jobs); update_option('eaiw_db_version',6101);
        }
        if ($dbv < 692) {
            global $wpdb; require_once ABSPATH.'wp-admin/includes/upgrade.php'; $table_jobs=$wpdb->prefix.'eaiw_jobs';
            $sql_jobs="CREATE TABLE $table_jobs (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            job_type varchar(40) NOT NULL,
            payload longtext NOT NULL,
            result longtext NULL,
            status enum('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
            attempts tinyint unsigned DEFAULT 0,
            trace_id varchar(36) NOT NULL,
            provider varchar(20) NULL,
            model varchar(60) NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            started_at datetime NULL,
            finished_at datetime NULL,
            error_text text NULL,
            PRIMARY KEY (id),
            KEY status (status, job_type),
            KEY trace_id (trace_id)
        ) {$wpdb->get_charset_collate()};";
            dbDelta($sql_jobs); update_option('eaiw_db_version',692);
        }
        if ($dbv < 6811) {
            global $wpdb; require_once ABSPATH.'wp-admin/includes/upgrade.php';
            $table_activity=$wpdb->prefix.'eaiw_activity_log';
            $sql_activity="CREATE TABLE $table_activity (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            trace_id varchar(36) NOT NULL,
            level varchar(12) NOT NULL DEFAULT 'info',
            event_name varchar(160) NOT NULL,
            context longtext NULL,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY event_name (event_name),
            KEY level (level),
            KEY created_at (created_at),
            KEY user_id (user_id)
        ) {$wpdb->get_charset_collate()};";
            dbDelta($sql_activity); update_option('eaiw_db_version',6811);
        }
        if (!get_role('eaiw_support')) add_role('eaiw_support','کارشناس اتحادیار',['read'=>true,'eaiw_support'=>true]);
        $support_role=get_role('eaiw_support'); if($support_role && !$support_role->has_cap('eaiw_support')) $support_role->add_cap('eaiw_support');
        // 6.6 chat defaults
        if(!get_option('eaiw_chatsoul_size')) update_option('eaiw_chatsoul_size','medium');
        if(get_option('eaiw_chatsoul_avatar')===false) update_option('eaiw_chatsoul_avatar','');
        if(!get_option('eaiw_chatsoul_position')) update_option('eaiw_chatsoul_position','bottom-right');
        if(get_option('eaiw_chatsoul_offset_x')===false) update_option('eaiw_chatsoul_offset_x',22);
        if(get_option('eaiw_chatsoul_offset_y')===false) update_option('eaiw_chatsoul_offset_y',22);
        if(get_option('eaiw_chatsoul_mobile')===false) update_option('eaiw_chatsoul_mobile',1);
        if(get_option('eaiw_chat_faqs')===false) update_option('eaiw_chat_faqs',[
            ['q'=>'هزینه ارسال چقدر است؟','a'=>'ارسال تهران 1 روزه، شهرستان 2-3 روزه — بالای 1 میلیون رایگان! 😎'],
            ['q'=>'چگونه سفارش رو پیگیری کنم؟','a'=>'کد پیگیری برات پیامک میشه — تو پنل سفارشات هم می‌بینید.'],
        ]);
        // تلگرام پیشرفته
        if(get_option('eaiw_telegram_proxy')===false) update_option('eaiw_telegram_proxy','');
        if(get_option('eaiw_telegram_order_chat')===false) update_option('eaiw_telegram_order_chat','');
        if(get_option('eaiw_telegram_order_enabled')===false) update_option('eaiw_telegram_order_enabled',0);
    }

    public function admin_assets($hook){
        if (strpos($hook, 'eaiw') === false && strpos($hook, 'etehadwp') === false) return;
        wp_enqueue_style('eaiw-nebula', EAIW_URL . 'assets/css/nebula.css', [], EAIW_VERSION);
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('eaiw-nebula', EAIW_URL . 'assets/js/nebula.js', ['jquery','wp-color-picker'], EAIW_VERSION, true);
        wp_enqueue_script('eaiw-agents', EAIW_URL . 'assets/js/agents.js', ['jquery'], EAIW_VERSION, true);
        $soul_name = get_option('eaiw_chatsoul_name','پشتیبان هوشمند');
        wp_localize_script('eaiw-nebula', 'EAIW', [
            'ajax' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('eaiw_nonce'),
            'rest' => esc_url_raw(rest_url('eaiw/v1/')),
            'supernatural' => (int)get_option('eaiw_supernatural_enabled',1),
            'theme' => get_option('eaiw_theme','dark'),
            'portalSeen' => (int)get_option('eaiw_portal_seen_count',0),
            'soulName' => $soul_name,
            'i18n' => [
                'portalTitle' => 'ورود به اتاق فرمان...',
                'jarvisHi' => 'سلام! من دستیار هوشمند اتحاد هستم — امروز چی بسازیم؟',
            ]
        ]);
        wp_add_inline_script('eaiw-nebula', 'window.EAIW_SUPERNATURAL=1;');
    }

    public function frontend_assets(){
        if (!get_option('eaiw_chatsoul_enabled', 0)) return;
        wp_enqueue_style('eaiw-chatsoul', EAIW_URL . 'assets/css/nebula.css', [], EAIW_VERSION);
        wp_enqueue_script('eaiw-chatsoul', EAIW_URL . 'assets/js/chatsoul.js', [], EAIW_VERSION, true);
        $soul_name = get_option('eaiw_chatsoul_name','اتحادیار');
        $soul_greeting = get_option('eaiw_chatsoul_greeting','سلام! من '.$soul_name.' هستم — دستیار باهوش و بامزه‌ات 😎 هر سوالی داری بپرس، حتی جوک!');
        wp_localize_script('eaiw-chatsoul', 'EAIW_SOUL', [
            'rest' => esc_url_raw(rest_url('eaiw/v1/chat')),
            'nonce' => wp_create_nonce('wp_rest'),
            'name' => $soul_name,
            'greeting' => $soul_greeting,
            'color' => get_option('eaiw_chatsoul_color','#6d28ff'),
            'size' => get_option('eaiw_chatsoul_size','medium'),
            'avatar' => get_option('eaiw_chatsoul_avatar',''),
            'position' => get_option('eaiw_chatsoul_position','bottom-right'),
            'offset_x' => get_option('eaiw_chatsoul_offset_x',22),
            'offset_y' => get_option('eaiw_chatsoul_offset_y',22),
            'mobile' => get_option('eaiw_chatsoul_mobile',1),
            'faqs' => array_slice(EAIW_ChatSoul::faqs(),0,4),
        ]);
    }

    public function register_rest(){
        register_rest_route('eaiw/v1', '/chat', [
            'methods' => 'POST',
            'callback' => [EAIW_ChatSoul::class, 'rest_chat'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('eaiw/v1', '/support/(?P<ticket>[a-f0-9-]{36})', ['methods'=>['GET','POST'],'callback'=>function($req){$item=EAIW_Support_Request::by_ticket($req['ticket'],$req->get_param('access_token'));if(!$item)return new WP_Error('support_not_found','درخواست پیدا نشد.',['status'=>404]);return rest_ensure_response($item);},'permission_callback'=>'__return_true']);
        register_rest_route('eaiw/v1', '/feedback', ['methods'=>'POST','callback'=>function($req){$ok=EAIW_Chat_Feedback::save($req->get_param('session_id'),$req->get_param('rating'),$req->get_param('message'));if(!$ok)return new WP_Error('feedback_invalid','بازخورد ثبت نشد.',['status'=>400]);EAIW_Logger::log('Chat feedback',['rating'=>(int)$req->get_param('rating')],'info');return rest_ensure_response(['saved'=>true]);},'permission_callback'=>'__return_true']);
        register_rest_route('eaiw/v1', '/support/audio', ['methods'=>'POST','callback'=>function($req){
            // این Endpoint عمومی است و هر فراخوانی‌اش پول واقعی خرج می‌کند:
            // فایل صوتی تا ۸ مگابایت می‌گیرد و به Whisper می‌فرستد. یک حلقهٔ
            // ساده علیه این URL یعنی صورتحساب نامحدود.
            // وقتی پلاگین هسته فعال است، Endpoint_Guard همان مسیر را با سطل
            // خودش محدود می‌کند؛ اینجا خودداری می‌کنیم تا یک درخواست در دو سطل
            // شمارش نشود. در نصب مستقل (که README صراحتاً مجاز می‌داند) هیچ
            // محافظ دیگری وجود ندارد، پس این تنها لایه است.
            if (!defined('ETEHADYAR_CORE_VERSION')) {
                $rl_key = 'eaiw_audio_rate_' . (is_user_logged_in()
                    ? 'u' . get_current_user_id()
                    : 'ip' . md5(isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown'));
                $rl_hits = (int) get_transient($rl_key);
                if ($rl_hits >= 3) {
                    return new WP_Error('rate_limited', 'تعداد درخواست‌های صوتی از حد مجاز گذشت. لطفاً یک ساعت دیگر دوباره تلاش کنید.', ['status'=>429]);
                }
                set_transient($rl_key, $rl_hits + 1, HOUR_IN_SECONDS);
            }
            $files=$req->get_file_params();$file=$files['audio']??null;if(!$file||!empty($file['error']))return new WP_Error('audio_invalid','فایل صوتی دریافت نشد.',['status'=>400]);$allowed=['audio/webm','audio/ogg','audio/mpeg','audio/wav'];if(!in_array($file['type'],$allowed,true))return new WP_Error('audio_type','نوع فایل صوتی پشتیبانی نمی‌شود.',['status'=>400]);if((int)$file['size']>8*1024*1024)return new WP_Error('audio_size','حجم فایل صوتی نباید بیشتر از ۸ مگابایت باشد.',['status'=>400]);require_once ABSPATH.'wp-admin/includes/file.php';$up=wp_handle_upload($file,['test_form'=>false,'mimes'=>['webm'=>'audio/webm','ogg'=>'audio/ogg','mp3'=>'audio/mpeg','wav'=>'audio/wav']]);if(isset($up['error']))return new WP_Error('audio_upload','بارگذاری فایل صوتی انجام نشد.',['status'=>400]);$out=EAIW_Support_Request::create($req->get_param('message')?:'درخواست صوتی کاربر',$req->get_param('session_id'),$req->get_param('name'),$req->get_param('contact'),$up['url']);$text=EAIW_AI_Client::transcribe_file($file['tmp_name']);if(!is_wp_error($text)&&$text)EAIW_Support_Request::update_transcript($out['id'],$text);EAIW_Logger::log('Audio support request',['ticket'=>$out['ticket']],'info');return rest_ensure_response($out);},'permission_callback'=>'__return_true']);
        register_rest_route('eaiw/v1', '/support', ['methods'=>'POST','callback'=>function($req){$message=sanitize_textarea_field($req->get_param('message')??'');if(mb_strlen($message)<5)return new WP_Error('support_empty','متن درخواست کوتاه است.',['status'=>400]);if(mb_strlen($message)>3000)return new WP_Error('support_long','متن درخواست نباید بیشتر از ۳۰۰۰ کاراکتر باشد.',['status'=>400]);return rest_ensure_response(EAIW_Support_Request::create($message,$req->get_param('session_id'),$req->get_param('name'),$req->get_param('contact')));},'permission_callback'=>'__return_true']);
        register_rest_route('eaiw/v1', '/brain/search', [
            'methods' => 'GET',
            'callback' => function($req){
                $q = sanitize_text_field($req->get_param('q'));
                $res = EAIW_RAG::search($q, 6);
                return rest_ensure_response($res);
            },
            'permission_callback' => function(){ return current_user_can('edit_posts'); }
        ]);
    }

    public function ajax_toggle_supernatural(){
        check_ajax_referer('eaiw_nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('دسترسی غیرمجاز');
        $val = isset($_POST['enabled']) ? (int)$_POST['enabled'] : 1;
        update_option('eaiw_supernatural_enabled', $val);
        EAIW_Logger::log('AJAX operation completed', ['action'=>sanitize_key($_POST['action']??'unknown')], 'success');
        wp_send_json_success(['enabled'=>$val]);
    }
    public function ajax_theme_save(){
        check_ajax_referer('eaiw_nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('دسترسی غیرمجاز', 403);
        $t = sanitize_text_field($_POST['theme'] ?? 'dark');
        if (!in_array($t, ['dark','light'])) $t='dark';
        update_option('eaiw_theme', $t);
        // also for user meta
        update_user_meta(get_current_user_id(), 'eaiw_theme', $t);
        EAIW_Logger::log('AJAX operation completed', ['action'=>sanitize_key($_POST['action']??'unknown')], 'success');
        wp_send_json_success(['theme'=>$t]);
    }
    public function ajax_portal_seen(){
        check_ajax_referer('eaiw_nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('دسترسی غیرمجاز', 403);
        $c = (int)get_option('eaiw_portal_seen_count',0);
        update_option('eaiw_portal_seen_count', $c+1);
        EAIW_Logger::log('AJAX operation completed', ['action'=>sanitize_key($_POST['action']??'unknown')], 'success');
        wp_send_json_success(['count'=>$c+1]);
    }
    public function ajax_brain_index(){
        check_ajax_referer('eaiw_nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('دسترسی');
        $res = EAIW_Site_Brain::index_batch(isset($_POST['offset'])?intval($_POST['offset']):0, 20);
        EAIW_Logger::log('AJAX operation completed', ['action'=>sanitize_key($_POST['action']??'unknown')], 'success');
        wp_send_json_success($res);
    }
    public function ajax_brain_search(){
        check_ajax_referer('eaiw_nonce');
        if (!current_user_can('edit_posts')) wp_send_json_error('دسترسی غیرمجاز', 403);
        $q = sanitize_text_field($_POST['q'] ?? '');
        EAIW_Logger::log('AJAX operation completed', ['action'=>sanitize_key($_POST['action']??'unknown')], 'success');
        wp_send_json_success(EAIW_RAG::search($q, 8));
    }
    public function ajax_agent_toggle(){
        check_ajax_referer('eaiw_nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('دسترسی');
        $key = sanitize_key($_POST['agent'] ?? '');
        $enabled = (int)($_POST['enabled'] ?? 0);
        $r = EAIW_Agent_Manager::set_enabled($key, $enabled);
        EAIW_Logger::log('AJAX operation completed', ['action'=>sanitize_key($_POST['action']??'unknown')], 'success');
        wp_send_json_success($r);
    }
    public function ajax_agent_run(){
        check_ajax_referer('eaiw_nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('دسترسی');
        $key = sanitize_key($_POST['agent'] ?? '');
        $r = EAIW_Agent_Manager::run_now($key);
        EAIW_Logger::log('AJAX operation completed', ['action'=>sanitize_key($_POST['action']??'unknown')], 'success');
        wp_send_json_success($r);
    }
    public function ajax_vision_generate(){
        check_ajax_referer('eaiw_nonce');
        if (!current_user_can('upload_files')) wp_send_json_error('دسترسی');
        $prompt = sanitize_text_field($_POST['prompt'] ?? '');
        $style = sanitize_text_field($_POST['style'] ?? 'photorealistic');
        $size = sanitize_text_field($_POST['size'] ?? '1280x720');
        $res = EAIW_Vision_Studio::generate($prompt, $style, $size);
        if (is_wp_error($res)) wp_send_json_error(self::friendly_error($res));
        EAIW_Logger::log('AJAX operation completed', ['action'=>sanitize_key($_POST['action']??'unknown')], 'success');
        wp_send_json_success($res);
    }
    public function ajax_architect_generate(){
        check_ajax_referer('eaiw_nonce');
        if (!current_user_can('edit_pages')) wp_send_json_error('دسترسی');
        $brief = sanitize_textarea_field($_POST['brief'] ?? '');
        $res = EAIW_Architect::generate($brief);
        if (is_wp_error($res)) wp_send_json_error(self::friendly_error($res));
        EAIW_Logger::log('AJAX operation completed', ['action'=>sanitize_key($_POST['action']??'unknown')], 'success');
        wp_send_json_success($res);
    }
    public function ajax_nexus_test(){
        check_ajax_referer('eaiw_nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('دسترسی غیرمجاز', 403);
        EAIW_Logger::log('AJAX operation completed', ['action'=>sanitize_key($_POST['action']??'unknown')], 'success');
        wp_send_json_success(EAIW_Nexus::test_all());
    }
    public function ajax_soul_save_name(){
        check_ajax_referer('eaiw_nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('دسترسی');
        $name = sanitize_text_field($_POST['name'] ?? '');
        if (!$name) $name='پشتیبان هوشمند';
        update_option('eaiw_chatsoul_name', $name);
        if (isset($_POST['greeting'])) update_option('eaiw_chatsoul_greeting', sanitize_textarea_field($_POST['greeting']));
        if (isset($_POST['color'])) update_option('eaiw_chatsoul_color', sanitize_hex_color($_POST['color']));
        EAIW_Logger::log('AJAX operation completed', ['action'=>sanitize_key($_POST['action']??'unknown')], 'success');
        wp_send_json_success(['name'=>$name]);
    }
    // 6.1 — Factory real — 6.12.5 with provider
    public function ajax_factory_generate(){
        check_ajax_referer('eaiw_nonce');
        if (!current_user_can('edit_posts')) wp_send_json_error('دسترسی');
        $prompt = sanitize_textarea_field($_POST['prompt'] ?? '');
        $post_id = intval($_POST['post_id'] ?? 0);
        $tone = sanitize_text_field($_POST['tone'] ?? 'حرفه‌ای و صمیمی');
        $length = intval($_POST['length'] ?? 1200);
        $provider = sanitize_text_field($_POST['provider'] ?? '');
        $save_draft = !empty($_POST['save_draft']);
        $auto_tts = !empty($_POST['auto_tts']);
        if (!empty($_POST['background'])) {
            $job=EAIW_Job_Queue::enqueue('factory', ['prompt'=>$prompt,'post_id'=>$post_id,'tone'=>$tone,'length'=>$length,'provider'=>$provider,'save_draft'=>$save_draft,'auto_tts'=>$auto_tts], $provider);
            if(is_wp_error($job)) wp_send_json_error(self::friendly_error($job));
            wp_send_json_success(['job_id'=>(int)$job,'queued'=>true]);
        }
        $res = EAIW_Omnichannel_Factory::generate_full([
            'prompt'=>$prompt,
            'post_id'=>$post_id,
            'tone'=>$tone,
            'length'=>$length,
            'provider'=>$provider,
            'save_draft'=>$save_draft,
            'auto_tts'=>$auto_tts,
        ]);
        if(is_wp_error($res)) wp_send_json_error(self::friendly_error($res));
        // ذخیره موقت برای انتشار
        set_transient('eaiw_factory_last_'.get_current_user_id(), $res, HOUR_IN_SECONDS);
        EAIW_Logger::log('AJAX operation completed', ['action'=>sanitize_key($_POST['action']??'unknown')], 'success');
        wp_send_json_success($res);
    }
    public function ajax_flux_generate(){
        check_ajax_referer('eaiw_nonce');
        if (!current_user_can('upload_files')) wp_send_json_error('دسترسی');
        $prompt=sanitize_text_field($_POST['prompt']??'');
        $style=sanitize_text_field($_POST['style']??'photorealistic');
        $size=sanitize_text_field($_POST['size']??'1024x1024');
        if(!empty($_POST['background'])){
            $job=EAIW_Job_Queue::enqueue('image',['prompt'=>$prompt,'style'=>$style,'size'=>$size]);
            if(is_wp_error($job)) wp_send_json_error(self::friendly_error($job));
            wp_send_json_success(['job_id'=>(int)$job,'queued'=>true]);
        }
        $res=EAIW_Flux_Client::generate($prompt,$style,$size);
        if(is_wp_error($res)) wp_send_json_error(self::friendly_error($res));
        EAIW_Logger::log('AJAX operation completed', ['action'=>sanitize_key($_POST['action']??'unknown')], 'success');
        wp_send_json_success($res);
    }
    public function ajax_tts_generate(){
        check_ajax_referer('eaiw_nonce');
        if (!current_user_can('edit_posts')) wp_send_json_error('دسترسی');
        $text=sanitize_textarea_field($_POST['text']??'');
        $voice=sanitize_text_field($_POST['voice']??'alloy');
        if(!empty($_POST['background'])){
            $job=EAIW_Job_Queue::enqueue('tts',['text'=>$text,'voice'=>$voice]);
            if(is_wp_error($job)) wp_send_json_error(self::friendly_error($job));
            wp_send_json_success(['job_id'=>(int)$job,'queued'=>true]);
        }
        $res=EAIW_TTS::synthesize($text,$voice);
        if(is_wp_error($res)) wp_send_json_error(self::friendly_error($res));
        EAIW_Logger::log('AJAX operation completed', ['action'=>sanitize_key($_POST['action']??'unknown')], 'success');
        wp_send_json_success($res);
    }
    public function ajax_factory_publish_telegram(){
        check_ajax_referer('eaiw_nonce');
        if (!current_user_can('edit_posts')) wp_send_json_error('دسترسی');
        $text=sanitize_textarea_field($_POST['text']??'');
        $image=sanitize_text_field($_POST['image']??'');
        $res=EAIW_Telegram::send($text,$image);
        if(is_wp_error($res)) wp_send_json_error(self::friendly_error($res));
        EAIW_Logger::log('AJAX operation completed', ['action'=>sanitize_key($_POST['action']??'unknown')], 'success');
        wp_send_json_success($res);
    }
    public function ajax_factory_publish_instagram(){
        check_ajax_referer('eaiw_nonce');
        if (!current_user_can('edit_posts')) wp_send_json_error('دسترسی');
        $caption=sanitize_textarea_field($_POST['caption']??'');
        $images = isset($_POST['images']) ? array_map('sanitize_text_field', (array)$_POST['images']) : [];
        // اگر از factory last استفاده شد
        if(empty($images)){
            $last=get_transient('eaiw_factory_last_'.get_current_user_id());
            if(!empty($last['images'])) $images=array_map(fn($x)=>$x['url'], $last['images']);
        }
        $res=EAIW_Instagram::publish_carousel($images,$caption);
        if(is_wp_error($res)) wp_send_json_error(self::friendly_error($res));
        EAIW_Logger::log('AJAX operation completed', ['action'=>sanitize_key($_POST['action']??'unknown')], 'success');
        wp_send_json_success($res);
    }
    public function ajax_social_test_telegram(){
        check_ajax_referer('eaiw_nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('دسترسی');
        $res=EAIW_Telegram::test();
        if(is_wp_error($res)) wp_send_json_error(self::friendly_error($res));
        EAIW_Logger::log('AJAX operation completed', ['action'=>sanitize_key($_POST['action']??'unknown')], 'success');
        wp_send_json_success($res);
    }
    public function ajax_social_save(){
        check_ajax_referer('eaiw_nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('دسترسی');
        if(isset($_POST['telegram_token'])) {
            $k = trim(sanitize_text_field(wp_unslash($_POST['telegram_token'])));
            if($k && $k !== '••••••••') EAIW_Vault::save_key('telegram', $k);
        }
        if(isset($_POST['telegram_chat'])) update_option('eaiw_telegram_chat', sanitize_text_field(wp_unslash($_POST['telegram_chat'])));
        if(isset($_POST['instagram_token'])) {
            $k = trim(sanitize_text_field(wp_unslash($_POST['instagram_token'])));
            if($k && $k !== '••••••••') EAIW_Vault::save_key('instagram', $k);
        }
        if(isset($_POST['instagram_user'])) update_option('eaiw_instagram_user', sanitize_text_field($_POST['instagram_user']));
        if(isset($_POST['flux_key'])){
            $k=sanitize_text_field($_POST['flux_key']);
            if($k && $k!=='••••••••') EAIW_Vault::save_key('flux',$k);
        }
        EAIW_Logger::log('AJAX operation completed', ['action'=>sanitize_key($_POST['action']??'unknown')], 'success');
        wp_send_json_success(['ok'=>true]);
    }

    // 6.2 Video Pro
    public function ajax_video_build(){
        check_ajax_referer('eaiw_nonce');
        if (!current_user_can('edit_posts')) wp_send_json_error('دسترسی');
        $title=sanitize_text_field($_POST['title'] ?? 'ویدیو اتحاد');
        $script=json_decode(stripslashes($_POST['script'] ?? '[]'), true);
        $images=isset($_POST['images']) ? array_map('sanitize_text_field', (array)$_POST['images']) : [];
        $audio=sanitize_text_field($_POST['audio'] ?? '');
        if(empty($script) && !empty($_POST['factory_transient'])){
            $last=get_transient('eaiw_factory_last_'.get_current_user_id());
            if($last){
                $title=$last['title'] ?? $title;
                $script=$last['video'] ?? $script;
                $images=array_map(fn($x)=>$x['url'], $last['images'] ?? []);
                $audio=$last['podcast']['audio']['url'] ?? $audio;
                if(empty($audio) && !empty($last['podcast']['text'])){
                    // try TTS quick
                    $tts=EAIW_TTS::synthesize(mb_substr($last['podcast']['text'],0,3500));
                    if(!is_wp_error($tts)) $audio=$tts['url'];
                }
            }
        }
        if(!empty($_POST['background'])){
            $job=EAIW_Job_Queue::enqueue('video',['title'=>$title,'script'=>$script,'images'=>$images,'audio_url'=>$audio,'duration'=>60]);
            if(is_wp_error($job)) wp_send_json_error(self::friendly_error($job));
            wp_send_json_success(['job_id'=>(int)$job,'queued'=>true]);
        }
        $res=EAIW_Video_Studio_Pro::build(['title'=>$title,'script'=>$script,'images'=>$images,'audio_url'=>$audio,'duration'=>60]);
        if(is_wp_error($res)) wp_send_json_error(self::friendly_error($res));
        EAIW_Logger::log('AJAX operation completed', ['action'=>sanitize_key($_POST['action']??'unknown')], 'success');
        wp_send_json_success($res);
    }
    public function ajax_woo_enhance_one(){
        check_ajax_referer('eaiw_nonce');
        if (!current_user_can('edit_products') && !current_user_can('edit_posts')) wp_send_json_error('دسترسی');
        $id=intval($_POST['product_id'] ?? 0);
        $res=EAIW_Woo_Autopilot::enhance_one($id);
        if(is_wp_error($res)) wp_send_json_error(self::friendly_error($res));
        EAIW_Logger::log('AJAX operation completed', ['action'=>sanitize_key($_POST['action']??'unknown')], 'success');
        wp_send_json_success($res);
    }
    public function ajax_woo_bulk_enhance(){
        check_ajax_referer('eaiw_nonce');
        if (!current_user_can('edit_products') && !current_user_can('edit_posts')) wp_send_json_error('دسترسی');
        $ids=isset($_POST['ids']) ? array_map('intval',(array)$_POST['ids']) : [];
        if(empty($ids)) wp_send_json_error('محصولی انتخاب نشده');
        $res=EAIW_Woo_Autopilot::bulk_enhance($ids);
        EAIW_Logger::log('AJAX operation completed', ['action'=>sanitize_key($_POST['action']??'unknown')], 'success');
        wp_send_json_success($res);
    }
    public function ajax_woo_create_product(){
        check_ajax_referer('eaiw_nonce');
        if (!current_user_can('edit_products') && !current_user_can('edit_posts')) wp_send_json_error('دسترسی');
        $prompt=sanitize_textarea_field($_POST['prompt'] ?? '');
        $make_image=!empty($_POST['make_image']);
        $res=EAIW_Woo_Autopilot::create_product($prompt,['make_image'=>$make_image]);
        if(is_wp_error($res)) wp_send_json_error(self::friendly_error($res));
        EAIW_Logger::log('AJAX operation completed', ['action'=>sanitize_key($_POST['action']??'unknown')], 'success');
        wp_send_json_success($res);
    }
    public function ajax_woo_find_weak(){
        check_ajax_referer('eaiw_nonce');
        if (!current_user_can('edit_products') && !current_user_can('edit_posts')) wp_send_json_error('دسترسی غیرمجاز', 403);
        $res=EAIW_Woo_Autopilot::find_weak(8);
        EAIW_Logger::log('AJAX operation completed', ['action'=>sanitize_key($_POST['action']??'unknown')], 'success');
        wp_send_json_success($res);
    }

    public function ajax_nexus_toggle(){
        check_ajax_referer('eaiw_nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('دسترسی');
        $id=intval($_POST['id']??0); $active=intval($_POST['active']??0);
        EAIW_Nexus::toggle($id,$active);
        EAIW_Logger::log('AJAX operation completed', ['action'=>sanitize_key($_POST['action']??'unknown')], 'success');
        wp_send_json_success(['id'=>$id,'active'=>$active]);
    }
    public function ajax_nexus_run(){
        check_ajax_referer('eaiw_nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('دسترسی');
        $id=intval($_POST['id']??0);
        $auto=EAIW_Nexus::get($id);
        if(!$auto) wp_send_json_error('یافت نشد');
        $res=EAIW_Automation_Engine::run($auto);
        EAIW_Logger::log('AJAX operation completed', ['action'=>sanitize_key($_POST['action']??'unknown')], 'success');
        wp_send_json_success($res);
    }
    public function ajax_nexus_create(){
        check_ajax_referer('eaiw_nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('دسترسی');
        $title=sanitize_text_field($_POST['title']??'');
        $trigger=json_decode(stripslashes($_POST['trigger']??'{}'), true);
        $action=json_decode(stripslashes($_POST['action']??'{}'), true);
        if(!$title) wp_send_json_error('عنوان لازم است');
        $id=EAIW_Nexus::create($title,$trigger,$action);
        EAIW_Logger::log('AJAX operation completed', ['action'=>sanitize_key($_POST['action']??'unknown')], 'success');
        wp_send_json_success(['id'=>$id]);
    }
    public function ajax_nexus_delete(){
        check_ajax_referer('eaiw_nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('دسترسی');
        $id=intval($_POST['id']??0);
        EAIW_Nexus::delete($id);
        EAIW_Logger::log('AJAX operation completed', ['action'=>sanitize_key($_POST['action']??'unknown')], 'success');
        wp_send_json_success(['deleted'=>$id]);
    }
    public function ajax_report_pdf(){
        check_ajax_referer('eaiw_nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('دسترسی');
        $url=EAIW_Reports::pdf_url();
        EAIW_Logger::log('AJAX operation completed', ['action'=>sanitize_key($_POST['action']??'unknown')], 'success');
        wp_send_json_success(['url'=>$url, 'html'=>str_replace('.pdf','.html',$url)]);
    }
    public function ajax_report_excel(){
        check_ajax_referer('eaiw_nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('دسترسی');
        $type=sanitize_text_field($_POST['type']??'weak');
        $url=EAIW_Reports::excel_url($type);
        EAIW_Logger::log('AJAX operation completed', ['action'=>sanitize_key($_POST['action']??'unknown')], 'success');
        wp_send_json_success(['url'=>$url]);
    }
    public function ajax_health_repair_cron(){
        check_ajax_referer('eaiw_nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('دسترسی غیرمجاز', 403);
        if (!EAIW_Health::repair_cron()) { EAIW_Logger::log('Cron repair failed', [], 'error'); wp_send_json_error('ترمیم زمان‌بندی انجام نشد. WP-Cron را در تنظیمات هاست بررسی کنید.'); }
        EAIW_Logger::log('Cron repaired', [], 'success');
        update_option('eaiw_health_last_check', time(), false);
        update_option('eaiw_health_snapshot', EAIW_Health::report(), false);
        EAIW_Logger::log('AJAX operation completed', ['action'=>sanitize_key($_POST['action']??'unknown')], 'success');
        wp_send_json_success(['message'=>'زمان‌بندی‌ها با موفقیت ترمیم شدند.']);
    }

    public function ajax_feedback_faq(){check_ajax_referer('eaiw_nonce');if(!current_user_can('manage_options')&&!current_user_can('eaiw_support'))wp_send_json_error('دسترسی غیرمجاز',403);$f=EAIW_Chat_Feedback::get(absint($_POST['id']??0));if(!$f)wp_send_json_error('بازخورد پیدا نشد.');$q=sanitize_text_field($_POST['question']??'پرسش ثبت‌شده از بازخورد');$a=sanitize_textarea_field($_POST['answer']??$f['message']);if(!$q||!$a)wp_send_json_error('پرسش و پاسخ لازم است.');$faqs=EAIW_ChatSoul::faqs();$faqs[]=['q'=>$q,'a'=>$a];EAIW_ChatSoul::save_faqs($faqs);EAIW_Logger::log('Feedback converted to FAQ',['feedback_id'=>(int)$f['id']],'success');wp_send_json_success(['message'=>'بازخورد به FAQ افزوده شد.']);}

    public function ajax_support_update(){check_ajax_referer('eaiw_nonce');if(!current_user_can('manage_options')&&!current_user_can('eaiw_support'))wp_send_json_error('دسترسی غیرمجاز',403);$id=absint($_POST['id']??0);$status=sanitize_key($_POST['status']??'open');$response=sanitize_textarea_field($_POST['response']??'');$assigned=absint($_POST['assigned_user']??0);if(!EAIW_Support_Request::update_status($id,$status,$response,$assigned))wp_send_json_error('به‌روزرسانی درخواست انجام نشد.');EAIW_Logger::log('Support request updated',['request_id'=>$id,'status'=>$status],'success');wp_send_json_success(['message'=>'درخواست به‌روزرسانی شد.']);}

    public function ajax_job_status(){
        check_ajax_referer('eaiw_nonce'); if(!current_user_can('manage_options')) wp_send_json_error('دسترسی غیرمجاز',403);
        $job=EAIW_Job_Queue::status(absint($_POST['id']??0)); if(!$job) wp_send_json_error('کار پیدا نشد.');
        wp_send_json_success($job);
    }

    public function ajax_job_retry(){
        check_ajax_referer('eaiw_nonce'); if(!current_user_can('manage_options')) wp_send_json_error('دسترسی غیرمجاز',403);
        $id=absint($_POST['id']??0); if(!EAIW_Job_Queue::retry($id)) wp_send_json_error('این کار ناموفق پیدا نشد.');
        EAIW_Logger::log('Job retried',['job_id'=>$id],'info'); wp_send_json_success(['message'=>'کار برای اجرای دوباره در صف قرار گرفت.']);
    }
    public function ajax_job_cancel(){
        check_ajax_referer('eaiw_nonce'); if(!current_user_can('manage_options')) wp_send_json_error('دسترسی غیرمجاز',403);
        $id=absint($_POST['id']??0); if(!EAIW_Job_Queue::cancel($id)) wp_send_json_error('کار قابل لغو پیدا نشد.');
        EAIW_Logger::log('Job cancelled',['job_id'=>$id],'warning'); wp_send_json_success(['message'=>'کار لغو شد.']);
    }

    public function ajax_activity_clear(){
        check_ajax_referer('eaiw_nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('دسترسی غیرمجاز',403);
        EAIW_Logger::clear();
        EAIW_Logger::log('Activity log cleared', [], 'warning');
        EAIW_Logger::log('AJAX operation completed', ['action'=>sanitize_key($_POST['action']??'unknown')], 'success');
        wp_send_json_success(['message'=>'تاریخچه رویدادها پاک شد.']);
    }

    public function ajax_health_check(){
        check_ajax_referer('eaiw_nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('دسترسی غیرمجاز', 403);
        $report = EAIW_Health::report();
        update_option('eaiw_health_last_check', time(), false);
        update_option('eaiw_health_snapshot', $report, false);
        EAIW_Logger::log('Health check', ['items'=>count($report)], 'info');
        EAIW_Logger::log('AJAX operation completed', ['action'=>sanitize_key($_POST['action']??'unknown')], 'success');
        wp_send_json_success(['report'=>$report, 'checked_at'=>current_time('mysql')]);
    }

    public function export_health_report(){
        if (!current_user_can('manage_options')) wp_die('دسترسی غیرمجاز', 'اتحادیار', ['response'=>403]);
        check_admin_referer('eaiw_health_export');
        $report = EAIW_Health::report();
        update_option('eaiw_health_last_check', time(), false);
        update_option('eaiw_health_snapshot', $report, false);
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="etehadyar-health-'.gmdate('Y-m-d-His').'.json"');
        echo wp_json_encode(['plugin_version'=>EAIW_VERSION,'checked_at'=>current_time('mysql'),'report'=>$report], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
        exit;
    }

    public function ajax_ai_test(){
        check_ajax_referer('eaiw_nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('دسترسی');
        $provider=sanitize_text_field($_POST['provider']??'');
        $key=EAIW_Vault::get_key($provider);
        if(!$key) wp_send_json_error('کلید وارد نشده');
        // تست سبک: یک درخواست کوتاه
        $res=EAIW_AI_Client::complete('سلام', 'تو دستیار فارسی هستی', ['provider'=>$provider,'max_tokens'=>20,'temperature'=>0.3]);
        if(is_wp_error($res)) wp_send_json_error(self::friendly_error($res));
        EAIW_Logger::log('AJAX operation completed', ['action'=>sanitize_key($_POST['action']??'unknown')], 'success');
        wp_send_json_success(['ok'=>true,'preview'=>mb_substr($res,0,80)]);
    }
    public function on_woocommerce_order($order_id){
        if(class_exists('EAIW_Telegram') && get_option('eaiw_telegram_order_enabled')){
            wp_schedule_single_event(time()+10, 'eaiw_tg_order_event', [$order_id]);
        }
    }
    public function handle_tg_order($order_id){
        if(class_exists('EAIW_Telegram')) EAIW_Telegram::send_order($order_id);
    }
    public function run_automation_cron(){
        if(class_exists('EAIW_Automation_Engine')) EAIW_Automation_Engine::cron_tick();
    }
    public function on_post_status($new,$old,$post){
        if($new!=='publish' || $old==='publish') return;
        if($post->post_type==='product'){
            if(class_exists('EAIW_Automation_Engine')) EAIW_Automation_Engine::on_product_publish($post->ID);
        } elseif(in_array($post->post_type,['post','page'])){
            if(class_exists('EAIW_Automation_Engine')) EAIW_Automation_Engine::on_post_publish($post->ID);
        }
    }

    public function cleanup_support_audio(){if(class_exists('EAIW_Support_Request')) EAIW_Support_Request::cleanup_audio(7);}

    public function run_jobs_cron(){ if(class_exists('EAIW_Job_Queue')) EAIW_Job_Queue::work(3); }

    public function run_agents_cron(){
        EAIW_Agent_Manager::run_due_agents();
    }
    public function run_guardian_cron(){
        if (class_exists('EAIW_Guardian')) EAIW_Guardian::scan();
    }
    /** تبدیل خطاهای فنی سرویس‌ها به پیام فارسی قابل فهم. */
    public static function friendly_error($error){
        if (!is_wp_error($error)) return 'عملیات با خطا روبه‌رو شد. دوباره تلاش کنیدید.';
        $code = $error->get_error_code();
        EAIW_Logger::log('Operation failed', ['code'=>$code], 'error');
        $messages = [
            'empty'=>'اطلاعات لازم وارد نشده است.', 'short'=>'توضیح واردشده کوتاه است. جزئیات بیشتری وارد کنید.',
            'queue_limit'=>'صف این نوع کار در حال حاضر پر است. پس از پایان کارهای فعلی دوباره تلاش کنید.',
            'no_key'=>'کلید دسترسی تنظیم نشده است. از بخش تنظیمات، یک سرویس هوش مصنوعی را فعال کنید.',
            'no_config'=>'تنظیمات این بخش کامل نیست. اطلاعات اتصال را بررسی کنید.', 'no_token'=>'کلید دسترسی وارد نشده است. ابتدا آن را در تنظیمات ذخیره کنید.',
            'no_credits'=>'اعتبار سرویس هوش مصنوعی کافی نیست. سرویس دیگری انتخاب یا اعتبار آن را بررسی کنید.', 'credits'=>'اعتبار سرویس انتخاب‌شده کافی نیست. سرویس دیگری انتخاب کنید.',
            'all_failed'=>'هیچ‌یک از سرویس‌های هوش مصنوعی پاسخ ندادند. تنظیمات اتصال و اعتبار را بررسی کنید.', 'api_error'=>'سرویس هوش مصنوعی پاسخ مناسبی نداد. چند لحظه بعد دوباره تلاش کنیدید.',
            'http_error'=>'ارتباط با سرویس برقرار نشد. اتصال اینترنت و دسترسی هاست را بررسی کنید.', 'http'=>'ارتباط با سرویس برقرار نشد. اتصال اینترنت و دسترسی هاست را بررسی کنید.',
            'rate_limited'=>'تعداد درخواست‌ها زیاد است. لطفاً کمی بعد دوباره تلاش کنیدید.', 'bad_json'=>'پاسخ سرویس قابل پردازش نبود. دوباره تلاش کنیدید.',
            'no_url'=>'سرویس نتیجه‌ای برای دریافت برنگرداند.', 'no_woo'=>'ووکامرس فعال یا آماده استفاده نیست.', 'not_found'=>'مورد درخواست‌شده پیدا نشد یا دیگر وجود ندارد.',
            'upload'=>'بارگذاری فایل انجام نشد. دسترسی پوشه بارگذاری را بررسی کنید.', 'dl'=>'دریافت تصویر انجام نشد. نشانی تصویر را بررسی کنید.',
            'ig_error'=>'انتشار در اینستاگرام انجام نشد. اتصال و دسترسی حساب را بررسی کنید.', 'tg_error'=>'ارسال به تلگرام انجام نشد. توکن، شناسه کانال و دسترسی ربات را بررسی کنید.',
            'chat_not_found'=>'کانال یا گفت‌وگو پیدا نشد. شناسه و دسترسی ربات را بررسی کنید.', 'chat_error'=>'ارتباط با کانال تلگرام برقرار نشد. تنظیمات ربات را بررسی کنید.',
            'tts_error'=>'تبدیل متن به صدا انجام نشد. سرویس صدا و کلید دسترسی را بررسی کنید.', 'flux_error'=>'تولید تصویر انجام نشد. سرویس تصویر و کلید دسترسی را بررسی کنید.',
            'stability_error'=>'تولید تصویر انجام نشد. سرویس تصویر و کلید دسترسی را بررسی کنید.', 'save_error'=>'ذخیره اطلاعات انجام نشد. دوباره تلاش کنیدید.'
        ];
        return $messages[$code] ?? 'عملیات با خطا روبه‌رو شد. تنظیمات را بررسی و دوباره تلاش کنیدید.';
    }

}
