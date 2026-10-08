<?php
defined('ABSPATH') || exit;
/** صف کارهای اتحادیار — اجرای کنترل‌شده عملیات پس‌زمینه */
class EAIW_Job_Queue {
    public static function enqueue($type,$payload=[],$provider='',$model='',$priority=10){
        global $wpdb; $table=$wpdb->prefix.'eaiw_jobs';
        if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table))!==$table) return new WP_Error('queue_missing','جدول صف کارها آماده نیست.');
        $type=sanitize_key($type); $limits=['factory'=>1,'video'=>1,'image'=>2,'tts'=>2,'agent'=>2,'guardian'=>1]; $max=$limits[$type]??3;
        $active=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE job_type=%s AND status IN ('pending','processing')",$type));
        if($active >= $max) return new WP_Error('queue_limit','تعداد کارهای هم‌زمان این بخش به سقف مجاز رسیده است. پس از پایان کارهای فعلی دوباره تلاش کنید.');
        $trace=wp_generate_uuid4(); $ok=$wpdb->insert($table,['job_type'=>sanitize_key($type),'payload'=>wp_json_encode($payload,JSON_UNESCAPED_UNICODE),'status'=>'pending','priority'=>max(1,min(100,(int)$priority)),'available_at'=>current_time('mysql',true),'trace_id'=>$trace,'provider'=>sanitize_key($provider),'model'=>sanitize_text_field($model)]);
        if(!$ok) return new WP_Error('queue_insert','افزودن کار به صف انجام نشد.');
        EAIW_Logger::log('Job queued',['type'=>sanitize_key($type),'trace_id'=>$trace],'info'); return (int)$wpdb->insert_id;
    }
    public static function summary(){
        global $wpdb; $table=$wpdb->prefix.'eaiw_jobs'; $out=['total'=>0,'pending'=>0,'processing'=>0,'completed'=>0,'failed'=>0,'by_provider'=>[]];
        if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table))!==$table)return $out;
        foreach($wpdb->get_results("SELECT status,COUNT(*) total FROM $table GROUP BY status",ARRAY_A) as $r)if(isset($out[$r['status']]))$out[$r['status']]=(int)$r['total'];
        $out['total']=$out['pending']+$out['processing']+$out['completed']+$out['failed'];
        $out['by_provider']=$wpdb->get_results("SELECT COALESCE(NULLIF(provider,''),'بدون تعیین') provider,COUNT(*) total,SUM(status='completed') completed,SUM(status='failed') failed,ROUND(AVG(elapsed),3) avg_elapsed FROM $table GROUP BY provider ORDER BY total DESC",ARRAY_A);
        return $out;
    }
    public static function recent($limit=50){
        global $wpdb; $table=$wpdb->prefix.'eaiw_jobs'; $limit=max(1,min(200,(int)$limit));
        if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table))!==$table)return [];
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM $table ORDER BY id DESC LIMIT %d",$limit),ARRAY_A);
    }
    public static function status($id){
        global $wpdb; $table=$wpdb->prefix.'eaiw_jobs'; if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table))!==$table)return false;
        $job=$wpdb->get_row($wpdb->prepare("SELECT id,job_type,status,attempts,trace_id,result,error_text,created_at,finished_at FROM $table WHERE id=%d",(int)$id),ARRAY_A);
        if(!$job)return null; if($job['result'])$job['result']=json_decode($job['result'],true); return $job;
    }
    public static function retry($id){
        global $wpdb; $table=$wpdb->prefix.'eaiw_jobs';
        return (bool)$wpdb->query($wpdb->prepare("UPDATE $table SET status='pending', started_at=NULL, finished_at=NULL, error_text=NULL, available_at=%s WHERE id=%d AND status='failed'",current_time('mysql',true),(int)$id));
    }
    public static function cancel($id){
        global $wpdb; $table=$wpdb->prefix.'eaiw_jobs';
        return (bool)$wpdb->query($wpdb->prepare("UPDATE $table SET status='failed', finished_at=%s, error_text=%s WHERE id=%d AND status IN ('pending','processing')",current_time('mysql',true),'به درخواست مدیر لغو شد.',(int)$id));
    }

    public static function run_next(){
        global $wpdb; $table=$wpdb->prefix.'eaiw_jobs';
        $job=$wpdb->get_row("SELECT * FROM $table WHERE status='pending' AND (available_at IS NULL OR available_at <= UTC_TIMESTAMP()) ORDER BY priority ASC, id ASC LIMIT 1",ARRAY_A); if(!$job)return false;
        $started_at=microtime(true); $claimed=$wpdb->query($wpdb->prepare("UPDATE $table SET status='processing',started_at=%s,attempts=attempts+1 WHERE id=%d AND status='pending'",current_time('mysql',true),$job['id'])); if(!$claimed)return false;
        $payload=json_decode($job['payload'],true); $result=null; $error='';
        try{
            switch($job['job_type']){
                case 'agent': $result=EAIW_Agent_Manager::run_now(sanitize_key($payload['agent']??'')); break;
                case 'guardian': $result=EAIW_Guardian::scan(); break;
                case 'factory': $result=EAIW_Omnichannel_Factory::generate_full($payload); break;
                case 'image': $result=EAIW_Flux_Client::generate($payload['prompt']??'', $payload['style']??'photorealistic', $payload['size']??'1024x1024'); break;
                case 'tts': $result=EAIW_TTS::synthesize($payload['text']??'', $payload['voice']??'alloy'); break;
                case 'video': $result=EAIW_Video_Studio_Pro::build($payload); break;
                default: throw new Exception('نوع کار پشتیبانی نمی‌شود.');
            }
            if(is_wp_error($result)) throw new Exception($result->get_error_message());
            $wpdb->update($table,['status'=>'completed','finished_at'=>current_time('mysql',true),'error_text'=>null,'result'=>wp_json_encode($result,JSON_UNESCAPED_UNICODE),'elapsed'=>round(microtime(true)-$started_at,3)],['id'=>$job['id']]); EAIW_Logger::log('Job completed',['trace_id'=>$job['trace_id'],'type'=>$job['job_type']],'success');
        }catch(Exception $e){$error=$e->getMessage();$attempt=(int)$job['attempts']+1;$final=$attempt>=3;$data=['status'=>$final?'failed':'pending','finished_at'=>$final?current_time('mysql',true):null,'started_at'=>null,'available_at'=>$final?null:gmdate('Y-m-d H:i:s',time()+(60*pow(5,$attempt-1))),'error_text'=>sanitize_text_field($error),'result'=>null,'elapsed'=>round(microtime(true)-$started_at,3)];$wpdb->update($table,$data,['id'=>$job['id']]);EAIW_Logger::log($final?'Job failed':'Job retry scheduled',['trace_id'=>$job['trace_id'],'type'=>$job['job_type'],'attempt'=>$attempt],$final?'error':'warning');}
        return ['id'=>(int)$job['id'],'status'=>$error?'failed':'completed','trace_id'=>$job['trace_id']];
    }
    public static function recover_stale($minutes=15){
        global $wpdb; $table=$wpdb->prefix.'eaiw_jobs'; if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table))!==$table)return 0; $cutoff=gmdate('Y-m-d H:i:s',time()-max(5,(int)$minutes)*MINUTE_IN_SECONDS);
        $rows=$wpdb->get_results($wpdb->prepare("SELECT id,attempts FROM $table WHERE status='processing' AND started_at < %s",$cutoff),ARRAY_A); $count=0;
        foreach($rows as $row){$new_status=((int)$row['attempts']<3)?'pending':'failed';$error=$new_status==='failed'?'تعداد تلاش‌های مجاز تمام شد.':null;$wpdb->update($table,['status'=>$new_status,'started_at'=>null,'error_text'=>$error],['id'=>(int)$row['id']]);$count++;EAIW_Logger::log('Stale job recovered',['job_id'=>(int)$row['id'],'status'=>$new_status],$new_status==='failed'?'error':'warning');} return $count;
    }
    public static function work($limit=3){if(get_transient('eaiw_queue_worker_lock'))return [];set_transient('eaiw_queue_worker_lock',1,55);$done=[];try{self::recover_stale();for($i=0;$i<max(1,min(10,(int)$limit));$i++){ $r=self::run_next();if(!$r)break;$done[]=$r;}}finally{delete_transient('eaiw_queue_worker_lock');}return $done;}
}
