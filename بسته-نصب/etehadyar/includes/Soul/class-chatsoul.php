<?php
defined('ABSPATH') || exit;
/**
 * ChatSoul 6.6 — باهوش، بامزه، فان + FAQ + GapGPT + شخصیت
 */
class EAIW_ChatSoul {
    public static function faqs(){
        $faqs=get_option('eaiw_chat_faqs',[]);
        if(!is_array($faqs)) $faqs=[];
        return $faqs;
    }
    public static function save_faqs($faqs){
        update_option('eaiw_chat_faqs', array_values(array_filter($faqs, fn($f)=>!empty($f['q']) && !empty($f['a']))), false);
    }

    public static function rest_chat($request){
        // این Endpoint عمومی است، بنابراین Nonce کافی نیست؛ مصرف API را محدود کن.
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown';
        $bucket = 'eaiw_chat_rate_' . md5($ip);
        $hits = (int) get_transient($bucket);
        if ($hits >= 20) {
            return new WP_Error('rate_limited', 'تعداد درخواست‌ها زیاد است؛ لطفاً یک دقیقه بعد دوباره امتحان کنید.', ['status'=>429]);
        }
        set_transient($bucket, $hits + 1, MINUTE_IN_SECONDS);

        $msg = sanitize_textarea_field($request->get_param('message') ?? $request->get_param('q') ?? '');
        if (mb_strlen($msg) > 2000) {
            return new WP_Error('message_too_long', 'متن پیام نباید بیشتر از ۲۰۰۰ کاراکتر باشد.', ['status'=>400]);
        }
        if (!$msg) return new WP_Error('empty','پیام خالی',['status'=>400]);
        $soul_name = get_option('eaiw_chatsoul_name','اتحادیار');
        if (!$soul_name) $soul_name='اتحادیار';
        // شخصیت پشتیبان: دقیق، گرم و مسئولانه؛ بدون حدس‌زدن یا اغراق.
        $personality = "تو {$soul_name}، پشتیبان هوشمند سایت ".get_bloginfo('name')." هستی. لحن تو گرم، محترمانه، روشن و حرفه‌ای است. پاسخ را فقط بر اساس اطلاعات معتبر سایت و متن کاربر بده. اگر پاسخ را می‌دانی، مستقیم و مطمئن جواب بده. اگر اطلاعات کافی نداری، فقط بگو «برای پاسخ دقیق به این مورد اطلاعات کافی ندارم» و راه ارتباط با کارشناس را پیشنهاد کن. هرگز قیمت، موجودی، وضعیت سفارش یا قانون سایت را حدس نزن.";
        // FAQ اول
        $faqs=self::faqs();
        $faq_hit=null;
        foreach($faqs as $f){
            if(mb_stripos($msg, mb_substr($f['q'],0,12))!==false || similar_text(mb_strtolower($msg), mb_strtolower($f['q'])) > 60){
                $faq_hit=$f;
                break;
            }
            // کلیدواژه
            $keys=explode(' ', $f['q']);
            $match=0; foreach($keys as $k) if(mb_strlen($k)>2 && mb_stripos($msg,$k)!==false) $match++;
            if($match>=2) { $faq_hit=$f; break; }
        }
        if($faq_hit){
            $answer="😎 سوال خوبیه!\n\n".$faq_hit['a']."\n\n— {$soul_name} ✨";
            $sources=[['title'=>$faq_hit['q'],'url'=>'','snippet'=>'FAQ']];
            self::log($request,$msg,$answer,$sources);
            return rest_ensure_response(['answer'=>$answer,'sources'=>$sources,'session_id'=>sanitize_text_field($request->get_param('session_id')?:'faq'),'name'=>$soul_name,'type'=>'faq','confidence'=>1.0,'needs_expert'=>false]);
        }

        $has_ai_key=(bool)EAIW_Vault::get_key('gapgpt') || (bool)EAIW_Vault::get_key('openai') || (bool)EAIW_Vault::get_key('gemini') || (bool)EAIW_Vault::get_key('claude');
        if(!$has_ai_key){$answer=self::simple_answer($msg,$soul_name);return rest_ensure_response(['answer'=>$answer,'sources'=>[],'session_id'=>sanitize_text_field($request->get_param('session_id')?:'guest'),'name'=>$soul_name,'type'=>'direct','confidence'=>0.8,'needs_expert'=>false]);}

        // RAG
        $use_site_context=(bool)get_option('eaiw_chatsoul_use_site_context',0); $ctx=''; if($use_site_context){try{$ctx=EAIW_RAG::context_for_prompt($msg,3);}catch(Throwable $e){EAIW_Logger::log('RAG failed',['code'=>'rag_error'],'error');}}
        $has_ctx = trim($ctx) !== '';
        // اگر سوال عمومی و بی‌ربط به سایت (سلام، چطوری، جوک...) → GapGPT مستقیم
        $is_smalltalk = preg_match('/^(سلام|درود|هی|هلو|چطوری|خوبی|جوک|لطیفه|شوخی|بگو|help)/iu', trim($msg)) || mb_strlen($msg)<12;
        $system = $personality . "\n";
        if($has_ctx) $system .= "دانش سایت:\n$ctx\n\nاز این دانش استفاده کن، اگر ربط نداشت نادیده بگیر.\n";
        $system .= "قواعد پاسخ‌گویی: ۱) فقط فارسی روان بنویس. ۲) پاسخ را مستقیم و کوتاه، معمولاً در ۳ تا ۶ جمله بده. ۳) پاسخ را با جمله اصلی شروع کن و حاشیه نرو. از حدس‌زدن خودداری کن، اما درباره اطلاعات معتبر با لحن روشن و قطعی بنویس. ۴) اگر پاسخ بر اساس دانش سایت است، به همان منبع تکیه کن. ۵) برای چند مرحله از فهرست شماره‌دار استفاده کن. ۶) از اصطلاح فنی، شوخی و شکلک غیرضروری استفاده نکن. ۷) اگر پاسخ قطعی نیست، کاربر را به کارشناس ارجاع بده.";

        // اول GapGPT / OpenAI را امتحان کن (برای صحبت عادی)
        // پاسخ اصلی از سرویس هوش مصنوعی؛ دانش سایت فقط در صورت فعال‌بودن گزینه مربوط وارد می‌شود.
        $answer='';
        try{$real=EAIW_AI_Client::complete($msg,$system,['provider'=>get_option('eaiw_chatsoul_provider',''),'temperature'=>0.45,'max_tokens'=>500]);}catch(Throwable $e){EAIW_Logger::log('AI chat failed',['code'=>'provider_exception'],'error');$real=new WP_Error('provider_exception','');}
        if(!is_wp_error($real) && $real) $answer=trim($real);
        if(!$answer){
            if($has_ctx){
                $answer="برای این پرسش، اطلاعات مرتبطی در سایت پیدا کردم:\n\n".wp_trim_words(strip_tags($ctx), 55)."\n\nاگر پاسخ دقیق‌تری می‌خواهید، متن کامل صفحه مرتبط را ببینید.";
            } else {
                $answer=self::simple_answer($msg,$soul_name);
            }
        }
        $sources=array_values(array_filter(EAIW_RAG::search($msg,3),function($h)use($msg){$words=preg_split('/\s+/u',mb_strtolower($msg));$text=mb_strtolower(($h['title']??'').' '.($h['snippet']??''));foreach($words as $w)if(mb_strlen($w)>3&&mb_stripos($text,$w)!==false)return true;return false;}));
        $confidence=$has_ctx?0.85:($is_smalltalk?0.75:0.25); $needs_expert=$confidence<0.5;
        if($needs_expert && stripos($answer,'کارشناس')===false) $answer.="\n\nاگر پاسخ کافی نیست، می‌توانید درخواست بررسی کارشناس را ثبت کنید.";
        self::log($request,$msg,$answer,$sources);
        return rest_ensure_response(['answer'=>$answer,'sources'=>$sources,'session_id'=>sanitize_text_field($request->get_param('session_id')?:'guest'),'name'=>$soul_name,'type'=>$has_ctx?'rag':'gapgpt','confidence'=>$confidence,'needs_expert'=>$needs_expert]);
    }

