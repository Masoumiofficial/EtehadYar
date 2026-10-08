<?php defined('ABSPATH')||exit;
$theme = get_option('eaiw_theme','dark');
$has_key = false;
if(class_exists('EAIW_Vault')){
  $has_key = EAIW_Vault::get_key('openai') || EAIW_Vault::get_key('gapgpt') || EAIW_Vault::get_key('gemini') || EAIW_Vault::get_key('claude');
}
// Brand voice
$brand_voice = get_option('eaiw_brand_voice', '');
?>
<div class="wrap eaiw-nebula-wrap <?php echo $theme==='light'?'eaiw-light':'';?>">
<div class="eaiw-nebula-bg"></div>
<div class="eaiw-topbar">
  <div class="eaiw-brand"><div class="eaiw-logo">🏭</div><div><h1>کارگاه محتوا — اتحادیار 6.8 <span style="background:linear-gradient(90deg,#ff2e97,#ff8a00); -webkit-background-clip:text; -webkit-text-fill-color:transparent; font-size:.75rem; border:1px solid #ff2e9755; padding:2px 6px; border-radius:999px">حرفه‌ای</span></h1><p>یک موضوع وارد کنید، 7 خروجی واقعی دریافت کنید + پیش‌نمایش زنده + امتیاز سئو</p></div></div>
  <a href="<?php echo admin_url('admin.php?page=eaiw-nebula');?>" class="eaiw-btn eaiw-btn-ghost">← اتاق فرمان</a>
</div>

<?php if(!$has_key): ?>
<div class="eaiw-card" style="background:rgba(245,158,11,.09); border-color:rgba(245,158,11,.25); display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px">
  <div><b style="color:#f59e0b">کلید تنظیم نشده است — کارگاه نمایشی می‌مونه</b><br><span style="font-size:.85rem; color:var(--nebula-muted)">یک کلید GapGPT/OpenAI وارد کن تا مقاله + تصویر واقعی بسازی</span></div>
  <a href="<?php echo admin_url('admin.php?page=eaiw-settings');?>" class="eaiw-btn eaiw-btn-primary" style="padding:7px 12px; font-size:.82rem">تنظیمات →</a>
</div>
<?php endif; ?>

