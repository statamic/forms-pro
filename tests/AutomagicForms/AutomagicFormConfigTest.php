<?php

namespace Tests\AutomagicForms;

use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades;
use Statamic\Facades\User;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Tests\TestCase;

class AutomagicFormConfigTest extends TestCase
{
    use PreventsSavingStacheItemsToDisk;

    protected function setUp(): void
    {
        parent::setUp();

        Facades\Form::all()->each->delete();
        Facades\Form::make('contact')->title('Contact')->save();

        $this->actingAs(User::make()->makeSuper());
    }

    #[Test]
    public function it_fails_validation_when_automagic_form_and_unique_instances_are_both_enabled()
    {
        $this
            ->patchJson(cp_route('forms.update', 'contact'), $this->payload(automagic: true, uniqueInstances: true))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['automagic_form' => "Automagic Forms can't be used with Unique Instances."]);
    }

    #[Test]
    public function it_passes_validation_when_only_automagic_form_is_enabled()
    {
        $this
            ->patchJson(cp_route('forms.update', 'contact'), $this->payload(automagic: true, uniqueInstances: false))
            ->assertOk();
    }

    #[Test]
    public function it_passes_validation_when_only_unique_instances_is_enabled()
    {
        $this
            ->patchJson(cp_route('forms.update', 'contact'), $this->payload(automagic: false, uniqueInstances: true))
            ->assertOk();
    }

    private function payload(bool $automagic, bool $uniqueInstances): array
    {
        return [
            'title' => 'Contact',
            'honeypot' => null,
            'store' => true,
            'automagic_form' => $automagic,
            'unique_instances' => $uniqueInstances,
        ];
    }
}
