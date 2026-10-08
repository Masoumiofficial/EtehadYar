<?php defined('ABSPATH')||exit;
$theme=get_option('eaiw_theme','dark');
$data   = EAIW_Oracle::insights();
$source = $data['source'] ?? 'unavailable';
$rows   = $data['rows'] ?? [];
$notice = $data['notice'] ?? '';
$err    = $data['error'] ?? '';
$audit  = ('search_console' === $source) ? [] : EAIW_Oracle::content_audit();
$settings_url = admin_url('admin.php?page=etehadyar-analytics');
?>
<div class="wrap eaiw-nebula-wrap <?php echo $theme==='light'?'eaiw-light':'';?>">
<div class="eaiw-nebula-bg"></div>
<div class="eaiw-topbar">
  <div class="eaiw-brand"><div class="eaiw-logo">📊</div><div><h1>عملکرد سئو</h1><p>آمار واقعی از گوگل سرچ کنسول — بدون حدس و بدون عدد ساختگی</p></div></div>
  <a href="<?php echo admin_url('admin.php?page=eaiw-nebula');?>" class="eaiw-btn eaiw-btn-ghost">← اتاق فرمان</a>
</div>
<div class="eaiw-grid">

<?php if ($source === 'search_console' && $rows): ?>
  <div class="eaiw-card eaiw-col-12">
    <h3><i>✅</i> عملکرد ۲۸ روز گذشته — داده واقعی گوگل</h3>
    <table class="eaiw-table">
      <tr><th>صفحه</th><th>کلیک</th><th>نمایش</th><th>نرخ کلیک</th><th>جایگاه</th><th>وضعیت</th><th>پیشنهاد</th></tr>
      <?php foreach($rows as $r): ?>
      <tr>
        <td><a href="<?php echo esc_url($r['url']);?>" target="_blank" rel="noreferrer" style="color:#6d28ff; font-weight:700"><?php echo esc_html(mb_substr($r['title'],0,36));?></a></td>
        <td><?php echo esc_html(number_format_i18n($r['clicks']));?></td>
        <td><?php echo esc_html(number_format_i18n($r['impressions']));?></td>
        <td><?php echo esc_html($r['ctr']);?>%</td>
        <td><?php echo esc_html($r['position']);?></td>
        <td><span class="eaiw-badge <?php echo $r['risk']=='high'?'red':($r['risk']=='medium'?'purple':'green');?>"><?php echo $r['risk']=='high'?'نیاز فوری':($r['risk']=='medium'?'قابل بهبود':'خوب');?></span></td>
        <td style="font-size:.82rem"><?php echo esc_html($r['advice']);?></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <div style="margin-top:10px; font-size:.82rem; color:var(--nebula-muted); background:var(--nebula-input-bg); border:1px solid var(--nebula-border); border-radius:10px; padding:8px">
      <?php echo esc_html($notice);?>
    </div>
  </div>

<?php else: ?>
  <div class="eaiw-card eaiw-col-12">
    <h3><i>🔌</i> آمار ترافیک در دسترس نیست</h3>
    <p style="line-height:2"><?php echo esc_html($notice);?></p>
    <?php if ($err): ?>
      <p style="font-size:.82rem; color:#f59e0b">جزئیات خطا: <?php echo esc_html($err);?></p>
    <?php endif; ?>
    <p style="background:var(--nebula-input-bg); border:1px solid var(--nebula-border); border-radius:10px; padding:10px; font-size:.85rem; line-height:2">
      در نسخه‌های پیشین، این صفحه وقتی اتصالی وجود نداشت اعداد <strong>تصادفی</strong> نشان می‌داد. آن رفتار حذف شد؛
      نمایش عدد ساختگی بدتر از نمایش‌ندادن آن است، چون ممکن است بر پایهٔ آن تصمیم بگیرید.
    </p>
    <p><a href="<?php echo esc_url($settings_url);?>" class="eaiw-btn">اتصال به گوگل سرچ کنسول</a></p>
  </div>

  <?php if ($audit): ?>
  <div class="eaiw-card eaiw-col-12">
    <h3><i>📋</i> بررسی محتوا — بر پایهٔ خود سایت شما</h3>
    <p style="font-size:.85rem; color:var(--nebula-muted)">
      این جدول آمار ترافیک نیست. فقط واقعیت‌هایی دربارهٔ نوشته‌های خود سایت است که مستقیماً از دیتابیس خوانده می‌شود.
    </p>
    <table class="eaiw-table">
      <tr><th>نوشته</th><th>تعداد کلمه</th><th>آخرین به‌روزرسانی</th><th>نکته</th></tr>
      <?php foreach($audit as $a): ?>
      <tr>
        <td><a href="<?php echo esc_url($a['url']);?>" target="_blank" rel="noreferrer" style="color:#6d28ff; font-weight:700"><?php echo esc_html(mb_substr($a['title'],0,36));?></a></td>
        <td><?php echo esc_html(number_format_i18n($a['words']));?></td>
        <td><?php echo esc_html(sprintf('%s روز پیش', number_format_i18n($a['age_days'])));?></td>
        <td style="font-size:.82rem"><?php echo esc_html($a['issue_text']);?></td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>
  <?php endif; ?>
<?php endif; ?>

</div>
</div>
