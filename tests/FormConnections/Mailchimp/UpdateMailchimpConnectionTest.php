<?php

namespace Tests\FormConnections\Mailchimp;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Contracts\Forms\Form as FormContract;
use Statamic\Facades\Form;
use Statamic\Facades\User;
use Statamic\Testing\Concerns\FakesRoles;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Tests\TestCase;

class UpdateMailchimpConnectionTest extends TestCase
{
    use FakesRoles;
    use PreventsSavingStacheItemsToDisk;

    private const string AUDIENCE = 'a1b2c3d4e5';

    #[Test]
    public function it_denies_access_if_you_dont_have_permission()
    {
        $form = $this->form(['list' => self::AUDIENCE, 'email_field' => 'email']);

        $this
            ->from('/original')
            ->actingAs($this->userWithoutPermission())
            ->patch(cp_route('forms.connect.mailchimp.update', $form->handle()), [
                'list' => self::AUDIENCE,
                'email_field' => 'name',
            ])
            ->assertRedirect('/original')
            ->assertSessionHas('error');

        $this->assertEquals(['list' => self::AUDIENCE, 'email_field' => 'email'], Form::find('test')->connections()->get('mailchimp'));
    }

    #[Test]
    public function it_updates_the_mailchimp_config()
    {
        $form = $this->form(['list' => self::AUDIENCE, 'email_field' => 'email'], [
            'webhook' => [['id' => 'abc', 'url' => 'https://example.com/hook']],
        ]);

        $this
            ->actingAs($this->userWithPermission())
            ->patch(cp_route('forms.connect.mailchimp.update', $form->handle()), [
                'list' => self::AUDIENCE,
                'email_field' => 'email',
                'first_name_field' => 'name',
                'consent_field' => 'consent',
                'tags' => ['newsletter'],
                'merge_fields' => [['key' => 'COMPANY', 'value' => 'name']],
                'groups' => [['group' => '4e4b2bc6ae', 'field' => 'consent', 'option' => 'news']],
                'marketing_permissions' => [['permission' => 'd60cef6b1f', 'field' => 'consent']],
                'skip_confirmation' => true,
            ])
            ->assertOk();

        $updated = Form::find('test');
        $this->assertEquals([
            'list' => self::AUDIENCE,
            'email_field' => 'email',
            'first_name_field' => 'name',
            'consent_field' => 'consent',
            'tags' => ['newsletter'],
            'merge_fields' => [['key' => 'COMPANY', 'value' => 'name']],
            'groups' => [['group' => '4e4b2bc6ae', 'field' => 'consent', 'option' => 'news']],
            'marketing_permissions' => [['permission' => 'd60cef6b1f', 'field' => 'consent']],
            'skip_confirmation' => true,
        ], $updated->connections()->get('mailchimp'));
        $this->assertEquals([['id' => 'abc', 'url' => 'https://example.com/hook']], $updated->connections()->get('webhook'));
    }

    #[Test]
    public function it_doesnt_persist_empty_values()
    {
        $form = $this->form();

        $this
            ->actingAs($this->userWithPermission())
            ->patch(cp_route('forms.connect.mailchimp.update', $form->handle()), [
                'list' => self::AUDIENCE,
                'email_field' => 'email',
                'first_name_field' => null,
                'last_name_field' => null,
                'consent_field' => null,
                'tags' => [],
                'merge_fields' => [],
                'marketing_permissions' => [],
                'skip_confirmation' => false,
            ])
            ->assertOk();

        $this->assertEquals([
            'list' => self::AUDIENCE,
            'email_field' => 'email',
            'skip_confirmation' => false,
        ], Form::find('test')->connections()->get('mailchimp'));
    }