    private static function simple_answer($msg,$name){
        if(preg_match('/وردپرس چیست|wordpress چیست/iu',$msg)) return "وردپرس یک سامانهٔ مدیریت محتواست که با آن می‌توانید بدون نیاز به کدنویسی، سایت، وبلاگ یا فروشگاه اینترنتی بسازید و محتوای آن را مدیریت کنید.";
        if(preg_match('/جوک|لطیفه/iu',$msg)) return "حتماً 😄\n\nبرنامه‌نویس چرا قهوه‌اش را سرد نمی‌خورد؟ چون همیشه منتظر می‌ماند کدش کامپایل شود!";
        if(preg_match('/^(سلام|درود|هی|خوبی|چطوری)/iu',trim($msg))) return "سلام! خوش آمدید. چه کمکی از دستم برمی‌آید؟";
        return "برای این پرسش هنوز پاسخ مطمئنی در دانش سایت ندارم. می‌توانید درخواست خود را برای بررسی کارشناس ارسال کنید.";
    }

    private static function log($request,$msg,$answer,$sources){
        global $wpdb;
        $t=$wpdb->prefix.'eaiw_chatsoul_logs';
        $sid=sanitize_text_field($request->get_param('session_id') ?: 'guest-'.substr(md5(($_SERVER['REMOTE_ADDR']??'0').($_SERVER['HTTP_USER_AGENT']??'')),0,8));
        if($wpdb->get_var("SHOW TABLES LIKE '$t'")==$t){
            $wpdb->insert($t,['session_id'=>$sid,'role'=>'user','message'=>$msg]);
            $wpdb->insert($t,['session_id'=>$sid,'role'=>'assistant','message'=>$answer,'sources'=>wp_json_encode($sources, JSON_UNESCAPED_UNICODE)]);
        }
    }

    private static function call_llm($system,$user,$key){
        $is_gap = (strpos($key,'gapgpt')!==false) || EAIW_Vault::get_key('gapgpt')===$key;
        $endpoint=$is_gap ? 'https://api.gapgpt.app/api/v1/chat/completions' : 'https://api.openai.com/v1/chat/completions';
        $model=$is_gap ? 'gpt-4o-mini' : 'gpt-4o-mini';
        // شخصیت فان را هم به system اضافه کردیم
        $resp=wp_remote_post($endpoint, [
            'headers'=>['Authorization'=>'Bearer '.$key,'Content-Type'=>'application/json'],
            'body'=>wp_json_encode([
                'model'=>$model,
                'messages'=>[['role'=>'system','content'=>$system],['role'=>'user','content'=>$user]],
                'temperature'=>0.85, // کمی فان
                'max_tokens'=>600,
            ], JSON_UNESCAPED_UNICODE),
            'timeout'=>28,
        ]);
        if(is_wp_error($resp)) return $resp;
        $code=wp_remote_retrieve_response_code($resp);
        $data=json_decode(wp_remote_retrieve_body($resp), true);
        if($code!==200) return new WP_Error('api_error', $data['error']['message'] ?? "خطای $code");
        $text=$data['choices'][0]['message']['content'] ?? '';
        return $text ? trim($text) : new WP_Error('empty','پاسخ خالی');
    }
}
