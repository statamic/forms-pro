<?php

namespace Tests\FormConnectors\Mailchimp;

use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Contracts\Forms\Form as FormContract;
use Statamic\Facades\Form;
use Statamic\Facades\User;
use Statamic\Testing\Concerns\FakesRoles;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Tests\TestCase;

class ListMailchimpGroupsTest extends TestCase
{
    use FakesRoles;
    use PreventsSavingStacheItemsToDisk;

    private const string AUDIENCE = 'a1b2c3d4e5';

    protected function setUp(): void
    {
        parent::setUp();

        config(['statamic.forms-pro.connections.mailchimp.api_key' => 'mailchimp-key-us14']);
    }

    #[Test]
    public function it_flattens_the_groups_in_every_category()
    {
        Http::fake([
            '*/interest-categories/updates/interests*' => Http::response(['interests' => [
                ['id' => 'f79652f791', 'name' => 'Weekly', 'display_order' => 0],
            ]]),
            '*/interest-categories/interests/interests*' => Http::response(['interests' => [
                ['id' => '3e1e51dbae', 'name' => 'Offers', 'display_order' => 1],
                ['id' => '4e4b2bc6ae', 'name' => 'News', 'display_order' => 0],
            ]]),
            '*/interest-categories*' => Http::response(['categories' => [
                ['id' => 'updates', 'title' => 'Updates', 'display_order' => 1],
                ['id' => 'interests', 'title' => 'Interests', 'display_order' => 0],
            ]]),
        ]);

        $this
            ->actingAs($this->userWithPermission())
            ->getJson($this->url())
            ->assertOk()
            ->assertExactJson(['groups' => [
                ['id' => '4e4b2bc6ae', 'name' => 'News', 'category' => 'Interests'],
                ['id' => '3e1e51dbae', 'name' => 'Offers', 'category' => 'Interests'],
                ['id' => 'f79652f791', 'name' => 'Weekly', 'category' => 'Updates'],
            ], 'error' => null]);
    }

    #[Test]
    public function it_returns_nothing_for_an_audience_without_groups()
    {
        Http::fake(['*/interest-categories*' => Http::response(['categories' => []])]);

        $this
            ->actingAs($this->userWithPermission())
            ->getJson($this->url())
            ->assertOk()
            ->assertExactJson(['groups' => [], 'error' => null]);
    }

    #[Test]
    public function it_returns_the_error_when_mailchimp_is_unavailable()
    {
        Http::fake(['*/interest-categories*' => Http::response([], 500)]);

        $this
            ->actingAs($this->userWithPermission())
            ->getJson($this->url())
            ->assertOk()
            ->assertExactJson(['groups' => [], 'error' => 'Mailchimp responded with a [500] status code.']);
    }

    #[Test]
    public function it_requires_an_audience()
    {
        Http::fake();

        $this
            ->actingAs($this->userWithPermission())
            ->getJson(cp_route('forms.connect.mailchimp.groups', $this->form()->handle()))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['list']);

        Http::assertNothingSent();
    }

    #[Test]
    #[DataProvider('malformedAudiences')]
    public function it_rejects_an_audience_that_isnt_alphanumeric(string $audience)
    {
        Http::fake();

        $this
            ->actingAs($this->userWithPermission())
            ->getJson(cp_route('forms.connect.mailchimp.groups', $this->form()->handle()).'?list='.urlencode($audience))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['list']);

        Http::assertNothingSent();
    }

    public static function malformedAudiences(): array
    {
        return [
            'path separator' => ['a1b2c3d4e5/members'],
            'traversal' => ['../account-info/v3/details'],
            'query string' => ['a1b2c3d4e5?count=1000'],
        ];
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
        return cp_route('forms.connect.mailchimp.groups', $this->form()->handle()).'?list='.self::AUDIENCE;
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
