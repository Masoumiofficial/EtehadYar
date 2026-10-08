<?php
defined('ABSPATH') || exit;
class EAIW_Logger {
    public static function log($msg, $ctx=[], $level='info'){
        $trace = wp_generate_uuid4();
        $safe_ctx = self::safe_context($ctx);
        $level = self::normalize_level($level);
        $line = sprintf("[EAIW:%s] %s %s\n", $trace, $msg, $safe_ctx?wp_json_encode($safe_ctx, JSON_UNESCAPED_UNICODE):'');
        error_log($line);
        global $wpdb;
        $table=$wpdb->prefix.'eaiw_activity_log';
        if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table))===$table){
            $wpdb->insert($table,['trace_id'=>$trace,'event_name'=>sanitize_text_field($msg),'level'=>$level,'context'=>wp_json_encode($safe_ctx,JSON_UNESCAPED_UNICODE),'user_id'=>get_current_user_id()?:0]);
        }
        return $trace;
    }
    public static function recent($args=[]){
        global $wpdb;
        $table=$wpdb->prefix.'eaiw_activity_log';
        $args=wp_parse_args($args,['limit'=>50,'page'=>1,'search'=>'','event'=>'','level'=>'']);
        $limit=max(1,min(200,(int)$args['limit']));
        $page=max(1,(int)$args['page']); $offset=($page-1)*$limit;
        if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table))!==$table) return [];
        $where=['1=1']; $values=[];
        if($args['search']!==''){$like='%'.$wpdb->esc_like(sanitize_text_field($args['search'])).'%';$where[]='(event_name LIKE %s OR trace_id LIKE %s OR context LIKE %s)';array_push($values,$like,$like,$like);}
        if($args['event']!==''){$where[]='event_name=%s';$values[]=sanitize_text_field($args['event']);}
        if($args['level']!==''){$where[]='level=%s';$values[]=self::normalize_level($args['level']);}
        $sql="SELECT * FROM $table WHERE ".implode(' AND ',$where)." ORDER BY id DESC LIMIT %d OFFSET %d";
        $values[]=$limit; $values[]=$offset;
        return $wpdb->get_results($wpdb->prepare($sql,$values),ARRAY_A);
    }
    public static function count($args=[]){
        global $wpdb; $table=$wpdb->prefix.'eaiw_activity_log';
        if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table))!==$table) return 0;
        $args=wp_parse_args($args,['search'=>'','event'=>'','level'=>'']); $where=['1=1'];$values=[];
        if($args['search']!==''){$like='%'.$wpdb->esc_like(sanitize_text_field($args['search'])).'%';$where[]='(event_name LIKE %s OR trace_id LIKE %s OR context LIKE %s)';array_push($values,$like,$like,$like);}
        if($args['event']!==''){$where[]='event_name=%s';$values[]=sanitize_text_field($args['event']);}
        if($args['level']!==''){$where[]='level=%s';$values[]=self::normalize_level($args['level']);}
        return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE ".implode(' AND ',$where),$values));
    }
    public static function event_names(){
        global $wpdb; $table=$wpdb->prefix.'eaiw_activity_log';
        if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table))!==$table) return [];
        return $wpdb->get_col("SELECT DISTINCT event_name FROM $table WHERE event_name<>'' ORDER BY event_name ASC");
    }
    public static function summary(){
        global $wpdb; $table=$wpdb->prefix.'eaiw_activity_log';
        $empty=['total'=>0,'today'=>0,'levels'=>['info'=>0,'success'=>0,'warning'=>0,'error'=>0],'top_events'=>[]];
        if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table))!==$table) return $empty;
        $empty['total']=(int)$wpdb->get_var("SELECT COUNT(*) FROM $table");
        $empty['today']=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE created_at >= %s",current_time('mysql',true) ? gmdate('Y-m-d 00:00:00') : date('Y-m-d 00:00:00')));
        foreach($wpdb->get_results("SELECT level, COUNT(*) total FROM $table GROUP BY level",ARRAY_A) as $row){ if(isset($empty['levels'][$row['level']])) $empty['levels'][$row['level']]=(int)$row['total']; }
        $empty['top_events']=$wpdb->get_results("SELECT event_name, COUNT(*) total FROM $table WHERE event_name<>'' GROUP BY event_name ORDER BY total DESC LIMIT 5",ARRAY_A);
        return $empty;
    }
    public static function daily_summary($days=7){
        global $wpdb; $table=$wpdb->prefix.'eaiw_activity_log'; $days=max(1,min(31,(int)$days));
        $out=[]; for($i=$days-1;$i>=0;$i--){$date=gmdate('Y-m-d',strtotime("-$i days"));$out[$date]=['date'=>$date,'total'=>0,'success'=>0,'error'=>0,'warning'=>0,'info'=>0];}
        if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table))!==$table) return array_values($out);
        $from=gmdate('Y-m-d 00:00:00',strtotime('-'.($days-1).' days'));
        $rows=$wpdb->get_results($wpdb->prepare("SELECT DATE(created_at) day, level, COUNT(*) total FROM $table WHERE created_at >= %s GROUP BY DATE(created_at), level ORDER BY day ASC",$from),ARRAY_A);
        foreach($rows as $row){if(isset($out[$row['day']])){$out[$row['day']]['total']+=(int)$row['total'];if(isset($out[$row['day']][$row['level']]))$out[$row['day']][$row['level']]=(int)$row['total'];}}
        return array_values($out);
    }
    public static function level_names(){ return ['info'=>'اطلاعات','success'=>'موفق','warning'=>'هشدار','error'=>'خطا']; }
    private static function normalize_level($level){ return in_array($level,['info','success','warning','error'],true)?$level:'info'; }
    public static function clear(){ global $wpdb; $table=$wpdb->prefix.'eaiw_activity_log'; if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table))===$table) return $wpdb->query("TRUNCATE TABLE $table"); return false; }
    private static function safe_context($ctx){
        if(!is_array($ctx)) return [];
        foreach(['key','token','api_key','access_token','password','secret'] as $s) unset($ctx[$s]);
        return $ctx;
    }
}
