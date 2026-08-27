<?php

namespace Tests\AutomagicForms;

use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades;
use Statamic\FormsPro\AutomagicForms\FormLinkType;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Tests\TestCase;

class FormLinkTypeTest extends TestCase
{
    use PreventsSavingStacheItemsToDisk;

    protected function setUp(): void
    {
        parent::setUp();

        Facades\Form::all()->each->delete();
    }

    #[Test]
    public function it_resolves_a_form_to_its_automagic_form_url()
    {
        Facades\Form::make('contact')->title('Contact')->save();

        $this->assertEquals(
            route('statamic.forms-pro.form.default.index', ['form' => 'contact'], absolute: false),
            (new FormLinkType)->resolve('contact')
        );
    }

    #[Test]
    public function it_resolves_nothing_when_the_form_does_not_exist()
    {
        $this->assertNull((new FormLinkType)->resolve('unknown'));
    }

    #[Test]
    public function it_translates_its_title()
    {
        $this->assertEquals('Form', (new FormLinkType)->title());

        app('translator')->addLines(['*.Form' => 'Formulier'], app()->getLocale());

        $this->assertEquals('Formulier', (new FormLinkType)->title());
    }
}
