<?php

namespace App\Providers;

use App\Enums\Permission;
use App\Models\User;
use App\Rules\CaselessOrMixedCase;
use App\Services\Mail\ImapMailbox;
use App\Services\Mail\Mailbox;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // the agent's inbox; tests bind a fake in its place
        $this->app->bind(Mailbox::class, ImapMailbox::class);
    }

    public function boot(): void
    {
        // Mail is never written to the log outside tests: a password-reset email there is a key to the
        // account in a file (SECURITY_AUDIT ٢). Real mail goes through the agent mailbox (AgentMailer).
        if (config('mail.default') === 'log' && ! $this->app->runningUnitTests()) {
            config(['mail.default' => 'array']);
        }

        // Every password in the system: 12+ characters, letters and a number. Upper and lower case only
        // when every letter is Latin: Arabic letters have no case, so mixedCase() would refuse any
        // Arabic password.
        Password::defaults(fn () => Password::min(12)->letters()->numbers()->rules([new CaselessOrMixedCase]));

        // One gate per permission (email.view, email.send, chat.use). The admin passes every gate;
        // a deactivated member passes none, even with a session that is still open.
        Gate::before(fn (User $user) => ! $user->is_active ? false : ($user->is_admin ? true : null));
        foreach (Permission::cases() as $permission) {
            Gate::define($permission->value, fn (User $user) => $user->hasPermission($permission));
        }
    }
}
