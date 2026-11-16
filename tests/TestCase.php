<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Tests check responses, not built assets; no `npm run build` needed to run them.
        $this->withoutVite();

        // No test reaches the network: a model call without Http::fake() fails at once instead of
        // waiting on the real provider (the queue is sync in tests, so a job can call it unseen).
        Http::preventStrayRequests();
    }
}
