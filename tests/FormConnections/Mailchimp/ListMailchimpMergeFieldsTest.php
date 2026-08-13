<?php

namespace Tests\FormConnections\Mailchimp;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Contracts\Forms\Form as FormContract;
use Statamic\Facades\Form;
use Statamic\Facades\User;
use Statamic\Testing\Concerns\FakesRoles;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Tests\TestCase;

class ListMailchimpMergeFieldsTest extends TestCase
{
    use FakesRoles;
    use PreventsSavingStacheItemsToDisk;

    private const string AUDIENCE = 'a1b2c3d4e5';

    protected function setUp(): void
    {
        parent::setUp();

        config(['forms-pro.connections.mailchimp.api_key' => 'mailchimp-key-us14']);
    }

    #[Test]
    public function it_lists_the_merge_fields_for_an_audience()
    {
        Http::fake(['us14.api.mailchimp.com/*' => Http::response(['merge_fields' => [
            ['tag' => 'FNAME', 'name' => 'First Name'],
            ['tag' => 'LNAME', 'name' => 'Last Name'],
            ['tag' => 'COMPANY', 'name' => 'Company'],
        ]])]);

        $this
            ->actingAs($this->userWithPermission())
            ->getJson($this->url())
            ->assertOk()
            ->assertExactJson([['tag' => 'COMPANY', 'name' => 'Company']]);

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'lists/'.self::AUDIENCE.'/merge-fields'));
    }

    #[Test]
    public function it_requires_an_audience()
    {
        Http::fake();

        $this
            ->actingAs($this->userWithPermission())
            ->getJson(cp_route('forms.connect.mailchimp.merge-fields', $this->form()->handle()))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['list']);

        Http::assertNothingSent();
    }

    #[Test]
    public function it_denies_access_if_you_dont_have_permission()
    {
        Http::fake();

        $this
            ->from('/original')
            ->actingAs($this->userWithoutPermission())
            ->get($this->url())
            ->assertRedirect('/original');

        Http::assertNothingSent();
    }

    private function url(): string
    {
        return cp_route('forms.connect.mailchimp.merge-fields', $this->form()->handle()).'?list='.self::AUDIENCE;
    }

    private function form(): FormContract
    {
        return tap(Form::make('test')->formFields([
            'fields' => [['handle' => 'email', 'field' => ['type' => 'email']]],
        ]))->save();
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
