<?php

namespace Tests;

use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Blueprint;
use Statamic\Facades\User;
use Statamic\FormsPro\Settings;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

class SettingsTest extends TestCase
{
    use PreventsSavingStacheItemsToDisk;

    #[Test]
    public function the_logo_field_falls_back_to_the_first_asset_container()
    {
        $this->makeAssetContainer('one');
        $this->makeAssetContainer('two');

        $this->assertEquals('one', $this->logoFieldtype()->preload()['container']['id']);
    }

    #[Test]
    public function the_logo_field_uses_the_configured_asset_container()
    {
        $this->makeAssetContainer('one');
        $this->makeAssetContainer('two');

        config()->set('forms-pro.automagic_forms.asset_container', 'two');

        $this->assertEquals('two', $this->logoFieldtype()->preload()['container']['id']);
    }

    private function makeAssetContainer(string $handle): void
    {
        AssetContainer::make($handle)->disk('local')->save();
    }

    private function logoFieldtype()
    {
        $this->actingAs(User::make()->makeSuper());

        return Blueprint::make()->setContents(Settings::blueprint())->field('logo')->fieldtype();
    }
}
