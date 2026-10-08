<?php

namespace Tests\FormConnectors;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

trait FakesConnectorApis
{
    protected function setUpFakesConnectorApis(): void
    {
        $this->fakeHttp(['*' => Http::response(null, 503)]);
    }

    protected function fakeHttp($callback = null): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($callback);
    }
}