<div class="eaiw-grid">
  <!-- Left: Input -->
  <div class="eaiw-card eaiw-col-5">
    <h3><i>📝</i> چی بسازیم؟</h3>
    <label style="font-size:.85rem; color:var(--nebula-muted); font-weight:700">از سایت انتخاب کن (اختیاری):</label>
    <select class="eaiw-select" id="eaiwFactoryPost" style="margin-top:6px">
      <option value="">— موضوع جدید —</option>
      <?php 
        $posts = [];
        try{ $posts=get_posts(['posts_per_page'=>15,'post_status'=>'publish','orderby'=>'modified','order'=>'DESC']); }catch(Exception $e){}
        foreach($posts as $p): ?>
        <option value="<?php echo (int)$p->ID;?>"><?php echo esc_html(mb_strimwidth(get_the_title($p) ?: 'بدون عنوان',0,45,'…'));?></option>
      <?php endforeach; ?>
    </select>

    <label class="eaiw-field-label">موضوع <span style="color:#ef4444">*</span>:</label>
    <textarea class="eaiw-textarea" id="eaiwFactoryPrompt" rows="2" placeholder="مثلاً: راهنمای کامل خرید قهوه اسپرسو — از دانه تا دم‌آوری"><?php echo isset($_GET['prompt'])?esc_textarea($_GET['prompt']):'';?></textarea>
    
    <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-top:10px">
      <div><label style="font-size:.80rem; color:var(--nebula-muted); font-weight:700">لحن</label>
        <select class="eaiw-select" id="eaiwFactoryTone">
          <option value="حرفه‌ای و صمیمی">حرفه‌ای و صمیمی</option>
          <option value="رسمی و آموزشی">رسمی و آموزشی</option>
          <option value="خودمونی و جذاب">خودمونی و جذاب</option>
          <option value="فروشنده و متقاعدکننده">فروشنده</option>
        </select></div>
      <div><label style="font-size:.80rem; color:var(--nebula-muted); font-weight:700">طول</label>
        <select class="eaiw-select" id="eaiwFactoryLen">
          <option value="800">کوتاه 800</option>
          <option value="1200" selected>استاندارد 1200</option>
          <option value="2000">بلند 2000</option>
        </select></div>
    </div>

    <div style="margin-top:10px">
      <label style="font-size:.80rem; color:var(--nebula-muted); font-weight:700; display:flex; justify-content:space-between; align-items:center">هوش مصنوعی <span style="font-size:.70rem; color:#22c55e">پیشنهاد: GapGPT برای ایران</span></label>
      <select class="eaiw-select" id="eaiwFactoryProvider" style="margin-top:4px">
        <option value="">خودکار — بهترین کلید فعال</option>
        <?php
          $provs = class_exists('EAIW_AI_Client') ? EAIW_AI_Client::providers() : [];
          foreach($provs as $k=>$info){
            $has=$info['has_key'];
            $label=$info['label'];
            $rec=$info['recommended']?' ⭐ پیشنهادی':'';
            $dis=$has?'':' (کلید ندارد)';
            echo '<option value="'.esc_attr($k).'" '.(!$has?'disabled':'').'>'.esc_html($label.$rec.$dis).'</option>';
          }
        ?>
      </select>
      <div style="font-size:.70rem; color:var(--nebula-muted); margin-top:4px">اگر OpenAI اعتبار نداشت، خودکار با GapGPT تولید می‌کند — GapGPT ارزان و بدون تحریم</div>
    </div>

    <div style="margin-top:10px; background:var(--nebula-input-bg); border:1px solid var(--nebula-border); border-radius:10px; padding:10px">
      <b style="font-size:.82rem">✨ ارتقای حرفه‌ای 6.7:</b>
      <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-top:8px">
        <label style="display:flex; gap:6px; align-items:center; font-size:.82rem"><input type="checkbox" id="eaiwFactorySeo" checked> پیش‌نمایش سئو زنده</label>
        <label style="display:flex; gap:6px; align-items:center; font-size:.82rem"><input type="checkbox" id="eaiwFactoryBrand" <?php checked(!empty($brand_voice));?>> لحن برند</label>
      </div>
      <div id="eaiwSeoPreview" style="margin-top:8px; background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.06); border-radius:8px; padding:8px; font-size:.78rem; display:none">
        <div style="color:#1a0dab; font-size:.85rem; font-weight:700" id="eaiwSeoTitle">عنوان سئو — اینجا پیش‌نمایش میاد</div>
        <div style="color:#006621; font-size:.75rem" id="eaiwSeoUrl"><?php echo esc_html(home_url('/'));?>...</div>
        <div style="color:#545454; font-size:.78rem" id="eaiwSeoDesc">توضیحات متا — 155 کاراکتر</div>
      </div>
    </div>

    <label style="display:flex; gap:8px; align-items:center; margin-top:10px; font-size:.85rem; background:var(--nebula-input-bg); border:1px solid var(--nebula-border); border-radius:10px; padding:8px 10px">
      <input type="checkbox" id="eaiwFactoryDraft" checked> ذخیره پیش‌نویس با تصویر شاخص
    </label>
    <button class="eaiw-btn eaiw-btn-primary" id="eaiwFactoryGen" style="margin-top:12px; width:100%; justify-content:center; padding:13px">🏭 تولید محتوا</button>
    <div id="eaiwFactoryProgress" style="display:none; margin-top:12px">
      <div class="eaiw-progress"><i id="eaiwFactoryBar" style="width:0%"></i></div>
      <div style="font-size:.82rem; color:var(--nebula-muted); margin-top:6px; text-align:center" id="eaiwFactoryStatus">آماده است</div>
    </div>
    <div id="eaiwFactoryError" style="display:none; margin-top:10px; background:rgba(239,68,68,.08); border:1px solid #ef444433; border-radius:10px; padding:10px; font-size:.85rem; color:#FECACA"></div>
  </div>

  <!-- Right: Result -->
  <div class="eaiw-card eaiw-col-7">
    <h3><i>📦</i> نتیجه — 7 خروجی (بعد از ساخت)</h3>
    <div id="eaiwFactoryEmpty" style="text-align:center; padding:32px 0; color:var(--nebula-muted); background:var(--nebula-input-bg); border:1px dashed var(--nebula-border); border-radius:12px">
      <div style="font-size:2rem">🏭</div>
      موضوع را وارد کنید و «ساخت» را انتخاب کنید<br>
      <span style="font-size:.82rem">مقاله + 3 تصویر + ویدئو + پادکست + اینستاگرام + ایمیل + پیام کوتاه — همه واقعی</span>
    </div>
    <div id="eaiwFactoryResult" style="display:none">
      <div style="display:flex; gap:6px; flex-wrap:wrap; margin-bottom:12px; border-bottom:1px solid var(--nebula-border); padding-bottom:10px">
        <button class="eaiw-btn eaiw-btn-primary eaiw-tab" data-tab="article" style="padding:6px 10px; font-size:.80rem">📄 مقاله</button>
        <button class="eaiw-btn eaiw-btn-ghost eaiw-tab" data-tab="images" style="padding:6px 10px; font-size:.80rem">🎨 تصاویر</button>
        <button class="eaiw-btn eaiw-btn-ghost eaiw-tab" data-tab="video" style="padding:6px 10px; font-size:.80rem">🎬 ویدئو</button>
        <button class="eaiw-btn eaiw-btn-ghost eaiw-tab" data-tab="podcast" style="padding:6px 10px; font-size:.80rem">🎙️ پادکست</button>
        <button class="eaiw-btn eaiw-btn-ghost eaiw-tab" data-tab="social" style="padding:6px 10px; font-size:.80rem">📸 شبکه</button>
      </div>
      <div id="tab-article" class="eaiw-tabpane">
        <div style="display:flex; gap:8px; margin-bottom:8px; flex-wrap:wrap">
          <a id="eaiwDraftLink" href="#" target="_blank" class="eaiw-btn eaiw-btn-primary" style="padding:6px 10px; font-size:.78rem; display:none">✏️ ویرایش پیش‌نویس</a>
          <button class="eaiw-btn eaiw-btn-ghost" id="eaiwCopyArticle" style="padding:6px 10px; font-size:.78rem">📋 کپی HTML</button>
          <span id="eaiwSeoScore" class="eaiw-badge" style="display:none">سئو: —</span>
        </div>
        <div id="eaiwArticleHtml" style="background:var(--nebula-input-bg); border:1px solid var(--nebula-border); border-radius:12px; padding:14px; max-height:360px; overflow:auto; font-size:.88rem; line-height:1.8"></div>
        <div style="margin-top:8px; font-size:.78rem; color:var(--nebula-muted)" id="eaiwArticleMeta"></div>
      </div>
      <div id="tab-images" class="eaiw-tabpane" style="display:none">
        <div id="eaiwImagesGrid" style="display:grid; grid-template-columns:repeat(3,1fr); gap:8px"></div>
      </div>
      <div id="tab-video" class="eaiw-tabpane" style="display:none">
        <div id="eaiwVideoScript" style="background:var(--nebula-input-bg); border:1px solid var(--nebula-border); border-radius:12px; padding:12px; font-size:.85rem"></div>
        <a href="<?php echo admin_url('admin.php?page=eaiw-video');?>" class="eaiw-btn eaiw-btn-ghost" style="margin-top:8px; padding:6px 10px; font-size:.78rem">🎬 بردن به ویدئو ساز →</a>
      </div>
      <div id="tab-podcast" class="eaiw-tabpane" style="display:none">
        <div id="eaiwPodcastText" style="background:var(--nebula-input-bg); border:1px solid var(--nebula-border); border-radius:12px; padding:12px; font-size:.85rem; max-height:180px; overflow:auto"></div>
        <button class="eaiw-btn eaiw-btn-primary" id="eaiwMakeAudio" style="margin-top:8px; padding:7px 12px; font-size:.82rem">🎙️ تبدیل به MP3</button>
        <div id="eaiwAudioResult" style="margin-top:8px"></div>
      </div>
      <div id="tab-social" class="eaiw-tabpane" style="display:none">
        <div id="eaiwCarousel" style="background:var(--nebula-input-bg); border:1px solid var(--nebula-border); border-radius:12px; padding:12px; font-size:.85rem"></div>
        <div style="margin-top:8px; display:grid; grid-template-columns:1fr 1fr; gap:8px">
          <button class="eaiw-btn eaiw-btn-primary" id="eaiwPublishInsta" style="padding:7px 10px; font-size:.80rem">📸 اینستاگرامگرام / دریافت بسته</button>
          <button class="eaiw-btn eaiw-btn-primary" id="eaiwPublishTg" style="padding:7px 10px; font-size:.80rem; background:linear-gradient(90deg,#229ED9,#1d8cc2)">✈️ تلگرام</button>
        </div>
        <div id="eaiwSocialResult" style="margin-top:8px; font-size:.82rem"></div>
        <div id="eaiwTweet" style="margin-top:8px; background:var(--nebula-input-bg); border:1px solid var(--nebula-border); border-radius:8px; padding:8px; font-size:.85rem"></div>
      </div>
    </div>
  </div>
