<?php

namespace App\Http\Requests\Auth;

use App\Models\AuditLog;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /** Wrong passwords from one device for one address, per minute. */
    public const PER_DEVICE = 5;

    /** Wrong passwords for one address from every device together, per hour. */
    public const PER_ACCOUNT = 20;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        // is_active inside the attempt: a deactivated member fails exactly like a wrong password
        if (! Auth::attempt([...$this->only('email', 'password'), 'is_active' => true], $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey());
            RateLimiter::hit($this->accountKey(), 3600);
            if (RateLimiter::attempts($this->accountKey()) === self::PER_ACCOUNT) {
                // logged once, when the account limit is reached: many devices guessing one account
                AuditLog::record('auth.account_throttled', null, ['email' => Str::lower($this->string('email'))]);
            }

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
        RateLimiter::clear($this->accountKey());
        Auth::user()->forceFill(['last_login_at' => now()])->saveQuietly();
    }

    /**
     * Ensure the login request is not rate limited: per device, and per account across every device,
     * so guessing one account's password from many addresses stops too.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        $key = match (true) {
            RateLimiter::tooManyAttempts($this->throttleKey(), self::PER_DEVICE) => $this->throttleKey(),
            RateLimiter::tooManyAttempts($this->accountKey(), self::PER_ACCOUNT) => $this->accountKey(),
            default => null,
        };
        if ($key === null) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($key);

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }

    /** The same address from any device. */
    public function accountKey(): string
    {
        return 'login-account|'.Str::transliterate(Str::lower($this->string('email')));
    }
}
