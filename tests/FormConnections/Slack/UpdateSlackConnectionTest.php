<?php

namespace Tests\FormConnections\Slack;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Form;
use Statamic\Facades\User;
use Statamic\Testing\Concerns\FakesRoles;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Tests\TestCase;

class UpdateSlackConnectionTest extends TestCase
{
    use FakesRoles;
    use PreventsSavingStacheItemsToDisk;

    #[Test]
    public function it_denies_access_if_you_dont_have_permission()
    {
        $form = tap(Form::make('test')->connections([
            'slack' => [['id' => 'abc', 'channel' => 'C0123ABCDEF']],
        ]))->save();

        $this
            ->from('/original')
            ->actingAs($this->userWithoutPermission())
            ->update($form, ['configs' => []])
            ->assertRedirect('/original')
            ->assertSessionHas('error');

        $this->assertEquals([['id' => 'abc', 'channel' => 'C0123ABCDEF']], Form::find('test')->connections()->get('slack'));
    }

    #[Test]
    public function it_updates_the_slack_configs()
    {
        $form = tap(Form::make('test')->connections([
            'webhook' => [['id' => 'abc', 'url' => 'https://example.com/hook']],
            'slack' => [['id' => 'def', 'channel' => 'C0123ABCDEF']],
        ]))->save();

        $this
            ->actingAs($this->userWithPermission())
            ->update($form, ['configs' => [
                ['id' => 'def', 'channel' => 'C0456DEFGHI', 'enabled' => false],
                ['id' => 'ghi', 'channel' => 'C0789JKLMNO', 'include_fields' => false, 'link_to_submission' => false],
                ['id' => 'jkl', 'channel' => 'C0123ABCDEF', 'enabled' => true, 'include_fields' => true, 'link_to_submission' => true],
            ]])
            ->assertOk();

        $updated = Form::find('test');
        $this->assertEquals([
            ['id' => 'def', 'channel' => 'C0456DEFGHI', 'enabled' => false],
            ['id' => 'ghi', 'channel' => 'C0789JKLMNO', 'include_fields' => false, 'link_to_submission' => false],
            ['id' => 'jkl', 'channel' => 'C0123ABCDEF'],
        ], $updated->connections()->get('slack'));
        $this->assertEquals([['id' => 'abc', 'url' => 'https://example.com/hook']], $updated->connections()->get('webhook'));
    }

    #[Test]
    public function it_doesnt_persist_the_client_row_state()
    {
        $form = tap(Form::make('test'))->save();

        $this
            ->actingAs($this->userWithPermission())
            ->update($form, ['configs' => [['_id' => 'vue-row', 'id' => 'abc', 'channel' => 'C0123ABCDEF']]])
            ->assertOk();

        $this->assertEquals([['id' => 'abc', 'channel' => 'C0123ABCDEF']], Form::find('test')->connections()->get('slack'));
    }

    #[Test]
    public function it_normalizes_the_conditions()
    {
        $form = tap(Form::make('test'))->save();

        $this
            ->actingAs($this->userWithPermission())
            ->update($form, ['configs' => [
                [
                    'id' => 'abc',
                    'channel' => 'C0123ABCDEF',
                    'conditions' => [
                        ['_id' => 'vue-row', 'field' => 'name', 'operator' => 'equals', 'value' => 'Bob', 'join' => 'and'],
                        ['field' => null, 'operator' => 'equals', 'value' => 'incomplete', 'join' => 'and'],
                    ],
                ],
                ['id' => 'def', 'channel' => 'C0456DEFGHI', 'conditions' => []],
            ]])
            ->assertOk();

        $this->assertEquals([
            [
                'id' => 'abc',
                'channel' => 'C0123ABCDEF',
                'conditions' => [['field' => 'name', 'operator' => 'equals', 'value' => 'Bob', 'join' => 'and']],
            ],
            ['id' => 'def', 'channel' => 'C0456DEFGHI'],
        ], Form::find('test')->connections()->get('slack'));
    }

    #[Test]
    public function it_removes_null_values_from_configs()
    {
        $form = tap(Form::make('test'))->save();

        $this
            ->actingAs($this->userWithPermission())
            ->update($form, ['configs' => [[
                'id' => 'abc',
                'channel' => 'C0123ABCDEF',
                'heading' => null,
                'enabled' => null,
            ]]])
            ->assertOk();

        $this->assertEquals([['id' => 'abc', 'channel' => 'C0123ABCDEF']], Form::find('test')->connections()->get('slack'));
    }

    #[Test]
    #[DataProvider('invalidPayloads')]
    public function it_rejects_invalid_payloads($payload, $errors)
    {
        $form = tap(Form::make('test')->formFields([
            'fields' => [['handle' => 'name', 'field' => ['type' => 'text']]],
        ])->connections([
            'slack' => [['id' => 'abc', 'channel' => 'C0123ABCDEF']],
        ]))->save();

        $this
            ->actingAs($this->userWithPermission())
            ->updateJson($form, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($errors);

        $this->assertEquals([['id' => 'abc', 'channel' => 'C0123ABCDEF']], Form::find('test')->connections()->get('slack'));
    }

    public static function invalidPayloads()
    {
        return [
            'missing configs' => [[], ['configs']],
            'configs not an array' => [['configs' => 'nope'], ['configs']],
            'config not an array' => [['configs' => ['nope']], ['configs.0']],
            'config without a channel' => [['configs' => [['enabled' => true]]], ['configs.0.channel']],
            'config with a channel name' => [['configs' => [['channel' => '#enquiries']]], ['configs.0.channel']],
        ];
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

    private function update($form, $params = [])
    {
        return $this->patch(cp_route('forms.connect.slack.update', $form->handle()), $params);
    }

    private function updateJson($form, $params = [])
    {
        return $this->patchJson(cp_route('forms.connect.slack.update', $form->handle()), $params);
    }
}
