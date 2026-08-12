<?php

namespace Tests\FormConnections\Twilio;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Form;
use Statamic\Facades\User;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Tests\FakesRoles;
use Tests\TestCase;

class UpdateTwilioConnectionTest extends TestCase
{
    use FakesRoles;
    use PreventsSavingStacheItemsToDisk;

    #[Test]
    public function it_denies_access_if_you_dont_have_permission()
    {
        $form = $this->form([['id' => 'abc', 'to' => '+447700900123', 'from' => '+14155552671', 'body' => 'New enquiry']]);

        $this
            ->from('/original')
            ->actingAs($this->userWithoutPermission())
            ->update($form, ['configs' => []])
            ->assertRedirect('/original')
            ->assertSessionHas('error');

        $this->assertCount(1, Form::find('test')->connections()->get('twilio'));
    }

    #[Test]
    public function it_updates_the_twilio_configs()
    {
        $form = tap(Form::make('test')->formFields([
            'fields' => [['handle' => 'phone', 'field' => ['type' => 'phone']]],
        ])->connections([
            'webhook' => [['id' => 'abc', 'url' => 'https://example.com/hook']],
            'twilio' => [['id' => 'def', 'to' => '+447700900123', 'from' => '+14155552671', 'body' => 'Old']],
        ]))->save();

        $this
            ->actingAs($this->userWithPermission())
            ->update($form, ['configs' => [
                ['id' => 'def', 'to' => '+447700900124', 'from' => '+14155552671', 'body' => 'New', 'enabled' => false],
                ['id' => 'ghi', 'to' => 'field:phone', 'from' => 'MG0123456789abcdef0123456789abcdef', 'body' => 'Thanks!', 'country_code' => '+44'],
            ]])
            ->assertOk();

        $updated = Form::find('test');
        $this->assertEquals([
            ['id' => 'def', 'to' => '+447700900124', 'from' => '+14155552671', 'body' => 'New', 'enabled' => false],
            ['id' => 'ghi', 'to' => 'field:phone', 'from' => 'MG0123456789abcdef0123456789abcdef', 'body' => 'Thanks!', 'country_code' => '+44'],
        ], $updated->connections()->get('twilio'));
        $this->assertEquals([['id' => 'abc', 'url' => 'https://example.com/hook']], $updated->connections()->get('webhook'));
    }

    #[Test]
    public function it_doesnt_persist_the_client_row_state()
    {
        $form = $this->form();

        $this
            ->actingAs($this->userWithPermission())
            ->update($form, ['configs' => [[
                '_id' => 'vue-row',
                'id' => 'abc',
                'to' => '+447700900123',
                'from' => '+14155552671',
                'body' => 'New enquiry',
                'country_code' => null,
                'enabled' => null,
            ]]])
            ->assertOk();

        $this->assertEquals(
            [['id' => 'abc', 'to' => '+447700900123', 'from' => '+14155552671', 'body' => 'New enquiry']],
            Form::find('test')->connections()->get('twilio')
        );
    }

    #[Test]
    public function it_normalizes_the_conditions()
    {
        $form = $this->form();

        $this
            ->actingAs($this->userWithPermission())
            ->update($form, ['configs' => [
                [
                    'id' => 'abc',
                    'to' => '+447700900123',
                    'from' => '+14155552671',
                    'body' => 'New enquiry',
                    'conditions' => [
                        ['_id' => 'vue-row', 'field' => 'phone', 'operator' => 'equals', 'value' => 'Bob', 'join' => 'and'],
                        ['field' => null, 'operator' => 'equals', 'value' => 'incomplete', 'join' => 'and'],
                    ],
                ],
            ]])
            ->assertOk();

        $this->assertEquals([[
            'id' => 'abc',
            'to' => '+447700900123',
            'from' => '+14155552671',
            'body' => 'New enquiry',
            'conditions' => [['field' => 'phone', 'operator' => 'equals', 'value' => 'Bob', 'join' => 'and']],
        ]], Form::find('test')->connections()->get('twilio'));
    }

    #[Test]
    #[DataProvider('invalidPayloads')]
    public function it_rejects_invalid_payloads($payload, $errors)
    {
        $form = $this->form([['id' => 'abc', 'to' => '+447700900123', 'from' => '+14155552671', 'body' => 'New enquiry']]);

        $this
            ->actingAs($this->userWithPermission())
            ->updateJson($form, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($errors);

        $this->assertCount(1, Form::find('test')->connections()->get('twilio'));
    }

    public static function invalidPayloads()
    {
        $valid = ['to' => '+447700900123', 'from' => '+14155552671', 'body' => 'New enquiry'];

        return [
            'missing configs' => [[], ['configs']],
            'configs not an array' => [['configs' => 'nope'], ['configs']],
            'config not an array' => [['configs' => ['nope']], ['configs.0']],
            'missing recipient' => [['configs' => [array_diff_key($valid, ['to' => ''])]], ['configs.0.to']],
            'recipient without a country code' => [['configs' => [[...$valid, 'to' => '07700900123']]], ['configs.0.to']],
            'recipient naming an unknown field' => [['configs' => [[...$valid, 'to' => 'field:nope']]], ['configs.0.to']],
            'missing sender' => [['configs' => [array_diff_key($valid, ['from' => ''])]], ['configs.0.from']],
            'sender that isnt a number or service' => [['configs' => [[...$valid, 'from' => 'STATAMIC']]], ['configs.0.from']],
            'missing body' => [['configs' => [array_diff_key($valid, ['body' => ''])]], ['configs.0.body']],
            'body over the segment limit' => [['configs' => [[...$valid, 'body' => str_repeat('a', 1601)]]], ['configs.0.body']],
            'dialling code without a plus' => [['configs' => [[...$valid, 'country_code' => '44']]], ['configs.0.country_code']],
        ];
    }

    private function form(?array $configs = null)
    {
        $form = Form::make('test')->formFields([
            'fields' => [['handle' => 'phone', 'field' => ['type' => 'phone']]],
        ]);

        if ($configs) {
            $form->connections(['twilio' => $configs]);
        }

        return tap($form)->save();
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
        return $this->patch(cp_route('forms.connect.twilio.update', $form->handle()), $params);
    }

    private function updateJson($form, $params = [])
    {
        return $this->patchJson(cp_route('forms.connect.twilio.update', $form->handle()), $params);
    }
}
