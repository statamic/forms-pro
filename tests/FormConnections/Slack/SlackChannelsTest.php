<?php

namespace Tests\FormConnections\Slack;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Form;
use Statamic\Facades\User;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Tests\FakesRoles;
use Tests\TestCase;

class SlackChannelsTest extends TestCase
{
    use FakesRoles;
    use PreventsSavingStacheItemsToDisk;

    protected function setUp(): void
    {
        parent::setUp();

        config(['forms-pro.connections.slack.token' => 'xoxb-token']);
    }

    #[Test]
    public function it_denies_access_if_you_dont_have_permission()
    {
        Http::fake();

        $this
            ->actingAs($this->userWithoutPermission())
            ->channels($this->form())
            ->assertForbidden();

        Http::assertNothingSent();
    }

    #[Test]
    public function it_returns_the_channels()
    {
        Http::fake(['slack.com/*' => Http::response([
            'ok' => true,
            'channels' => [
                ['id' => 'C0456DEFGHI', 'name' => 'general', 'is_private' => false],
                ['id' => 'C0123ABCDEF', 'name' => 'enquiries', 'is_private' => true],
            ],
        ])]);

        $this
            ->actingAs($this->userWithPermission())
            ->channels($this->form())
            ->assertOk()
            ->assertExactJson(['channels' => [
                ['id' => 'C0123ABCDEF', 'name' => 'enquiries', 'is_private' => true],
                ['id' => 'C0456DEFGHI', 'name' => 'general', 'is_private' => false],
            ]]);
    }

    #[Test]
    public function it_follows_the_cursor_until_every_channel_has_been_fetched()
    {
        Http::fake(['slack.com/*' => Http::sequence()
            ->push([
                'ok' => true,
                'channels' => [['id' => 'C0123ABCDEF', 'name' => 'enquiries']],
                'response_metadata' => ['next_cursor' => 'page-two'],
            ])
            ->push([
                'ok' => true,
                'channels' => [['id' => 'C0456DEFGHI', 'name' => 'general']],
                'response_metadata' => ['next_cursor' => ''],
            ]),
        ]);

        $this
            ->actingAs($this->userWithPermission())
            ->channels($this->form())
            ->assertOk()
            ->assertJsonCount(2, 'channels');

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => $request['cursor'] === 'page-two');
    }

    #[Test]
    public function it_caches_the_channels()
    {
        Http::fake(['slack.com/*' => Http::response(['ok' => true, 'channels' => []])]);

        $this->actingAs($this->userWithPermission());

        $this->channels($form = $this->form())->assertOk();
        $this->channels($form)->assertOk();

        Http::assertSentCount(1);
        $this->assertNotNull(Cache::get('forms-pro.slack.channels'));
    }

    #[Test]
    public function it_returns_the_slack_error_when_the_token_is_invalid()
    {
        Http::fake(['slack.com/*' => Http::response(['ok' => false, 'error' => 'invalid_auth'])]);

        $this
            ->actingAs($this->userWithPermission())
            ->channels($this->form())
            ->assertUnprocessable()
            ->assertJson(['message' => 'Slack rejected your token. Check the FORMS_PRO_SLACK_TOKEN environment variable.']);
    }

    private function form()
    {
        return tap(Form::make('test'))->save();
    }

    private function channels($form)
    {
        return $this->getJson(cp_route('forms.connect.slack.channels', $form->handle()));
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
