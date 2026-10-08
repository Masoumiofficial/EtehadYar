<?php
defined('ABSPATH') || exit;

/** مرکز سلامت اتحادیار — بررسی پیش‌نیازها و وضعیت سرویس‌ها */
class EAIW_Health {
    public static function check(){
        $report = self::report();
        $res = [];
        foreach ($report as $key=>$item) $res[$key] = (bool)$item['ok'];
        return $res;
    }

    public static function report(){
        global $wp_version;
        $upload = wp_upload_dir();
        $api = EAIW_Vault::get_key('gapgpt') || EAIW_Vault::get_key('openai') || EAIW_Vault::get_key('gemini') || EAIW_Vault::get_key('claude');
        $required_tables=['eaiw_vectors','eaiw_agents','eaiw_jobs','eaiw_automations','eaiw_chatsoul_logs','eaiw_automation_runs','eaiw_activity_log'];
        $missing_tables=[]; foreach($required_tables as $table_name) if(!self::table_exists($table_name)) $missing_tables[]=$table_name;
        $max_execution=(int)ini_get('max_execution_time');
        $rest_ok=wp_http_validate_url(rest_url('eaiw/v1/'))!==false;
        return [
            'php'=>['label'=>'نسخه PHP','value'=>PHP_VERSION,'ok'=>version_compare(PHP_VERSION,'7.4','>='),'help'=>'حداقل نسخه مورد نیاز PHP، نسخه ۷.۴ است.'],
            'wordpress'=>['label'=>'نسخه وردپرس','value'=>(string)$wp_version,'ok'=>version_compare($wp_version,'6.0','>='),'help'=>'حداقل نسخه مورد نیاز وردپرس، نسخه ۶ است.'],
            'openssl'=>['label'=>'رمزنگاری OpenSSL','value'=>extension_loaded('openssl')?'فعال':'غیرفعال','ok'=>extension_loaded('openssl'),'help'=>'برای نگهداری امن کلیدهای دسترسی لازم است.'],
            'mbstring'=>['label'=>'پشتیبانی متن فارسی','value'=>extension_loaded('mbstring')?'فعال':'غیرفعال','ok'=>extension_loaded('mbstring'),'help'=>'برای پردازش درست متن فارسی و تقسیم محتوا لازم است.'],
            'curl'=>['label'=>'ارتباط اینترنتی','value'=>function_exists('curl_init')?'فعال':'غیرفعال','ok'=>function_exists('curl_init'),'help'=>'برای ارتباط با سرویس‌های هوش مصنوعی استفاده می‌شود.'],
            'memory'=>['label'=>'حافظه PHP','value'=>self::memory_value(),'ok'=>self::mem_ok(),'help'=>'برای کارهای سنگین، حداقل ۲۵۶ مگابایت پیشنهاد می‌شود.'],
            'vectors'=>['label'=>'جدول خرد سایت','value'=>self::table_exists('eaiw_vectors')?'آماده':'یافت نشد','ok'=>self::table_exists('eaiw_vectors'),'help'=>'برای جست‌وجوی محتوای سایت استفاده می‌شود.'],
            'agents'=>['label'=>'جدول یاران هوشمند','value'=>self::table_exists('eaiw_agents')?'آماده':'یافت نشد','ok'=>self::table_exists('eaiw_agents'),'help'=>'برای نگهداری تنظیمات یاران هوشمند استفاده می‌شود.'],
            'cron'=>['label'=>'زمان‌بندی یاران','value'=>wp_next_scheduled('eaiw_agents_cron')?'فعال':'تنظیم نشده','ok'=>(bool)wp_next_scheduled('eaiw_agents_cron'),'help'=>'اجرای دوره‌ای یاران هوشمند را کنترل می‌کند.'],
            'automation_cron'=>['label'=>'زمان‌بندی خودکارسازی','value'=>wp_next_scheduled('eaiw_automation_cron')?'فعال':'تنظیم نشده','ok'=>(bool)wp_next_scheduled('eaiw_automation_cron'),'help'=>'اجرای خودکار کارها را کنترل می‌کند.'],
            'guardian_cron'=>['label'=>'زمان‌بندی نگهبان','value'=>wp_next_scheduled('eaiw_guardian_cron')?'فعال':'تنظیم نشده','ok'=>(bool)wp_next_scheduled('eaiw_guardian_cron'),'help'=>'بررسی دوره‌ای سلامت سایت را کنترل می‌کند.'],
            'https'=>['label'=>'اتصال امن سایت','value'=>is_ssl()?'فعال':'غیرفعال','ok'=>is_ssl(),'help'=>'برای امنیت ورود و ارتباط با سرویس‌ها استفاده می‌شود.'],
            'api'=>['label'=>'سرویس هوش مصنوعی','value'=>$api?'تنظیم شده':'تنظیم نشده','ok'=>(bool)$api,'help'=>'حداقل یک کلید دسترسی در تنظیمات وارد کنید.'],
            'uploads'=>['label'=>'پوشه بارگذاری','value'=>is_writable($upload['basedir'])?'قابل نوشتن':'غیرقابل نوشتن','ok'=>is_writable($upload['basedir']),'help'=>'برای ذخیره تصویر، صدا و گزارش لازم است.'],
            'database'=>['label'=>'جدول‌های افزونه','value'=>$missing_tables?'ناقص':'کامل','ok'=>!$missing_tables,'help'=>$missing_tables?'جدول‌های ناقص: '.implode('، ',$missing_tables):'همه جدول‌های اصلی آماده هستند.'],
            'execution'=>['label'=>'زمان اجرای PHP','value'=>$max_execution?$max_execution.' ثانیه':'بدون محدودیت','ok'=>!$max_execution || $max_execution>=60,'help'=>'برای عملیات تولید محتوای طولانی، حداقل ۶۰ ثانیه پیشنهاد می‌شود.'],
            'rest'=>['label'=>'مسیر REST افزونه','value'=>$rest_ok?'در دسترس':'بررسی نشد','ok'=>$rest_ok,'help'=>'برای ارتباط همراه سایت و بخش‌های مدیریتی استفاده می‌شود.'],
            'woocommerce'=>['label'=>'ووکامرس','value'=>class_exists('WooCommerce')?'فعال':'نصب نشده','ok'=>class_exists('WooCommerce'),'help'=>'فقط برای قابلیت‌های فروشگاهی لازم است.'],
        ];
    }
    public static function repair_cron(){
        if (!wp_next_scheduled('eaiw_agents_cron')) wp_schedule_event(time()+60, 'fifteen_minutes', 'eaiw_agents_cron');
        if (!wp_next_scheduled('eaiw_automation_cron')) wp_schedule_event(time()+60, 'fifteen_minutes', 'eaiw_automation_cron');
        if (!wp_next_scheduled('eaiw_guardian_cron')) wp_schedule_event(time()+300, 'hourly', 'eaiw_guardian_cron');
        return (bool)wp_next_scheduled('eaiw_agents_cron') && (bool)wp_next_scheduled('eaiw_automation_cron') && (bool)wp_next_scheduled('eaiw_guardian_cron');
    }

    private static function memory_value(){ return ini_get('memory_limit') ?: 'نامشخص'; }
    private static function mem_ok(){ $m=ini_get('memory_limit'); if($m==='' || $m==='-1') return true; return wp_convert_hr_to_bytes($m)>=256*1024*1024; }
    private static function table_exists($tn){ global $wpdb; $t=$wpdb->prefix.$tn; return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$t))===$t; }
}
