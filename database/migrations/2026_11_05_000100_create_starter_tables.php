<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * كل جداول نسخة المتدرب في ملف واحد، عشان تنقرى من أول نظرة:
 *
 * - أعمدة الفريق على users: أدمن، ونشط، وصلاحيات، ولغة.
 * - settings: إعدادات الأدمن (مزوّد الذكاء الاصطناعي وصندوق الإيميل). السرّي منها مشفّر.
 * - email_messages وemail_attachments: الوارد والصادر من صندوق الإيميل، ومرفقاته على قرص خاص.
 * - chat_sessions وchat_messages: الدردشة مع المساعد، كل جلسة لصاحبها.
 * - audit_logs: من سوّى وش، ومتى.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->boolean('is_admin')->default(false);
            $t->boolean('is_active')->default(true);
            $t->boolean('must_change_password')->default(false);
            $t->jsonb('permissions')->default('[]');
            $t->string('locale', 5)->default('ar');
            $t->timestamp('last_login_at')->nullable();
        });

        Schema::create('settings', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->text('value')->nullable();
            $t->boolean('encrypted')->default(false);
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('email_messages', function (Blueprint $t) {
            $t->id();
            $t->string('direction', 8);                 // in | out
            $t->string('status', 16);                   // received | queued | sending | sent | failed
            $t->string('message_id')->nullable()->unique();
            $t->string('in_reply_to')->nullable()->index();
            $t->jsonb('references')->default('[]');
            $t->string('from_email')->nullable();
            $t->string('from_name')->nullable();
            $t->jsonb('to')->default('[]');
            $t->string('subject')->nullable();
            $t->longText('body_text')->nullable();      // plain text only; HTML is never rendered
            $t->unsignedInteger('imap_uid')->nullable();
            $t->unsignedTinyInteger('send_attempts')->default(0);
            $t->text('error')->nullable();
            $t->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('read_at')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamps();
            $t->index(['direction', 'status']);
        });

        Schema::create('email_attachments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('email_message_id')->constrained()->cascadeOnDelete();
            $t->string('path');                         // under storage/app/private, never public/
            $t->string('original_name');
            $t->string('mime', 128);
            $t->unsignedBigInteger('size');
            $t->timestamps();
        });

        Schema::create('chat_sessions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('title')->nullable();
            $t->timestamps();
        });

        Schema::create('chat_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('chat_session_id')->constrained()->cascadeOnDelete();
            $t->string('role', 16);                     // user | assistant
            $t->longText('content');
            $t->timestamp('created_at')->useCurrent();
        });

        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('action', 64);
            $t->string('subject_type')->nullable();
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->jsonb('changes')->default('{}');
            $t->string('ip', 45)->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['audit_logs', 'chat_messages', 'chat_sessions', 'email_attachments', 'email_messages', 'settings'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['is_admin', 'is_active', 'must_change_password', 'permissions', 'locale', 'last_login_at']));
    }
};
