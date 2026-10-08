<?php defined('ABSPATH')||exit;
$report=EAIW_Health::report();
$ok=0; foreach($report as $item) if($item['ok']) $ok++;
$total=count($report); $percent=$total?(int)round(($ok/$total)*100):0;
$theme=get_option('eaiw_theme','dark');
$last_check=(int)get_option('eaiw_health_last_check',0);
$export_url=wp_nonce_url(admin_url('admin-post.php?action=eaiw_health_export'),'eaiw_health_export');
$health_actions=[
 'php'=>['label'=>'راهنمای ارتقا','url'=>admin_url('site-health.php')],
 'wordpress'=>['label'=>'بررسی وردپرس','url'=>admin_url('update-core.php')],
 'memory'=>['label'=>'راهنمای افزایش حافظه','url'=>admin_url('site-health.php')],
 'api'=>['label'=>'تنظیم کلید دسترسی','url'=>admin_url('admin.php?page=eaiw-settings')],
 'uploads'=>['label'=>'تنظیمات رسانه','url'=>admin_url('options-media.php')],
 'woocommerce'=>['label'=>'مدیریت افزونه‌ها','url'=>admin_url('plugins.php')],
 'vectors'=>['label'=>'بازسازی خرد سایت','url'=>admin_url('admin.php?page=eaiw-brain')],
 'agents'=>['label'=>'مدیریت یاران','url'=>admin_url('admin.php?page=eaiw-agents')],
 'https'=>['label'=>'راهنمای HTTPS','url'=>admin_url('site-health.php')],
];
?>
<div class="wrap eaiw-nebula-wrap <?php echo $theme==='light'?'eaiw-light':'';?>">
  <div class="eaiw-nebula-bg"></div>
  <div class="eaiw-topbar">
    <div class="eaiw-brand"><div class="eaiw-logo">✓</div><div><h1>مرکز سلامت اتحادیار</h1><p>وضعیت بخش‌های مهم افزونه را یک‌جا بررسی کنید.</p></div></div>
    <div class="eaiw-toolbar"><button type="button" id="eaiwHealthRefresh" class="eaiw-btn eaiw-btn-primary">بررسی دوباره</button><a href="<?php echo esc_url($export_url);?>" class="eaiw-btn eaiw-btn-ghost">دریافت گزارش</a><a href="<?php echo esc_url(admin_url('admin.php?page=eaiw-nebula'));?>" class="eaiw-btn eaiw-btn-ghost">بازگشت به پیشخوان</a></div>
  </div>
  <div class="eaiw-grid">
    <div class="eaiw-card eaiw-col-4 eaiw-health-summary">
      <h3>وضعیت کلی</h3>
      <p class="eaiw-health-last">آخرین بررسی: <?php echo $last_check ? esc_html(wp_date(get_option('date_format','Y-m-d').' H:i',$last_check)) : 'هنوز بررسی نشده است';?></p>
      <div class="eaiw-health-score"><?php echo (int)$percent;?><small>٪</small></div>
      <p><?php echo $ok===$total?'همه بررسی‌ها با موفقیت انجام شد.':sprintf('%d مورد از %d مورد آماده است.',$ok,$total);?></p>
      <div class="eaiw-progress"><i style="width:<?php echo $percent;?>%"></i></div>
    </div>
    <div class="eaiw-card eaiw-col-8">
      <h3>بررسی‌های انجام‌شده</h3>
      <div class="eaiw-health-list">
      <?php foreach($report as $key=>$item): ?>
        <div class="eaiw-health-item <?php echo $item['ok']?'is-ok':'is-warning';?>">
          <span class="eaiw-health-icon"><?php echo $item['ok']?'✓':'!';?></span>
          <div class="eaiw-health-copy"><strong><?php echo esc_html($item['label']);?></strong><small><?php echo esc_html($item['help']);?></small></div>
          <b><?php echo esc_html($item['value']);?></b>
          <?php if(!$item['ok'] && in_array($key,['cron','automation_cron','guardian_cron'],true)): ?><button type="button" class="eaiw-health-fix" data-fix="cron">ترمیم</button><?php elseif(!$item['ok'] && isset($health_actions[$key])): ?><a class="eaiw-health-fix" href="<?php echo esc_url($health_actions[$key]['url']);?>"><?php echo esc_html($health_actions[$key]['label']);?></a><?php endif;?>
        </div>
      <?php endforeach; ?>
      </div>
    </div>
    <div class="eaiw-card eaiw-col-12"><div class="eaiw-toolbar"><h3>آزمایش اتصال سرویس‌ها</h3><span class="eaiw-help">این آزمایش برای سرویس‌های انتخاب‌شده یک درخواست کوتاه ارسال می‌کند.</span></div><div class="eaiw-provider-tests"><div class="eaiw-provider-test"><strong>OpenAI</strong><button class="eaiw-btn eaiw-btn-ghost eaiw-test-provider" data-provider="openai">بررسی اتصال</button><span class="eaiw-test-result" data-provider-result="openai"></span></div><div class="eaiw-provider-test"><strong>GapGPT</strong><button class="eaiw-btn eaiw-btn-ghost eaiw-test-provider" data-provider="gapgpt">بررسی اتصال</button><span class="eaiw-test-result" data-provider-result="gapgpt"></span></div><div class="eaiw-provider-test"><strong>Gemini</strong><button class="eaiw-btn eaiw-btn-ghost eaiw-test-provider" data-provider="gemini">بررسی اتصال</button><span class="eaiw-test-result" data-provider-result="gemini"></span></div><div class="eaiw-provider-test"><strong>Claude</strong><button class="eaiw-btn eaiw-btn-ghost eaiw-test-provider" data-provider="claude">بررسی اتصال</button><span class="eaiw-test-result" data-provider-result="claude"></span></div><div class="eaiw-provider-test"><strong>تلگرام</strong><button class="eaiw-btn eaiw-btn-ghost" id="eaiwTestTelegram">بررسی اتصال</button><span class="eaiw-test-result" id="eaiwTelegramResult"></span></div></div><p class="eaiw-help">آزمایش هوش مصنوعی مقدار بسیار کمی از سهمیه سرویس را مصرف می‌کند.</p></div>
    <div class="eaiw-card eaiw-col-12 eaiw-health-tip">
      <strong>راهنما:</strong> موردهای زرد الزاماً مانع کار افزونه نیستند. برای نمونه، ووکامرس فقط زمانی لازم است که بخواهید از قابلیت‌های فروشگاهی استفاده کنید و HTTPS برای سایت‌های آزمایشی ممکن است هنوز فعال نشده باشد.
    </div>
  </div>
