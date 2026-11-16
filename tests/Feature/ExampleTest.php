<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_there_is_no_self_registration(): void
    {
        $this->get('/register')->assertNotFound();
    }
}
