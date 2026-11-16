<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * أول أدمن (مدير الـ HR). المنصة بلا تسجيل ذاتي، فهذا الأمر هو المدخل الوحيد
 * لأول حساب، وكلمة المرور تُكتب في الطرفية مخفية ولا تظهر في أي سجل.
 */
class CreateAdmin extends Command
{
    protected $signature = 'hr:create-admin {email} {--name=مدير الموارد البشرية}';

    protected $description = 'Create the first HR admin account (asks for the password, hidden)';

    public function handle(): int
    {
        $email = strtolower(trim($this->argument('email')));
        $password = (string) $this->secret('كلمة المرور (١٢ حرفًا على الأقل)');

        $v = Validator::make(['email' => $email, 'password' => $password], [
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', Password::defaults()],
        ]);
        if ($v->fails()) {
            foreach ($v->errors()->all() as $e) {
                $this->error($e);
            }

            return self::FAILURE;
        }

        $user = User::create([
            'name' => $this->option('name'),
            'email' => $email,
            'password' => Hash::make($password),
        ]);
        $user->forceFill(['email_verified_at' => now(), 'is_admin' => true])->save();

        $this->info("تم إنشاء الأدمن #{$user->id} <{$email}>");

        return self::SUCCESS;
    }
}