<script>
jQuery(function($){
  $('.eaiw-test-provider').on('click',function(){const b=$(this),p=b.data('provider'),o=$('[data-provider-result='+p+']');b.prop('disabled',true).text('در حال بررسی...');o.text('');$.post(EAIW.ajax,{action:'eaiw_ai_test',provider:p,_ajax_nonce:EAIW.nonce},function(r){b.prop('disabled',false).text('بررسی دوباره');o.text(r.success?'اتصال برقرار شد':'خطا: '+(r.data||'بررسی نشد')).attr('class','eaiw-test-result '+(r.success?'is-ok':'is-error'));}).fail(function(){b.prop('disabled',false).text('بررسی دوباره');o.text('ارتباط برقرار نشد').attr('class','eaiw-test-result is-error');});});
  $('#eaiwTestTelegram').on('click',function(){const b=$(this),o=$('#eaiwTelegramResult');b.prop('disabled',true).text('در حال بررسی...');$.post(EAIW.ajax,{action:'eaiw_social_test_telegram',_ajax_nonce:EAIW.nonce},function(r){b.prop('disabled',false).text('بررسی دوباره');o.text(r.success?'اتصال برقرار شد':'خطا: '+(r.data||'بررسی نشد')).attr('class','eaiw-test-result '+(r.success?'is-ok':'is-error'));}).fail(function(){b.prop('disabled',false).text('بررسی دوباره');o.text('ارتباط برقرار نشد').attr('class','eaiw-test-result is-error');});});
  $('.eaiw-health-fix[data-fix=cron]').on('click',function(){
    const b=$(this); b.prop('disabled',true).text('در حال ترمیم...');
    $.post(EAIW.ajax,{action:'eaiw_health_repair_cron',_ajax_nonce:EAIW.nonce},function(r){
      if(r.success){ window.alert(r.data.message); location.reload(); }
      else { b.prop('disabled',false).text('ترمیم'); window.alert(r.data||'ترمیم انجام نشد.'); }
    }).fail(function(){ b.prop('disabled',false).text('ترمیم'); window.alert('ارتباط با سامانه برقرار نشد.'); });
  });
  $('#eaiwHealthRefresh').on('click',function(){
    const b=$(this); b.prop('disabled',true).text('در حال بررسی...');
    $.post(EAIW.ajax,{action:'eaiw_health_check',_ajax_nonce:EAIW.nonce},function(r){
      if(r.success){ location.reload(); }
      else { b.prop('disabled',false).text('بررسی دوباره'); window.alert(r.data||'بررسی انجام نشد.'); }
    }).fail(function(){ b.prop('disabled',false).text('بررسی دوباره'); window.alert('ارتباط با سامانه برقرار نشد.'); });
  });
});
</script>
</div>
