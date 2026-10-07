<?php

namespace Tests\FormConnectors\Mailchimp;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Contracts\Forms\Form as FormContract;
use Statamic\Facades\Form;
use Statamic\Facades\User;
use Statamic\Testing\Concerns\FakesRoles;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Tests\TestCase;

class ListMailchimpMarketingPermissionsTest extends TestCase
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
    public function it_lists_the_marketing_permissions_for_an_audience()
    {
        $this->fakeAudience(gdpr: true, members: [
            ['marketing_permissions' => [
                ['marketing_permission_id' => 'd60cef6b1f', 'text' => 'Email', 'enabled' => false],
            ]],
            ['marketing_permissions' => [
                ['marketing_permission_id' => 'd60cef6b1f', 'text' => 'Email', 'enabled' => true],
                ['marketing_permission_id' => '3560e0d4a2', 'text' => 'Direct Mail', 'enabled' => false],
            ]],
        ]);

        $this
            ->actingAs($this->userWithPermission())
            ->getJson($this->url())
            ->assertOk()
            ->assertExactJson([
                'state' => 'ok',
                'permissions' => [
                    ['id' => 'd60cef6b1f', 'text' => 'Email'],
                    ['id' => '3560e0d4a2', 'text' => 'Direct Mail'],
                ],
                'error' => null,
            ]);

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'lists/'.self::AUDIENCE.'/members'));
    }

    #[Test]
    public function it_doesnt_look_for_permissions_when_the_audience_has_no_gdpr_fields()
    {
        $this->fakeAudience(gdpr: false);

        $this
            ->actingAs($this->userWithPermission())
            ->getJson($this->url())
            ->assertOk()
            ->assertExactJson(['state' => 'not_enabled', 'permissions' => [], 'error' => null]);

        Http::assertSentCount(1);
    }

    #[Test]
    public function it_tells_you_when_the_audience_has_no_contacts_to_read_permissions_from()
    {
        $this->fakeAudience(gdpr: true, members: []);

        $this
            ->actingAs($this->userWithPermission())
            ->getJson($this->url())
            ->assertOk()
            ->assertExactJson(['state' => 'no_contacts', 'permissions' => [], 'error' => null]);
    }

    #[Test]
    public function it_returns_the_error_when_mailchimp_is_unavailable()
    {
        Http::fake(['*/lists/'.self::AUDIENCE => Http::response([], 500)]);

        $this
            ->actingAs($this->userWithPermission())
            ->getJson($this->url())
            ->assertOk()
            ->assertExactJson(['state' => null, 'permissions' => [], 'error' => 'Mailchimp responded with a [500] status code.']);
    }

    #[Test]
    public function it_doesnt_cache_the_empty_state()
    {
        $this->fakeAudience(gdpr: true, members: []);

        $user = $this->userWithPermission();

        $this->actingAs($user)->getJson($this->url())->assertOk();
        $this->actingAs($user)->getJson($this->url())->assertOk();

        Http::assertSentCount(4);
    }

    #[Test]
    public function it_requires_an_audience()
    {
        Http::fake();

        $this
            ->actingAs($this->userWithPermission())
            ->getJson(cp_route('forms.connect.mailchimp.marketing-permissions', $this->form()->handle()))
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

    private function fakeAudience(bool $gdpr, array $members = []): void
    {
        Http::fake([
            '*/lists/'.self::AUDIENCE.'/members*' => Http::response(['members' => $members]),
            '*/lists/'.self::AUDIENCE => Http::response(['marketing_permissions' => $gdpr]),
        ]);
    }

    private function url(): string
    {
        return cp_route('forms.connect.mailchimp.marketing-permissions', $this->form()->handle()).'?list='.self::AUDIENCE;
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
