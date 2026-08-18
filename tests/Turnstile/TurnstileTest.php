<?php

namespace Tests\Turnstile;

use Illuminate\Support\Facades\Blade;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Parse;
use Tests\TestCase;

class TurnstileTest extends TestCase
{
    #[Test]
    public function it_renders_the_widget_when_turnstile_is_configured()
    {
        config([
            'forms-pro.turnstile.site_key' => 'site-key',
            'forms-pro.turnstile.secret_key' => 'secret-key',
        ]);

        $html = (string) Parse::template('{{ turnstile }}', [], trusted: true);

        $this->assertEquals('<div data-forms-pro-turnstile data-sitekey="site-key"></div>', $html);
    }

    #[Test]
    public function it_renders_the_widget_in_blade_templates()
    {
        config([
            'forms-pro.turnstile.site_key' => 'site-key',
            'forms-pro.turnstile.secret_key' => 'secret-key',
        ]);

        $html = Blade::render('<s:turnstile />');

        $this->assertEquals('<div data-forms-pro-turnstile data-sitekey="site-key"></div>', trim($html));
    }

    #[Test]
    public function it_renders_nothing_on_intermediate_pages_of_multi_page_forms()
    {
        config([
            'forms-pro.turnstile.site_key' => 'site-key',
            'forms-pro.turnstile.secret_key' => 'secret-key',
        ]);

        $context = [
            'pages' => [['id' => 'one'], ['id' => 'two'], ['id' => 'three']],
            'page' => ['id' => 'one'],
        ];

        $this->assertSame('', (string) Parse::template('{{ turnstile }}', $context, trusted: true));
    }

    #[Test]
    public function it_renders_the_widget_on_the_last_page_of_multi_page_forms()
    {
        config([
            'forms-pro.turnstile.site_key' => 'site-key',
            'forms-pro.turnstile.secret_key' => 'secret-key',
        ]);

        $context = [
            'pages' => [['id' => 'one'], ['id' => 'two'], ['id' => 'three']],
            'page' => ['id' => 'three'],
        ];

        $this->assertStringContainsString('data-forms-pro-turnstile', (string) Parse::template('{{ turnstile }}', $context, trusted: true));
    }

    #[Test]
    public function it_renders_the_widget_on_intermediate_pages_when_using_a_js_driver()
    {
        config([
            'forms-pro.turnstile.site_key' => 'site-key',
            'forms-pro.turnstile.secret_key' => 'secret-key',
        ]);

        $context = [
            'js_driver' => 'forms_pro_alpine',
            'pages' => [['id' => 'one'], ['id' => 'two'], ['id' => 'three']],
            'page' => ['id' => 'one'],
        ];

        $this->assertStringContainsString('data-forms-pro-turnstile', (string) Parse::template('{{ turnstile }}', $context, trusted: true));
    }

    #[Test]
    #[DataProvider('incompleteConfigurations')]
    public function it_renders_nothing_until_both_keys_are_configured(?string $siteKey, ?string $secretKey)
    {
        config([
            'forms-pro.turnstile.site_key' => $siteKey,
            'forms-pro.turnstile.secret_key' => $secretKey,
        ]);

        $this->assertSame('', (string) Parse::template('{{ turnstile }}', [], trusted: true));
    }

    public static function incompleteConfigurations(): array
    {
        return [
            'no keys' => [null, null],
            'site key only' => ['site-key', null],
            'secret key only' => [null, 'secret-key'],
        ];
    }
}
