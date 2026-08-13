<?php

namespace Tests\FormConnections\HubSpot;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Contracts\Forms\Form as FormContract;
use Statamic\Facades\Form;
use Statamic\Facades\User;
use Statamic\Testing\Concerns\FakesRoles;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Tests\TestCase;

class UpdateHubSpotConnectionTest extends TestCase
{
    use FakesRoles;
    use PreventsSavingStacheItemsToDisk;

    private const string FORM = '2cc5419c-d732-4a4a-a82d-c0bfbcf948a5';

    #[Test]
    public function it_denies_access_if_you_dont_have_permission()
    {
        $form = $this->form(['email_field' => 'email']);

        $this
            ->from('/original')
            ->actingAs($this->userWithoutPermission())
            ->patch(cp_route('forms.connect.hubspot.update', $form->handle()), [
                'form' => self::FORM,
                'email_field' => 'name',
            ])
            ->assertRedirect('/original')
            ->assertSessionHas('error');

        $this->assertEquals(['email_field' => 'email'], Form::find('test')->connections()->get('hubspot'));
    }

    #[Test]
    public function it_updates_the_hubspot_config()
    {
        $form = $this->form(['email_field' => 'email'], [
            'webhook' => [['id' => 'abc', 'url' => 'https://example.com/hook']],
        ]);

        $this
            ->actingAs($this->userWithPermission())
            ->patch(cp_route('forms.connect.hubspot.update', $form->handle()), [
                'form' => self::FORM,
                'email_field' => 'email',
                'consent_field' => 'consent',
                'consent_to_process_field' => 'consent',
                'legitimate_interest_subscription' => 3314676590,
                'properties' => [['key' => 'firstname', 'value' => 'name']],
                'communications' => [['subscription' => 3314676590, 'field' => 'consent']],
            ])
            ->assertOk();

        $updated = Form::find('test');
        $this->assertEquals([
            'form' => self::FORM,
            'email_field' => 'email',
            'consent_field' => 'consent',
            'consent_to_process_field' => 'consent',
            'legitimate_interest_subscription' => 3314676590,
            'properties' => [['key' => 'firstname', 'value' => 'name']],
            'communications' => [['subscription' => 3314676590, 'field' => 'consent']],
        ], $updated->connections()->get('hubspot'));
        $this->assertEquals([['id' => 'abc', 'url' => 'https://example.com/hook']], $updated->connections()->get('webhook'));
    }

    #[Test]
    public function it_doesnt_persist_empty_values()
    {
        $form = $this->form();

        $this
            ->actingAs($this->userWithPermission())
            ->patch(cp_route('forms.connect.hubspot.update', $form->handle()), [
                'form' => self::FORM,
                'email_field' => 'email',
                'consent_field' => null,
                'consent_to_process_field' => null,
                'legitimate_interest_subscription' => null,
                'properties' => [],
                'communications' => [],
            ])
            ->assertOk();

        $this->assertEquals(
            ['form' => self::FORM, 'email_field' => 'email'],
            Form::find('test')->connections()->get('hubspot'),
        );
    }

    #[Test]
    #[DataProvider('invalidPayloads')]
    public function it_rejects_invalid_payloads(array $payload, array $errors)
    {
        $form = $this->form(['email_field' => 'email']);

        $this
            ->actingAs($this->userWithPermission())
            ->patchJson(cp_route('forms.connect.hubspot.update', $form->handle()), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($errors);

        $this->assertEquals(['email_field' => 'email'], Form::find('test')->connections()->get('hubspot'));
    }

    public static function invalidPayloads(): array
    {
        return [
            'missing form' => [['email_field' => 'email'], ['form']],
            'form that isnt a uuid' => [['form' => 'contact-us', 'email_field' => 'email'], ['form']],
            'missing email field' => [['form' => self::FORM], ['email_field']],
            'unknown email field' => [['form' => self::FORM, 'email_field' => 'nope'], ['email_field']],
            'unknown consent field' => [
                ['form' => self::FORM, 'email_field' => 'email', 'consent_field' => 'nope'],
                ['consent_field'],
            ],
            'email as a property' => [
                ['form' => self::FORM, 'email_field' => 'email', 'properties' => [['key' => 'email', 'value' => 'name']]],
                ['properties.0.key'],
            ],
            'the same property twice' => [
                ['form' => self::FORM, 'email_field' => 'email', 'properties' => [
                    ['key' => 'firstname', 'value' => 'name'],
                    ['key' => 'firstname', 'value' => 'email'],
                ]],
                ['properties.0.key', 'properties.1.key'],
            ],
            'property with an unknown form field' => [
                ['form' => self::FORM, 'email_field' => 'email', 'properties' => [['key' => 'firstname', 'value' => 'nope']]],
                ['properties.0.value'],
            ],
            'property without a value' => [
                ['form' => self::FORM, 'email_field' => 'email', 'properties' => [['key' => 'firstname']]],
                ['properties.0.value'],
            ],
            'subscription that isnt an id' => [
                ['form' => self::FORM, 'email_field' => 'email', 'communications' => [['subscription' => 'marketing', 'field' => 'consent']]],
                ['communications.0.subscription'],
            ],
            'the same subscription twice' => [
                ['form' => self::FORM, 'email_field' => 'email', 'communications' => [
                    ['subscription' => 3314676590, 'field' => 'consent'],
                    ['subscription' => 3314676590, 'field' => 'name'],
                ]],
                ['communications.0.subscription', 'communications.1.subscription'],
            ],
            'communication with an unknown form field' => [
                ['form' => self::FORM, 'email_field' => 'email', 'communications' => [['subscription' => 3314676590, 'field' => 'nope']]],
                ['communications.0.field'],
            ],
        ];
    }

    private function form(?array $config = null, array $connections = []): FormContract
    {
        if ($config) {
            $connections['hubspot'] = $config;
        }

        return tap(Form::make('test')->formFields([
            'fields' => [
                ['handle' => 'name', 'field' => ['type' => 'text']],
                ['handle' => 'email', 'field' => ['type' => 'text']],
                ['handle' => 'consent', 'field' => ['type' => 'toggle']],
            ],
        ])->connections($connections))->save();
    }

    private function userWithoutPermission()
    {
        $this->setTestRoles(['test' => ['access cp']]);

        return tap(User::make()->assignRole('test'))->save();
    }

    private function userWithPermission()
    {
        $this->setTestRoles(['test' => ['access cp', 'edit forms']]);

        return tap(User::make()->assignRole('test'))->save();
    }
}
