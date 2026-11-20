<div dir="rtl">

# QuestBoard Platform

یک بک‌اند چندبازی (Multi-Tenant) با Laravel برای **مأموریت‌ها، پیشرفت بازیکن، دستاوردها، فصل‌های رقابتی و لیدربورد لحظه‌ای**؛ با معماری رویدادمحور (Outbox + صف)، ضدتقلب لایه‌ای، تولید محتوای مأموریت با LLM، Webhook امضاشده، مشاهده‌پذیری و استقرار با Docker و CI/CD.

> نسخه کامل مستندات به انگلیسی: [README.md](README.md)
>
> **وضعیت:** پروژه آموزشی/نمایشی است؛ با اصول تولیدی ساخته شده ولی در محیط واقعی تست بار نشده است.

## قابلیت‌ها

- **چندبازی بودن سطح ردیف:** ستون `game_id` در همه جداول، Global Scope، Route Binding محدود به بازی و بررسی عضویت/مدیریت (تست اختصاصی IDOR)
- **مأموریت‌ها:** CRUD، پیش‌نویس ← انتشار، مأموریت تکرارشونده با Cooldown، صفحه‌بندی Cursor
- **XP و سطح:** `round(xp_reward × clamp(score/max_score) × ضریب دشواری)`، منحنی سطح قابل تعویض، سقف XP روزانه برای هر بازی
- **Idempotency:** هدر `Idempotency-Key` الزامی، بازپخش همان پاسخ، قفل درخواست هم‌زمان، Unique constraint و قفل ردیف در دیتابیس
- **لیدربورد:** Redis Sorted Set برای همه‌زمان‌ها، هفتگی و فصلی؛ رتبه من و همسایه‌ها؛ بازسازی از MySQL و صحت‌سنجی دوره‌ای
- **دستاوردها:** قواعد JSON، ارزیابی غیرهمزمان، باز شدن فقط یک‌بار
- **فصل‌ها:** حداکثر یک فصل فعال، فعال/بسته‌شدن خودکار، Snapshot رتبه نهایی، تمدید خودکار
- **ضدتقلب:** اعتبارسنجی منطقی، قوانین `RiskScorer` (سرعت غیرعادی، ارسال پشت‌سرهم، امتیاز کامل مشکوک)، صف بازبینی بدون اعمال XP، Ban/Shadow-ban، API سرور بازی با HMAC و جلوگیری از Replay
- **تولید محتوا با AI:** پاسخ ۲۰۲ و پردازش در صف، پرامپت نسخه‌دار با امکان Rollback، جداسازی ورودی کاربر (ضد Prompt Injection)، اعتبارسنجی Schema و یک Retry اصلاحی، Moderation، ثبت توکن و هزینه، سهمیه ماهانه (۴۲۹)، ذخیره فقط به‌صورت پیش‌نویس
- **Webhook:** امضای HMAC-SHA256، Retry نمایی (۶ تلاش)، غیرفعال‌شدن خودکار، محافظت SSRF
- **عملیات:** خطاهای `application/problem+json`، لاگ JSON با `request_id`، متریک Prometheus، Health check، Audit Log، Horizon

## اجرای سریع

</div>

```bash
git clone https://github.com/blindmagnet/questboard.git && cd questboard
make up        # ساخت .env با APP_KEY جدید و بالا آوردن کل سرویس‌ها
```

<div dir="rtl">

بعد از اجرا API روی `http://localhost:8080` در دسترس است. همه حساب‌های نمونه رمز `password123` دارند:
`admin@questboard.test` (مدیر پلتفرم)، `ember-admin@questboard.test` (مدیر بازی)، `player1@ember.test` (بازیکن).

## مستندات

- معماری، ERD و Sequence Diagram: [docs/architecture.md](docs/architecture.md)
- تصمیم‌های معماری (ADR): [docs/adr](docs/adr)
- Runbook: [docs/runbook.md](docs/runbook.md)
- قرارداد API (OpenAPI): [docs/openapi.json](docs/openapi.json)
- تست بار k6: [load/](load/README.md)

## تست و کیفیت

۱۱۲ تست Pest (Unit، Feature، Integration با Redis واقعی، Contract و امنیتی)، پوشش حدود ۹۴٪، Larastan سطح ۶ بدون خطا، Pint؛ روی MariaDB 10.11 هم اجرا و تأیید شده. CI روی MySQL 8.4 و Redis 7 اجرا می‌شود.

## مجوز

[MIT](LICENSE) — © ۲۰۲۶ غزاله قاسم‌زاده (NewBlab)

</div>
