<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المرفقات اللي رفضها فحص الملفات (UploadGuard) ما تنحفظ أبدًا، فهنا ينحفظ بس اسمها وسبب الرفض،
 * عشان صفحة الإيميل تقول وش انرفض وليش. ملف تحديث جديد بدل تعديل الأول، لأن الأول اشتغل على DEV.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_messages', fn (Blueprint $t) => $t->jsonb('refused_attachments')->nullable()->after('body_text'));
    }

    public function down(): void
    {
        Schema::table('email_messages', fn (Blueprint $t) => $t->dropColumn('refused_attachments'));
    }
};
