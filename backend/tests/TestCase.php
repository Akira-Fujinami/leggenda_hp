<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // fakeされていない実通信は即例外にする。ネットワーク待ちで
        // テストがハングする(タイムアウトなしで流すと発覚が遅れる)のを防ぐため。
        Http::preventStrayRequests();
    }
}
