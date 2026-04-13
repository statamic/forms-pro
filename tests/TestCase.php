<?php

namespace Tests;

use Statamic\FormsPro\ServiceProvider;
use Statamic\Testing\AddonTestCase;

class TestCase extends AddonTestCase
{
    protected string $addonServiceProvider = ServiceProvider::class;
}