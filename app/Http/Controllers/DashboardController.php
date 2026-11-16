<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Models\EmailMessage;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** اللوحة: أرقام قليلة، كل رقم يظهر فقط لمن يملك صلاحية الصفحة اللي يفتحها. */
class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $mail = $user->can(Permission::ViewEmail->value) ? [
            'unread' => EmailMessage::query()->where('direction', 'in')->whereNull('read_at')->count(),
            'received_today' => EmailMessage::query()->where('direction', 'in')->where('created_at', '>=', today())->count(),
            'queued' => EmailMessage::query()->where('direction', 'out')->whereIn('status', ['queued', 'sending'])->count(),
            'failed' => EmailMessage::query()->where('direction', 'out')->where('status', 'failed')->count(),
            'latest' => EmailMessage::query()->where('direction', 'in')->latest('id')->limit(5)
                ->get(['id', 'from_email', 'from_name', 'subject', 'read_at', 'created_at']),
        ] : null;

        return Inertia::render('Dashboard', [
            'mail' => $mail,
            'chats' => $user->can(Permission::Chat->value) ? $user->chatSessions()->count() : null,
            // the admin sees what is still missing before the system can work
            'setup' => $user->is_admin ? [
                'ai_key' => (bool) Setting::get('ai.api_key'),
                'mailbox' => (bool) Setting::get('mail.address') && (bool) Setting::get('mail.app_password'),
                'receiving' => Setting::bool('mail.receiving_enabled'),
                'sending' => Setting::bool('mail.sending_enabled'),
                'team' => User::query()->count(),
            ] : null,
        ]);
    }
}
