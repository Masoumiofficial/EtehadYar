<?php
defined('ABSPATH') || exit;
class EAIW_Deactivator {
    public static function deactivate(){
        wp_clear_scheduled_hook('eaiw_agents_cron');
        wp_clear_scheduled_hook('eaiw_guardian_cron');
        wp_clear_scheduled_hook('eaiw_automation_cron');
        wp_clear_scheduled_hook('eaiw_jobs_cron');
        wp_clear_scheduled_hook('eaiw_support_audio_cleanup');
        flush_rewrite_rules();
    }
}