</div>
</div>

<script>
jQuery(function($){
  function toast(m,ok=true){
    const t=$(`<div style="position:fixed; bottom:20px; right:20px; z-index:9999; background:${ok?'#10b981':'#ef4444'}; color:white; padding:10px 14px; border-radius:12px; font-size:.9rem">${m}</div>`);
    $('body').append(t); setTimeout(()=> t.fadeOut(400, ()=>t.remove()), 3000);
  }
  // SEO live preview
  $('#eaiwFactoryPrompt').on('input', function(){
    const v=$(this).val().trim();
    if(!v){ $('#eaiwSeoPreview').hide(); return; }
    $('#eaiwSeoPreview').show();
    $('#eaiwSeoTitle').text(v.slice(0,60) + ' | اتحادیار');
    $('#eaiwSeoDesc').text(v.slice(0,155) + ' — ادامه در سایت...');
    $('#eaiwSeoUrl').text(location.origin + '/' + v.replace(/\s+/g,'-').slice(0,30));
  });
  $(document).on('click','.eaiw-tab', function(){
    const tab=$(this).data('tab');
    $('.eaiw-tab').removeClass('eaiw-btn-primary').addClass('eaiw-btn-ghost');
    $(this).removeClass('eaiw-btn-ghost').addClass('eaiw-btn-primary');
    $('.eaiw-tabpane').hide(); $('#tab-'+tab).show();
  });
  let lastRes=null;
  $('#eaiwFactoryGen').on('click', function(){
    const btn=$(this); const prompt=$('#eaiwFactoryPrompt').val().trim(); const post_id=$('#eaiwFactoryPost').val();
    if(!prompt && !post_id){ $('#eaiwFactoryError').text('موضوع را وارد کنید').show(); return toast('موضوع را وارد کنید',false); }
    $('#eaiwFactoryError').hide(); btn.prop('disabled',true).text('در حال آماده‌سازی محتوا...');
    $('#eaiwFactoryProgress').show(); $('#eaiwFactoryBar').css('width','18%');
    $('#eaiwFactoryStatus').text('در حال آماده‌سازی مقاله...');
    let prog=setInterval(()=>{ let w=parseInt($('#eaiwFactoryBar').css('width'))||18; if(w<88) $('#eaiwFactoryBar').css('width',(w+7)+'%'); },1800);
  function renderFactoryResult(data){
    lastRes=data; try{localStorage.setItem('eaiw_factory_last',JSON.stringify(lastRes));}catch(e){}
    $('#eaiwFactoryEmpty').hide(); $('#eaiwFactoryResult').show(); $('#eaiwArticleHtml').html(lastRes.article.html||''); $('#eaiwArticleMeta').text('کلمات: '+(lastRes.article.words||'—'));
    if(lastRes.article.seo_score) $('#eaiwSeoScore').text('سئو: '+lastRes.article.seo_score+'/100').show(); if(lastRes.draft_id) $('#eaiwDraftLink').attr('href',lastRes.draft_url).show(); else $('#eaiwDraftLink').hide();
    const grid=$('#eaiwImagesGrid'); grid.empty(); (lastRes.images||[]).forEach(img=>grid.append(`<div><img src="${img.url}" style="width:100%; aspect-ratio:16/9; object-fit:cover"><div>${img.mode||'موقت'}</div></div>`)); let vhtml=''; (lastRes.video||[]).forEach(x=>vhtml+=`<div><b>${x.start||''}–${x.end||''}</b> ${x.vo||''}</div>`); $('#eaiwVideoScript').html(vhtml); $('#eaiwPodcastText').text(lastRes.podcast.text||''); let ch=''; (lastRes.carousel||[]).forEach((c,i)=>ch+=`<div>${i+1}. ${c}</div>`); $('#eaiwCarousel').html(ch); $('#eaiwTweet').text(lastRes.tweet||''); toast('محتوا با موفقیت آماده شد.'); $('.eaiw-tab').removeClass('eaiw-btn-primary').addClass('eaiw-btn-ghost'); $('.eaiw-tab[data-tab="article"]').removeClass('eaiw-btn-ghost').addClass('eaiw-btn-primary'); $('.eaiw-tabpane').hide(); $('#tab-article').show();
  }
    $.post(EAIW.ajax, {action:'eaiw_factory_generate', prompt:prompt, post_id:post_id, tone:$('#eaiwFactoryTone').val(), length:$('#eaiwFactoryLen').val(), provider:$('#eaiwFactoryProvider').val(), save_draft:$('#eaiwFactoryDraft').is(':checked')?1:0, background:1, _ajax_nonce:EAIW.nonce}, res=>{
      clearInterval(prog); $('#eaiwFactoryBar').css('width','100%'); btn.prop('disabled',false).text('🏭 تولید محتوا');
      setTimeout(()=> $('#eaiwFactoryProgress').hide(),1000);
      if(!res.success){ $('#eaiwFactoryError').text(res.data||'خطا').show(); return toast(res.data,false); }
      if(!res.success){ $('#eaiwFactoryError').text(res.data||'خطا').show(); return toast(res.data,false); }
      if(res.data.job_id){ $('#eaiwFactoryStatus').text('کار در صف قرار گرفت؛ در حال آماده‌سازی...'); const jobId=res.data.job_id; let tries=0; const poll=setInterval(function(){ if(++tries>180){clearInterval(poll);clearInterval(prog);btn.prop('disabled',false).text('تولید محتوا');$('#eaiwFactoryError').text('زمان آماده‌سازی طولانی شد؛ نتیجه را از صفحه صف کارها پیگیری کنید.').show();return;} $.post(EAIW.ajax,{action:'eaiw_job_status',id:jobId,_ajax_nonce:EAIW.nonce},function(st){if(!st.success)return;if(st.data.status==='completed'){clearInterval(poll);clearInterval(prog);$('#eaiwFactoryBar').css('width','100%');btn.prop('disabled',false).text('تولید محتوا');renderFactoryResult(st.data.result||{});}if(st.data.status==='failed'){clearInterval(poll);clearInterval(prog);btn.prop('disabled',false).text('تولید محتوا');$('#eaiwFactoryError').text(st.data.error_text||'آماده‌سازی محتوا ناموفق بود.').show();}});},2000); return; }
      renderFactoryResult(res.data);
    }).fail(xhr=>{
      clearInterval(prog); btn.prop('disabled',false).text('🏭 تولید محتوا');
      let msg='خطای ارتباط'; if(xhr.responseJSON&&xhr.responseJSON.data) msg=xhr.responseJSON.data;
      $('#eaiwFactoryError').text(msg).show(); toast(msg,false);
    });
  });
  $('#eaiwCopyArticle').on('click', ()=>{ const h=$('#eaiwArticleHtml').html(); if(!h) return toast('چیزی نیست',false); navigator.clipboard.writeText(h).then(()=>toast('کپی شد')); });
  $('#eaiwMakeAudio').on('click', function(){
    const btn=$(this); const txt=$('#eaiwPodcastText').text().trim(); if(!txt) return toast('متنی نیست',false);
    btn.prop('disabled',true).text('...');
    $.post(EAIW.ajax,{action:'eaiw_tts_generate', text:txt, background:1, _ajax_nonce:EAIW.nonce}, res=>{
      btn.prop('disabled',false).text('🎙️ تبدیل به MP3');
      if(res.success && res.data.job_id){ $('#eaiwAudioResult').text('صدا در صف آماده‌سازی است...'); return EAIWPollJob(res.data.job_id,function(data){$('#eaiwAudioResult').html(`<audio controls src="${data.url}" style="width:100%; margin-top:8px"></audio>`);},function(msg){toast(msg,false);});}
      if(res.success) $('#eaiwAudioResult').html(`<audio controls src="${res.data.url}" style="width:100%; margin-top:8px"></audio><a href="${res.data.url}" target="_blank" style="font-size:.78rem; color:#22d3ee">⬇️ دانلود</a>`);
      else toast(res.data,false);
    });
  });
  $('#eaiwPublishTg').on('click', function(){
    if(!lastRes) return toast('اول بساز',false);
    const btn=$(this); btn.prop('disabled',true).text('...');
    $.post(EAIW.ajax,{action:'eaiw_factory_publish_telegram', text:lastRes.title+"\n\n"+lastRes.hashtags, image:lastRes.images[0]?lastRes.images[0].url:'', _ajax_nonce:EAIW.nonce}, res=>{
      btn.prop('disabled',false).text('✈️ تلگرام');
      if(res.success) toast('ارسال شد'); else toast(res.data,false);
      $('#eaiwSocialResult').html(res.success?'<span class="eaiw-badge green">ارسال شد</span>':'<span class="eaiw-badge red">'+res.data+'</span>');
    });
  });
  $('#eaiwPublishInsta').on('click', function(){
    if(!lastRes) return toast('اول بساز',false);
    const btn=$(this); btn.prop('disabled',true).text('...');
    $.post(EAIW.ajax,{action:'eaiw_factory_publish_instagram', caption:lastRes.title+"\n\n"+lastRes.hashtags, images:lastRes.images.map(x=>x.url), _ajax_nonce:EAIW.nonce}, res=>{
      btn.prop('disabled',false).text('📸 اینستاگرامگرام / دریافت بسته');
      if(res.success && res.data.url) $('#eaiwSocialResult').html(`<a href="${res.data.url}" target="_blank" class="eaiw-btn eaiw-btn-primary" style="padding:6px 10px; font-size:.78rem">⬇️ ZIP</a>`);
      else if(res.success) toast('منتشر شد');
      else toast(res.data,false);
    });
  });
});
</script>
