<?php

namespace Tests\FormConnections\Mailcoach;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Contracts\Forms\Form as FormContract;
use Statamic\Facades\Form;
use Statamic\Facades\User;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Tests\FakesRoles;
use Tests\TestCase;

class UpdateMailcoachConnectionTest extends TestCase
{
    use FakesRoles;
    use PreventsSavingStacheItemsToDisk;

    private const LIST = '9dcbb6dc-1b47-4d2e-9d3f-8dbf1a92a5f1';

    #[Test]
    public function it_denies_access_if_you_dont_have_permission()
    {
        $form = $this->form(['list' => self::LIST, 'email_field' => 'email']);

        $this
            ->from('/original')
            ->actingAs($this->userWithoutPermission())
            ->patch(cp_route('forms.connect.mailcoach.update', $form->handle()), [
                'list' => self::LIST,
                'email_field' => 'name',
            ])
            ->assertRedirect('/original')
            ->assertSessionHas('error');

        $this->assertEquals(['list' => self::LIST, 'email_field' => 'email'], Form::find('test')->connections()->get('mailcoach'));
    }

    #[Test]
    public function it_updates_the_mailcoach_config()
    {
        $form = $this->form(['list' => self::LIST, 'email_field' => 'email'], [
            'webhook' => [['id' => 'abc', 'url' => 'https://example.com/hook']],
        ]);

        $this
            ->actingAs($this->userWithPermission())
            ->patch(cp_route('forms.connect.mailcoach.update', $form->handle()), [
                'list' => self::LIST,
                'email_field' => 'email',
                'first_name_field' => 'name',
                'consent_field' => 'consent',
                'tags' => ['newsletter'],
                'attributes' => [['key' => 'full_name', 'value' => 'name']],
                'skip_confirmation' => true,
            ])
            ->assertOk();

        $updated = Form::find('test');
        $this->assertEquals([
            'list' => self::LIST,
            'email_field' => 'email',
            'first_name_field' => 'name',
            'consent_field' => 'consent',
            'tags' => ['newsletter'],
            'attributes' => [['key' => 'full_name', 'value' => 'name']],
            'skip_confirmation' => true,
        ], $updated->connections()->get('mailcoach'));
        $this->assertEquals([['id' => 'abc', 'url' => 'https://example.com/hook']], $updated->connections()->get('webhook'));
    }

    #[Test]
    public function it_doesnt_persist_empty_values()
    {
        $form = $this->form();

        $this
            ->actingAs($this->userWithPermission())
            ->patch(cp_route('forms.connect.mailcoach.update', $form->handle()), [
                'list' => self::LIST,
                'email_field' => 'email',
                'first_name_field' => null,
                'last_name_field' => null,
                'consent_field' => null,
                'tags' => [],
                'attributes' => [],
                'skip_confirmation' => false,
            ])
            ->assertOk();

        $this->assertEquals(['list' => self::LIST, 'email_field' => 'email'], Form::find('test')->connections()->get('mailcoach'));
    }

    #[Test]
    #[DataProvider('invalidPayloads')]
    public function it_rejects_invalid_payloads(array $payload, array $errors)
    {
        $form = $this->form(['list' => self::LIST, 'email_field' => 'email']);

        $this
            ->actingAs($this->userWithPermission())
            ->patchJson(cp_route('forms.connect.mailcoach.update', $form->handle()), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($errors);

        $this->assertEquals(['list' => self::LIST, 'email_field' => 'email'], Form::find('test')->connections()->get('mailcoach'));
    }

    public static function invalidPayloads(): array
    {
        return [
            'missing list' => [['email_field' => 'email'], ['list']],
            'list that isnt a uuid' => [['list' => 'newsletter', 'email_field' => 'email'], ['list']],
            'missing email field' => [[['list' => self::LIST]], ['email_field']],
            'unknown email field' => [['list' => self::LIST, 'email_field' => 'nope'], ['email_field']],
            'unknown consent field' => [['list' => self::LIST, 'email_field' => 'email', 'consent_field' => 'nope'], ['consent_field']],
            'attribute without a key' => [
                ['list' => self::LIST, 'email_field' => 'email', 'attributes' => [['value' => 'name']]],
                ['attributes.0.key'],
            ],
            'attribute with an unknown form field' => [
                ['list' => self::LIST, 'email_field' => 'email', 'attributes' => [['key' => 'full_name', 'value' => 'nope']]],
                ['attributes.0.value'],
            ],
            'attribute without a value' => [
                ['list' => self::LIST, 'email_field' => 'email', 'attributes' => [['key' => 'full_name']]],
                ['attributes.0.value'],
            ],
        ];
    }

    private function form(?array $config = null, array $connections = []): FormContract
    {
        if ($config) {
            $connections['mailcoach'] = $config;
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
