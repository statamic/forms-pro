<?php

namespace Tests\FormConnections\Kit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Contracts\Forms\Form as FormContract;
use Statamic\Facades\Form;
use Statamic\Facades\User;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Tests\FakesRoles;
use Tests\TestCase;

class UpdateKitConnectionTest extends TestCase
{
    use FakesRoles;
    use PreventsSavingStacheItemsToDisk;

    private const FORM = 9785233;

    #[Test]
    public function it_denies_access_if_you_dont_have_permission()
    {
        $form = $this->form(['email_field' => 'email']);

        $this
            ->from('/original')
            ->actingAs($this->userWithoutPermission())
            ->patch(cp_route('forms.connect.kit.update', $form->handle()), [
                'email_field' => 'name',
            ])
            ->assertRedirect('/original')
            ->assertSessionHas('error');

        $this->assertEquals(['email_field' => 'email'], Form::find('test')->connections()->get('kit'));
    }

    #[Test]
    public function it_updates_the_kit_config()
    {
        $form = $this->form(['email_field' => 'email'], [
            'webhook' => [['id' => 'abc', 'url' => 'https://example.com/hook']],
        ]);

        $this
            ->actingAs($this->userWithPermission())
            ->patch(cp_route('forms.connect.kit.update', $form->handle()), [
                'form' => self::FORM,
                'email_field' => 'email',
                'first_name_field' => 'name',
                'consent_field' => 'consent',
                'tags' => [22367468, 22367469],
                'custom_fields' => [['key' => 'last_name', 'value' => 'name']],
            ])
            ->assertOk();

        $updated = Form::find('test');
        $this->assertEquals([
            'form' => self::FORM,
            'email_field' => 'email',
            'first_name_field' => 'name',
            'consent_field' => 'consent',
            'tags' => [22367468, 22367469],
            'custom_fields' => [['key' => 'last_name', 'value' => 'name']],
        ], $updated->connections()->get('kit'));
        $this->assertEquals([['id' => 'abc', 'url' => 'https://example.com/hook']], $updated->connections()->get('webhook'));
    }

    #[Test]
    public function it_doesnt_persist_empty_values()
    {
        $form = $this->form();

        $this
            ->actingAs($this->userWithPermission())
            ->patch(cp_route('forms.connect.kit.update', $form->handle()), [
                'form' => null,
                'email_field' => 'email',
                'first_name_field' => null,
                'consent_field' => null,
                'tags' => [],
                'custom_fields' => [],
            ])
            ->assertOk();

        $this->assertEquals(['email_field' => 'email'], Form::find('test')->connections()->get('kit'));
    }

    #[Test]
    #[DataProvider('invalidPayloads')]
    public function it_rejects_invalid_payloads(array $payload, array $errors)
    {
        $form = $this->form(['email_field' => 'email']);

        $this
            ->actingAs($this->userWithPermission())
            ->patchJson(cp_route('forms.connect.kit.update', $form->handle()), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($errors);

        $this->assertEquals(['email_field' => 'email'], Form::find('test')->connections()->get('kit'));
    }

    public static function invalidPayloads(): array
    {
        return [
            'missing email field' => [[], ['email_field']],
            'unknown email field' => [['email_field' => 'nope'], ['email_field']],
            'kit form that isnt an id' => [['form' => 'newsletter', 'email_field' => 'email'], ['form']],
            'tag that isnt an id' => [['email_field' => 'email', 'tags' => ['newsletter']], ['tags.0']],
            'the same tag twice' => [
                ['email_field' => 'email', 'tags' => [22367468, 22367468]],
                ['tags.0', 'tags.1'],
            ],
            'unknown consent field' => [['email_field' => 'email', 'consent_field' => 'nope'], ['consent_field']],
            'custom field without a key' => [
                ['email_field' => 'email', 'custom_fields' => [['value' => 'name']]],
                ['custom_fields.0.key'],
            ],
            'the same custom field key twice' => [
                ['email_field' => 'email', 'custom_fields' => [
                    ['key' => 'last_name', 'value' => 'name'],
                    ['key' => 'last_name', 'value' => 'email'],
                ]],
                ['custom_fields.0.key', 'custom_fields.1.key'],
            ],
            'custom field with an unknown form field' => [
                ['email_field' => 'email', 'custom_fields' => [['key' => 'last_name', 'value' => 'nope']]],
                ['custom_fields.0.value'],
            ],
            'custom field without a value' => [
                ['email_field' => 'email', 'custom_fields' => [['key' => 'last_name']]],
                ['custom_fields.0.value'],
            ],
        ];
    }

    private function form(?array $config = null, array $connections = []): FormContract
    {
        if ($config) {
            $connections['kit'] = $config;
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
