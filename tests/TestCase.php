<?php

namespace Tests;

use Facades\Statamic\Console\Processes\Composer;
use Statamic\FormsPro\ServiceProvider;
use Statamic\Testing\AddonTestCase;

class TestCase extends AddonTestCase
{
    protected string $addonServiceProvider = ServiceProvider::class;

    protected function setUp(): void
    {
        parent::setUp();

        Composer::shouldReceive('isInstalled')->with('statamic/forms-pro')->andReturn(true);
    }
}
