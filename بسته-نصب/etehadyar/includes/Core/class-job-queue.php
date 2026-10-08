<?php
defined('ABSPATH') || exit;
/** صف کارهای اتحادیار — اجرای کنترل‌شده عملیات پس‌زمینه */
class EAIW_Job_Queue {
    /** @var array<string,bool> Cache of user_id column probes, keyed by table. */
    protected static $user_column=[];

    /**
     * Whether the jobs table carries the tenant column added by the core plugin.
     *
     * Probed once per request per table: enqueue() runs inside user requests
     * and must not pay for a schema query every time.
     *
     * @param string $table Full table name.
     * @return bool
     */
    protected static function has_user_column($table){
        if(!isset(self::$user_column[$table])){
            global $wpdb;
            self::$user_column[$table] = (bool) $wpdb->get_var($wpdb->prepare('SHOW COLUMNS FROM `'.$table.'` LIKE %s','user_id'));
        }
        return self::$user_column[$table];
    }

    public static function enqueue($type,$payload=[],$provider='',$model='',$priority=10){
        global $wpdb; $table=$wpdb->prefix.'eaiw_jobs';
        if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table))!==$table) return new WP_Error('queue_missing','جدول صف کارها آماده نیست.');
        $type=sanitize_key($type); $limits=['factory'=>1,'video'=>1,'image'=>2,'tts'=>2,'agent'=>2,'guardian'=>1]; $max=$limits[$type]??3;
        $active=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE job_type=%s AND status IN ('pending','processing')",$type));
        if($active >= $max) return new WP_Error('queue_limit','تعداد کارهای هم‌زمان این بخش به سقف مجاز رسیده است. پس از پایان کارهای فعلی دوباره تلاش کنید.');
        $trace=wp_generate_uuid4();
        $row=['job_type'=>sanitize_key($type),'payload'=>wp_json_encode($payload,JSON_UNESCAPED_UNICODE),'status'=>'pending','priority'=>max(1,min(100,(int)$priority)),'available_at'=>current_time('mysql',true),'trace_id'=>$trace,'provider'=>sanitize_key($provider),'model'=>sanitize_text_field($model)];
        // The core plugin adds user_id to this table so background work can be
        // attributed to a tenant. Standalone installs of this plugin do not
        // have the column, so only write it when it is really there — otherwise
        // every enqueue would fail on an unknown column.
        if(self::has_user_column($table)){ $row['user_id']=get_current_user_id(); }
        $ok=$wpdb->insert($table,$row);
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
        $payload=json_decode($job['payload'],true); $result=null; $error=''; $final=false;

        /**
         * Fires after a job is claimed and before any of its work runs.
         *
         * Cron has no current user, so this is the only moment a companion
         * plugin can learn whose job it is about to execute.
         *
         * @param array $job     Job row (includes user_id when the core plugin
         *                       has migrated the table).
         * @param array $payload Decoded job payload.
         */
        do_action('eaiw_job_before', $job, is_array($payload)?$payload:[]);

        try{
            // The whole switch is handed out as a callable so a companion
            // plugin can wrap execution — e.g. run it inside a tenant context
            // that restores itself in a finally block. Unfiltered, this is
            // exactly the same code path as before.
            $executor = function() use ($job, $payload) {
                switch($job['job_type']){
                    case 'agent': return EAIW_Agent_Manager::run_now(sanitize_key($payload['agent']??''));
                    case 'guardian': return EAIW_Guardian::scan();
                    case 'factory': return EAIW_Omnichannel_Factory::generate_full($payload);
                    case 'image': return EAIW_Flux_Client::generate($payload['prompt']??'', $payload['style']??'photorealistic', $payload['size']??'1024x1024');
                    case 'tts': return EAIW_TTS::synthesize($payload['text']??'', $payload['voice']??'alloy');
                    case 'video': return EAIW_Video_Studio_Pro::build($payload);
                    default: throw new Exception('نوع کار پشتیبانی نمی‌شود.');
                }
            };

            /**
             * Filters the callable that executes a claimed job.
             *
             * @param callable $executor Returns the job result, throws on failure.
             * @param array    $job      Job row.
             * @param array    $payload  Decoded job payload.
             */
            $executor = apply_filters('eaiw_job_executor', $executor, $job, is_array($payload)?$payload:[]);

            $result = is_callable($executor) ? $executor() : null;
            if(is_wp_error($result)) throw new Exception($result->get_error_message());
            $wpdb->update($table,['status'=>'completed','finished_at'=>current_time('mysql',true),'error_text'=>null,'result'=>wp_json_encode($result,JSON_UNESCAPED_UNICODE),'elapsed'=>round(microtime(true)-$started_at,3)],['id'=>$job['id']]); EAIW_Logger::log('Job completed',['trace_id'=>$job['trace_id'],'type'=>$job['job_type']],'success');
        }catch(Exception $e){$error=$e->getMessage();$attempt=(int)$job['attempts']+1;$final=$attempt>=3;$data=['status'=>$final?'failed':'pending','finished_at'=>$final?current_time('mysql',true):null,'started_at'=>null,'available_at'=>$final?null:gmdate('Y-m-d H:i:s',time()+(60*pow(5,$attempt-1))),'error_text'=>sanitize_text_field($error),'result'=>null,'elapsed'=>round(microtime(true)-$started_at,3)];$wpdb->update($table,$data,['id'=>$job['id']]);EAIW_Logger::log($final?'Job failed':'Job retry scheduled',['trace_id'=>$job['trace_id'],'type'=>$job['job_type'],'attempt'=>$attempt],$final?'error':'warning');}

        /**
         * Fires once a job attempt has finished, whichever way it ended.
         *
         * This is where money is settled: the customer was charged when the
         * job was queued, so a job that finally fails must be refunded and a
         * job that succeeded can be reconciled against what it really did.
         *
         * @param array  $job    Job row.
         * @param mixed  $result Handler result on success, null on failure.
         * @param string $error  Error text, empty on success.
         * @param bool   $final  Whether retries are exhausted (job is `failed`).
         */
        do_action('eaiw_job_result', $job, $result, $error, $final);

        return ['id'=>(int)$job['id'],'status'=>$error?'failed':'completed','trace_id'=>$job['trace_id']];
    }
    public static function recover_stale($minutes=15){
        global $wpdb; $table=$wpdb->prefix.'eaiw_jobs'; if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table))!==$table)return 0; $cutoff=gmdate('Y-m-d H:i:s',time()-max(5,(int)$minutes)*MINUTE_IN_SECONDS);
        $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE status='processing' AND started_at < %s",$cutoff),ARRAY_A); $count=0;
        foreach($rows as $row){$new_status=((int)$row['attempts']<3)?'pending':'failed';$error=$new_status==='failed'?'تعداد تلاش‌های مجاز تمام شد.':null;$wpdb->update($table,['status'=>$new_status,'started_at'=>null,'error_text'=>$error],['id'=>(int)$row['id']]);$count++;EAIW_Logger::log('Stale job recovered',['job_id'=>(int)$row['id'],'status'=>$new_status],$new_status==='failed'?'error':'warning');
            // A job abandoned mid-flight never reaches run_next()'s result
            // hook, so without this its charge would never be settled either.
            if($new_status==='failed'){do_action('eaiw_job_result',$row,null,(string)$error,true);}
        } return $count;
    }
    public static function work($limit=3){if(get_transient('eaiw_queue_worker_lock'))return [];set_transient('eaiw_queue_worker_lock',1,55);$done=[];try{self::recover_stale();for($i=0;$i<max(1,min(10,(int)$limit));$i++){ $r=self::run_next();if(!$r)break;$done[]=$r;}}finally{delete_transient('eaiw_queue_worker_lock');}return $done;}
}
