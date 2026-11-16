<?php

namespace Tests\Feature;

use App\Models\EmailMessage;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_accounts_and_sample_emails_are_created_once(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);   // the container seeds on every start

        $admin = User::query()->where('email', DatabaseSeeder::DEMO_ADMIN)->sole();
        $member = User::query()->where('email', DatabaseSeeder::DEMO_MEMBER)->sole();
        $this->assertTrue($admin->is_admin);
        $this->assertFalse($member->is_admin);
        $this->assertSame(['email.view', 'chat.use'], $member->effectivePermissions());
        $this->assertTrue(Hash::check(DatabaseSeeder::DEFAULT_PASSWORD, $admin->password));
        $this->assertSame(3, EmailMessage::query()->count());

        $this->post('/login', ['email' => DatabaseSeeder::DEMO_ADMIN, 'password' => DatabaseSeeder::DEFAULT_PASSWORD])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($admin);
    }

    public function test_the_demo_password_follows_the_environment(): void
    {
        $this->seed(DatabaseSeeder::class);
        putenv('DEMO_PASSWORD=a-long-server-only-password');
        try {
            $this->seed(DatabaseSeeder::class);
        } finally {
            putenv('DEMO_PASSWORD');
        }

        $admin = User::query()->where('email', DatabaseSeeder::DEMO_ADMIN)->sole();
        $this->assertTrue(Hash::check('a-long-server-only-password', $admin->password));
        $this->assertFalse(Hash::check(DatabaseSeeder::DEFAULT_PASSWORD, $admin->password));
    }

    public function test_real_mail_is_never_mixed_with_samples(): void
    {
        EmailMessage::query()->create(['direction' => 'in', 'status' => 'received', 'from_email' => 'real@example.com', 'subject' => 'real']);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(['real'], EmailMessage::query()->pluck('subject')->all());
    }
}
