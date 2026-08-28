<?php

namespace Tests\AutomagicForms;

use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Contracts\Data\Localization;
use Statamic\Contracts\Forms\Form;
use Statamic\Facades;
use Statamic\FormsPro\AutomagicForms\FormLinkType;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Tests\TestCase;

class AutomagicFormRoutingTest extends TestCase
{
    use PreventsSavingStacheItemsToDisk;

    private bool $enabled = true;

    private string $sites = <<<'YAML'
default:
    name: Default
    url: http://localhost/
    locale: en_US
french:
    name: French
    url: http://localhost/fr/
    locale: fr_FR
YAML;

    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('statamic.system.multisite', true);
        $app['config']->set('statamic.editions.pro', true);
        $app['config']->set('forms-pro.automagic_forms.enabled', $this->enabled);
        $app['config']->set('forms-pro.automagic_forms.route', 'get-in-touch');

        $app['files']->put(resource_path('sites.yaml'), $this->sites);
    }

    protected function useSitesOnSeparateDomains(): void
    {
        $this->sites = <<<'YAML'
default:
    name: Default
    url: http://localhost/
    locale: en_US
french:
    name: French
    url: http://french.example.com/
    locale: fr_FR
YAML;
    }

    protected function disableAutomagicForms(): void
    {
        $this->enabled = false;
    }

    protected function setUp(): void
    {
        parent::setUp();

        Facades\Form::all()->each->delete();
    }

    protected function tearDown(): void
    {
        $this->app['files']->delete(resource_path('sites.yaml'));

        parent::tearDown();
    }

    #[Test]
    public function it_generates_urls_using_the_configured_route()
    {
        $this->createForm();

        $this->assertEquals(
            'http://localhost/get-in-touch/contact',
            route('statamic.forms-pro.form.default.index', ['form' => 'contact'])
        );

        $this->assertEquals(
            'http://localhost/fr/get-in-touch/contact',
            route('statamic.forms-pro.form.french.index', ['form' => 'contact'])
        );
    }

    #[Test]
    public function it_renders_the_form_for_the_default_site()
    {
        $this->createForm();

        $this
            ->get(route('statamic.forms-pro.form.default.index', ['form' => 'contact']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('site.handle', 'default'));
    }

    #[Test]
    public function it_renders_the_form_for_a_non_default_site()
    {
        $this->createForm();

        $this
            ->get(route('statamic.forms-pro.form.french.index', ['form' => 'contact']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('site.handle', 'french'));
    }

    #[Test]
    public function it_submits_the_form_on_a_non_default_site()
    {
        $form = $this->createForm();

        $this
            ->postJson(route('statamic.forms-pro.form.french.store', ['form' => 'contact', 'page' => 'main']), [
                'name' => 'Han Solo',
                'email' => 'han@example.com',
            ])
            ->assertOk()
            ->assertJsonPath('redirect', route('statamic.forms-pro.form.french.success', ['form' => 'contact']));

        $this->assertNotNull($form->submissions()->first());
    }

    #[Test]
    public function it_renders_the_form_when_the_request_comes_from_another_hostname()
    {
        $this->createForm();

        $this
            ->get('http://staging.example.com/get-in-touch/contact')
            ->assertOk();
    }

    #[Test]
    #[DefineEnvironment('useSitesOnSeparateDomains')]
    public function it_generates_urls_for_sites_on_separate_domains()
    {
        $this->createForm();

        $this->assertEquals(
            'http://localhost/get-in-touch/contact',
            route('statamic.forms-pro.form.default.index', ['form' => 'contact'])
        );

        $this->assertEquals(
            'http://french.example.com/get-in-touch/contact',
            route('statamic.forms-pro.form.french.index', ['form' => 'contact'])
        );
    }

    #[Test]
    #[DefineEnvironment('useSitesOnSeparateDomains')]
    public function it_renders_the_form_for_a_site_on_another_domain()
    {
        $this->createForm();

        $this
            ->get(route('statamic.forms-pro.form.french.index', ['form' => 'contact']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('site.handle', 'french'));
    }

    #[Test]
    public function it_resolves_the_link_type_url_using_the_current_site_by_default()
    {
        $this->createForm();

        $this->assertEquals(
            route('statamic.forms-pro.form.default.index', ['form' => 'contact'], absolute: false),
            (new FormLinkType)->resolve('contact')
        );
    }

    #[Test]
    public function it_resolves_the_link_type_url_using_the_parents_site()
    {
        $this->createForm();

        $parent = $this->createStub(Localization::class);
        $parent->method('locale')->willReturn('french');

        $this->assertEquals(
            route('statamic.forms-pro.form.french.index', ['form' => 'contact'], absolute: false),
            (new FormLinkType)->resolve('contact', $parent, localize: true)
        );
    }

    #[Test]
    #[DefineEnvironment('disableAutomagicForms')]
    public function it_doesnt_register_routes_when_automagic_forms_are_disabled()
    {
        $this->createForm();

        $this->assertFalse(Route::has('statamic.forms-pro.form.default.index'));
        $this->assertFalse(Route::has('statamic.forms-pro.form.french.index'));
    }

    private function createForm(): Form
    {
        return tap(Facades\Form::make('contact')
            ->title('Contact')
            ->set('automagic_form', true)
            ->formFields([
                'fields' => [
                    ['handle' => 'name', 'field' => ['type' => 'text', 'validate' => ['required']]],
                    ['handle' => 'email', 'field' => ['type' => 'text', 'validate' => ['required', 'email']]],
                ],
            ]))->save();
    }
}
