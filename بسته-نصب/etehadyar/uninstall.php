<?php
if (!defined('WP_UNINSTALL_PLUGIN')) exit;
// داده‌های افزونه به‌صورت پیش‌فرض حفظ می‌شوند. حذف کامل فقط با فعال‌سازی صریح این گزینه انجام می‌شود.
if (!get_option('eaiw_delete_data_on_uninstall', 0)) return;
global $wpdb;
$tables=['eaiw_vectors','eaiw_agents','eaiw_jobs','eaiw_automations','eaiw_chatsoul_logs','eaiw_automation_runs','eaiw_activity_log','eaiw_usage','eaiw_support_requests','eaiw_support_history','eaiw_chat_feedback'];
foreach ($tables as $table) $wpdb->query('DROP TABLE IF EXISTS `'.$wpdb->prefix.$table.'`');
$options=['eaiw_db_version','eaiw_health_last_check','eaiw_health_snapshot','eaiw_usage_rates','eaiw_delete_data_on_uninstall'];
foreach ($options as $option) delete_option($option);
remove_role('eaiw_support');