    #[Test]
    #[DataProvider('invalidPayloads')]
    public function it_rejects_invalid_payloads(array $payload, array $errors)
    {
        $form = $this->form(['list' => self::AUDIENCE, 'email_field' => 'email']);

        $this
            ->actingAs($this->userWithPermission())
            ->patchJson(cp_route('forms.connect.mailchimp.update', $form->handle()), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($errors);

        $this->assertEquals(['list' => self::AUDIENCE, 'email_field' => 'email'], Form::find('test')->connections()->get('mailchimp'));
    }

    public static function invalidPayloads(): array
    {
        return [
            'missing audience' => [['email_field' => 'email'], ['list']],
            'audience with a path separator' => [['list' => 'a1b2c3d4e5/members', 'email_field' => 'email'], ['list']],
            'audience with a query string' => [['list' => 'a1b2c3d4e5?count=1000', 'email_field' => 'email'], ['list']],
            'missing email field' => [['list' => self::AUDIENCE], ['email_field']],
            'unknown email field' => [['list' => self::AUDIENCE, 'email_field' => 'nope'], ['email_field']],
            'unknown consent field' => [['list' => self::AUDIENCE, 'email_field' => 'email', 'consent_field' => 'nope'], ['consent_field']],
            'merge field without a key' => [
                ['list' => self::AUDIENCE, 'email_field' => 'email', 'merge_fields' => [['value' => 'name']]],
                ['merge_fields.0.key'],
            ],
            'merge field without a value' => [
                ['list' => self::AUDIENCE, 'email_field' => 'email', 'merge_fields' => [['key' => 'COMPANY']]],
                ['merge_fields.0.value'],
            ],
            'lowercase merge tag' => [
                ['list' => self::AUDIENCE, 'email_field' => 'email', 'merge_fields' => [['key' => 'company', 'value' => 'name']]],
                ['merge_fields.0.key'],
            ],
            'merge field with an unknown form field' => [
                ['list' => self::AUDIENCE, 'email_field' => 'email', 'merge_fields' => [['key' => 'COMPANY', 'value' => 'nope']]],
                ['merge_fields.0.value'],
            ],
            'the same merge tag twice' => [
                ['list' => self::AUDIENCE, 'email_field' => 'email', 'merge_fields' => [
                    ['key' => 'COMPANY', 'value' => 'name'],
                    ['key' => 'COMPANY', 'value' => 'email'],
                ]],
                ['merge_fields.0.key', 'merge_fields.1.key'],
            ],
            'merge tag handled by a dedicated field' => [
                ['list' => self::AUDIENCE, 'email_field' => 'email', 'merge_fields' => [['key' => 'FNAME', 'value' => 'name']]],
                ['merge_fields.0.key'],
            ],
            'group without a form field' => [
                ['list' => self::AUDIENCE, 'email_field' => 'email', 'groups' => [['group' => '4e4b2bc6ae']]],
                ['groups.0.field'],
            ],
            'the same group twice' => [
                ['list' => self::AUDIENCE, 'email_field' => 'email', 'groups' => [
                    ['group' => '4e4b2bc6ae', 'field' => 'consent'],
                    ['group' => '4e4b2bc6ae', 'field' => 'name'],
                ]],
                ['groups.0.group', 'groups.1.group'],
            ],
            'group without an option when the form field has some' => [
                ['list' => self::AUDIENCE, 'email_field' => 'email', 'groups' => [['group' => '4e4b2bc6ae', 'field' => 'interests']]],
                ['groups.0.option'],
            ],
            'group with an unknown form field' => [
                ['list' => self::AUDIENCE, 'email_field' => 'email', 'groups' => [['group' => '4e4b2bc6ae', 'field' => 'nope']]],
                ['groups.0.field'],
            ],
            'marketing permission without a field' => [
                ['list' => self::AUDIENCE, 'email_field' => 'email', 'marketing_permissions' => [['permission' => 'd60cef6b1f']]],
                ['marketing_permissions.0.field'],
            ],
            'marketing permission with an unknown form field' => [
                ['list' => self::AUDIENCE, 'email_field' => 'email', 'marketing_permissions' => [['permission' => 'd60cef6b1f', 'field' => 'nope']]],
                ['marketing_permissions.0.field'],
            ],
            'the same marketing permission twice' => [
                ['list' => self::AUDIENCE, 'email_field' => 'email', 'marketing_permissions' => [
                    ['permission' => 'd60cef6b1f', 'field' => 'consent'],
                    ['permission' => 'd60cef6b1f', 'field' => 'name'],
                ]],
                ['marketing_permissions.0.permission', 'marketing_permissions.1.permission'],
            ],
        ];
    }

    private function form(?array $config = null, array $connections = []): FormContract
    {
        if ($config) {
            $connections['mailchimp'] = $config;
        }

        return tap(Form::make('test')->formFields([
            'fields' => [
                ['handle' => 'name', 'field' => ['type' => 'text']],
                ['handle' => 'email', 'field' => ['type' => 'text']],
                ['handle' => 'consent', 'field' => ['type' => 'toggle']],
                ['handle' => 'interests', 'field' => ['type' => 'checkboxes', 'options' => ['news' => 'News']]],
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
