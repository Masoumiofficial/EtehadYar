=== EtehadWP AI Writer — پوسته هوشمند ===
Contributors: etehadwp
Tags: ai, openai, gemini, claude, gpt, seo, content, woocommerce, elementor
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 6.13.2
License: GPLv2 or later

دنیای هوشمند هوش مصنوعی برای وردپرس — از نویسنده به سیستم‌عامل سایت.

== Description ==

**EtehadWP AI Universe v6.0 پوسته هوشمند** — ارتقای مهم پس از 5.23.0 Writer.

دیگر فقط مقاله نساز — امپراتوری بساز:

* **Nebula Command Center** — داشبورد هولوگرافیک زنده + Portal ورود + Command Palette (⌘K) + JARVIS فارسی
* **خرد سایت 2.0** — حافظه برداری کل سایت (Vector) + RAG بدون توهم
* **ارتش ۴ Agent خودمختار** — SEO Watcher, Gardener, Link Weaver, Trend Hunter (هر ۱۵ دقیقه)
* **Omnichannel Factory** — یک مقاله → ۳ تصویر + ویدئو ۶۰ث + پادکست + کاروسل + ایمیل + پیام کوتاه
* **Vision Studio** — تولید تصویر واقعی AI (Flux/Stability) + ذخیره در Media Library
* **صفحه‌ساز هوشمند** — با یک جمله، لندینگ Gutenberg/Elementor بساز
* **Woo God Mode** — توضیح متقاعدکننده + FAQ + مقایسه + سئو محصول
* **Oracle** — پیش‌بینی ۳۰ روزه رتبه/CTR
* **Guardian** — اسکن لینک شکسته/Alt/اسکیما + ترمیم
* **ChatSoul** — روح سایت: چت‌بات RAG برای بازدیدکنندگان
* **Nexus** — اتوماسیون اگر-آنگاه داخل وردپرس

سازگار ۱۰۰٪ با 5.23 — هیچ داده‌ای پاک نمی‌شود.

== رابطه با افزونهٔ هسته ==

این افزونه به‌تنهایی کار می‌کند و همهٔ قابلیت‌های بالا را دارد. اما یک افزونهٔ
تک‌کاربره است: هیچ لایهٔ چندمستأجری، کیف پول، جداسازی دادهٔ کاربران یا مدیریت
دسترسی اعضای سایت را ندارد.

برای راه‌اندازی به‌عنوان پلتفرم (فروش اعتبار به کاربران، پنل کاربری، تفکیک دادهٔ
هر عضو) افزونهٔ «اتحادیار هسته | Etehadyar Core» لازم است. هسته این کارها را
بدون تغییر دادن کد همین افزونه انجام می‌دهد — از جمله:

* صورتحساب کیف پول روی همهٔ عملیات‌های AI این افزونه
* بازگشت وجه کارهای صف‌شده‌ای که در پس‌زمینه ناموفق می‌شوند
* اجرای کارهای cron به نام مالک واقعی‌شان (نه `user_id = 0`)
* محدودسازی نرخ Endpointهای عمومی

نکتهٔ مهم: وقتی هسته فعال نباشد، محافظت‌های بالا وجود ندارند. برای همین
پرخرج‌ترین Endpoint عمومی (`/eaiw/v1/support/audio`، که فایل صوتی را به سرویس
رونویسی پولی می‌فرستد) محدودسازی نرخ داخلی خودش را دارد و در صورت فعال بودن
هسته، به محدودساز قوی‌تر هسته واگذار می‌کند تا یک درخواست دوبار شمارش نشود.

== Installation ==

1. پوشه `etehadwp-ai-writer` را در `/wp-content/plugins/` آپلود کنید
2. افزونه را فعال کنید — جداول vectors/agents/jobs/automations ساخته می‌شود
3. به «اتحاد AI Universe» بروید — Portal هوشمند را ببینید
4. کلیدهای AI را در تنظیمات Vault وارد کنید
5. مغز سایت را یک بار ایندکس کنید

== Changelog ==

= 6.0.0 — پوسته هوشمند (2026-08-06) =
* انفجار هوشمند پس از 5.23.0 Writer
* افزوده شد: Nebula Command Center + Portal + Command Palette + JARVIS
* افزوده شد: خرد سایت 2.0 (Vector Store + RAG)
* افزوده شد: یاران هوشمند (4 Agent + Cron 15min + Job Queue)
* افزوده شد: Omnichannel Factory (7 خروجی)
* افزوده شد: Vision Studio واقعی (SVG Placeholder + API Ready)
* افزوده شد: صفحه‌ساز هوشمند (Gutenberg)
* افزوده شد: Woo God Mode
* افزوده شد: پیش‌بینی سایت
* افزوده شد: نگهبان سایت
* افزوده شد: ChatSoul (REST + ویجت)
* افزوده شد: Nexus Automation
* حفظ شد: تمام 5.23 (سرویس Profiles, GSC/GA4/SERP, Knowledge Hub, Calendar, Cluster, Cannibalization, Video Studio)
* امنیت: Vault AES-256-GCM + Nonce + Trace ID

= 5.23.0 =
* Video Scenario Studio — آخرین نسخه Writer قبل از Universe

== Upgrade Notice ==

از 5.23 به 6.0 — فقط آپدیت کن. دیتابیس به‌صورت خودکار مهاجرت می‌کند. Portal بعد از ۳ ورود محو می‌شود — حالت هوشمند با سوییچ هدر قابل خاموش/روشن است.
