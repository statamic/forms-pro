<?php

namespace Tests\FormConnectors\Slack;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Contracts\Forms\Form as FormContract;
use Statamic\Contracts\Forms\Submission;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Form;
use Statamic\Facades\Site;
use Statamic\FormsPro\FormConnectors\Slack\SendSlackMessage;
use Statamic\FormsPro\FormConnectors\Slack\SlackClient;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Tests\FormConnectors\AssertsFailedJobs;
use Tests\TestCase;

class SendSlackMessageTest extends TestCase
{
    use AssertsFailedJobs;
    use PreventsSavingStacheItemsToDisk;

    protected function setUp(): void
    {
        parent::setUp();

        config(['statamic.forms-pro.connections.slack.token' => 'xoxb-token']);
    }

    #[Test]
    public function it_posts_the_message_to_slack()
    {
        Http::fake(['slack.com/*' => Http::response(['ok' => true])]);

        $this->send($this->form(), ['name' => 'Jack Black']);

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://slack.com/api/chat.postMessage'
                && $request->hasHeader('Authorization', 'Bearer xoxb-token')
                && $request['channel'] === 'C0123ABCDEF'
                && $request['unfurl_links'] === false;
        });
    }

    #[Test]
    public function it_includes_a_heading_with_the_form_title()
    {
        $blocks = $this->blocksForForm($this->form(), ['name' => 'Jack Black']);

        $this->assertEquals('header', $blocks[0]['type']);
        $this->assertEquals('New Contact Us submission', $blocks[0]['text']['text']);
    }

    #[Test]
    public function it_uses_the_configured_heading()
    {
        $blocks = $this->blocksForForm($this->form(), ['name' => 'Jack Black'], [
            'heading' => 'New enquiry from {{ name }}',
        ]);

        $this->assertEquals('New enquiry from Jack Black', $blocks[0]['text']['text']);
    }

    #[Test]
    public function it_truncates_a_heading_to_slacks_limit()
    {
        $blocks = $this->blocksForForm($this->form(), ['name' => str_repeat('a', 300)], [
            'heading' => 'New enquiry from {{ name }}',
        ]);

        $this->assertEquals(150, mb_strlen($blocks[0]['text']['text']));
        $this->assertStringEndsWith('…', $blocks[0]['text']['text']);
    }

    #[Test]
    public function it_sets_a_plain_text_fallback()
    {
        Http::fake(['slack.com/*' => Http::response(['ok' => true])]);

        $this->send($this->form(), ['name' => 'Jack Black']);

        Http::assertSent(fn (Request $request) => $request['text'] === 'New Contact Us submission');
    }

    #[Test]
    public function it_escapes_mrkdwn_in_the_plain_text_fallback()
    {
        Http::fake(['slack.com/*' => Http::response(['ok' => true])]);

        $this->send($this->form(), ['name' => '<!channel>'], [
            'heading' => 'New enquiry from {{ name }}',
        ]);

        Http::assertSent(fn (Request $request) => $request['text'] === 'New enquiry from &lt;!channel&gt;');
    }

    #[Test]
    public function it_includes_all_fields_by_default()
    {
        $blocks = $this->blocksForForm($this->form(), ['name' => 'Jack Black', 'email' => 'jack@example.com']);

        $this->assertEquals([
            ['type' => 'mrkdwn', 'text' => "*Name*\nJack Black"],
            ['type' => 'mrkdwn', 'text' => "*Email*\njack@example.com"],
        ], $blocks[1]['fields']);
    }

    #[Test]
    public function it_only_includes_the_selected_fields()
    {
        $blocks = $this->blocksForForm($this->form(), ['name' => 'Jack Black', 'email' => 'jack@example.com'], [
            'fields' => ['email'],
        ]);

        $this->assertEquals([['type' => 'mrkdwn', 'text' => "*Email*\njack@example.com"]], $blocks[1]['fields']);
    }

    #[Test]
    public function it_omits_the_fields_when_they_arent_included()
    {
        $blocks = $this->blocksForForm($this->form(), ['name' => 'Jack Black'], ['include_fields' => false]);

        $this->assertEquals(['header', 'actions', 'context'], collect($blocks)->pluck('type')->all());
    }

    #[Test]
    public function it_excludes_information_fields()
    {
        $form = $this->form([
            ['handle' => 'about_you', 'field' => ['type' => 'heading', 'display' => 'About You']],
            ['handle' => 'name', 'field' => ['type' => 'text']],
        ]);

        $blocks = $this->blocksForForm($form, ['about_you' => 'About You', 'name' => 'Jack Black']);

        $this->assertEquals([['type' => 'mrkdwn', 'text' => "*Name*\nJack Black"]], $blocks[1]['fields']);
    }

    #[Test]
    public function it_skips_blank_values()
    {
        $blocks = $this->blocksForForm($this->form(), ['name' => 'Jack Black', 'email' => null]);

        $this->assertEquals([['type' => 'mrkdwn', 'text' => "*Name*\nJack Black"]], $blocks[1]['fields']);
    }

    #[Test]
    public function it_puts_long_values_in_their_own_section()
    {
        $form = $this->form([
            ['handle' => 'name', 'field' => ['type' => 'text']],
            ['handle' => 'message', 'field' => ['type' => 'textarea']],
        ]);

        $blocks = $this->blocksForForm($form, ['name' => 'Jack Black', 'message' => "Hey there.\nLet's talk."]);

        $this->assertEquals([['type' => 'mrkdwn', 'text' => "*Name*\nJack Black"]], $blocks[1]['fields']);
        $this->assertEquals(['type' => 'mrkdwn', 'text' => "*Message*\nHey there.\nLet's talk."], $blocks[2]['text']);
    }

    #[Test]
    #[DataProvider('optionFieldProvider')]
    public function it_renders_option_labels($field, $value, $expected)
    {
        $form = $this->form([['handle' => 'choice', 'field' => $field]]);

        $blocks = $this->blocksForForm($form, ['choice' => $value]);

        $this->assertEquals("*Choice*\n{$expected}", $blocks[1]['fields'][0]['text']);
    }

    public static function optionFieldProvider()
    {
        $options = ['sales' => 'Sales Enquiry', 'support' => 'Support Request'];

        return [
            'select' => [['type' => 'select', 'options' => $options], 'sales', 'Sales Enquiry'],
            'select without a label' => [['type' => 'select', 'options' => ['sales' => null]], 'sales', 'sales'],
            'radio' => [['type' => 'radio', 'options' => $options], 'support', 'Support Request'],
            'multiple select' => [['type' => 'select', 'multiple' => true, 'options' => $options], ['sales', 'support'], 'Sales Enquiry, Support Request'],
            'checkboxes' => [['type' => 'checkboxes', 'options' => $options], ['sales'], 'Sales Enquiry'],
            'zero' => [['type' => 'radio', 'options' => ['0' => '0', '5' => '5']], '0', '0'],
        ];
    }

    #[Test]
    public function it_renders_stored_uploads_as_links()
    {
        Storage::fake('test', ['url' => 'http://localhost/assets']);
        Storage::disk('test')->put('cv.pdf', '');
        AssetContainer::make('documents')->disk('test')->save();

        $form = $this->form([['handle' => 'cv', 'field' => ['type' => 'form_upload', 'store' => true, 'container' => 'documents']]]);

        $blocks = $this->blocksForForm($form, ['cv' => ['cv.pdf']]);

        $this->assertEquals("*Cv*\n<http://localhost/assets/cv.pdf|cv.pdf>", $blocks[1]['fields'][0]['text']);
    }

    #[Test]
    public function it_renders_stored_uploads_in_a_private_container_as_filenames()
    {
        Storage::fake('test', ['url' => null]);
        Storage::disk('test')->put('cv.pdf', '');
        AssetContainer::make('documents')->disk('test')->save();

        $form = $this->form([['handle' => 'cv', 'field' => ['type' => 'form_upload', 'store' => true, 'container' => 'documents']]]);

        $blocks = $this->blocksForForm($form, ['cv' => ['cv.pdf']]);

        $this->assertEquals("*Cv*\ncv.pdf", $blocks[1]['fields'][0]['text']);
    }

    #[Test]
    public function it_renders_unstored_uploads_as_filenames()
    {
        $form = $this->form([['handle' => 'cv', 'field' => ['type' => 'form_upload']]]);

        $blocks = $this->blocksForForm($form, ['cv' => ['1785861616.23/cv/cv.pdf']]);

        $this->assertEquals("*Cv*\ncv.pdf", $blocks[1]['fields'][0]['text']);
    }

    #[Test]
    public function it_escapes_mrkdwn_characters()
    {
        $blocks = $this->blocksForForm($this->form(), ['name' => 'Jack & <Jill>']);

        $this->assertEquals("*Name*\nJack &amp; &lt;Jill&gt;", $blocks[1]['fields'][0]['text']);
    }

    #[Test]
    public function it_truncates_long_values()
    {
        $form = $this->form([['handle' => 'heading', 'field' => ['type' => 'textarea']]]);

        $blocks = $this->blocksForForm($form, ['heading' => str_repeat('a', 4000)]);

        $this->assertEquals(3000, mb_strlen($blocks[1]['text']['text']));
        $this->assertStringEndsWith('…', $blocks[1]['text']['text']);
    }

    #[Test]
    public function it_caps_the_number_of_blocks()
    {
        $fields = collect(range(1, 60))
            ->map(fn ($i) => ['handle' => "field_{$i}", 'field' => ['type' => 'textarea']])
            ->all();

        $data = collect(range(1, 60))->mapWithKeys(fn ($i) => ["field_{$i}" => "line one\nline two"])->all();

        $blocks = $this->blocksForForm($this->form($fields), $data);

        $this->assertCount(50, $blocks);
        $this->assertEquals('context', $blocks[47]['type']);
        $this->assertStringContainsString('too long', $blocks[47]['elements'][0]['text']);
    }

    #[Test]
    public function it_links_to_the_submission()
    {
        $submission = $this->submission($this->form(), ['name' => 'Jack Black']);

        $blocks = $this->blocksForSubmission($submission);
        $button = collect($blocks)->firstWhere('type', 'actions')['elements'][0];

        $this->assertEquals('View submission', $button['text']['text']);
        $this->assertEquals(cp_route('forms.submissions.show', ['contact_us', $submission->id()]), $button['url']);
    }

    #[Test]
    public function it_omits_the_submission_link_when_disabled()
    {
        $blocks = $this->blocksForForm($this->form(), ['name' => 'Jack Black'], ['link_to_submission' => false]);

        $this->assertNull(collect($blocks)->firstWhere('type', 'actions'));
    }

    #[Test]
    public function it_includes_a_context_footer()
    {
        $blocks = $this->blocksForForm($this->form(), ['name' => 'Jack Black']);
        $context = collect($blocks)->last();

        $this->assertEquals('context', $context['type']);
        $this->assertStringStartsWith('Contact Us · default · <!date^', $context['elements'][0]['text']);
    }

    #[Test]
    public function it_joins_the_channel_and_retries_when_the_bot_isnt_in_it()
    {
        Http::fake([
            'slack.com/api/chat.postMessage' => Http::sequence()
                ->push(['ok' => false, 'error' => 'not_in_channel'])
                ->push(['ok' => true]),
            'slack.com/api/conversations.join' => Http::response(['ok' => true]),
        ]);

        $this->send($this->form(), ['name' => 'Jack Black']);

        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://slack.com/api/conversations.join' && $request['channel'] === 'C0123ABCDEF');
    }

    #[Test]
    public function it_releases_the_job_when_rate_limited()
    {
        Http::fake(['slack.com/*' => Http::response(['ok' => false, 'error' => 'ratelimited'], 429, ['Retry-After' => 42])]);

        $queueJob = Mockery::mock(Job::class);
        $queueJob->shouldReceive('release')->once()->with(42);

        $this->job($this->form(), ['name' => 'Jack Black'])->setJob($queueJob)->handle(app(SlackClient::class));
    }

    #[Test]
    public function it_releases_the_job_when_slack_is_unavailable()
    {
        Http::fake(['slack.com/*' => Http::response(null, 503)]);

        $queueJob = Mockery::mock(Job::class);
        $queueJob->shouldReceive('release')->once()->with(30);

        $this->job($this->form(), ['name' => 'Jack Black'])->setJob($queueJob)->handle(app(SlackClient::class));
    }

    #[Test]
    #[DataProvider('fatalErrorProvider')]
    public function it_fails_the_job_on_fatal_errors($error)
    {
        Http::fake(['slack.com/*' => Http::response(['ok' => false, 'error' => $error])]);

        $this->assertJobFails($this->job($this->form(), ['name' => 'Jack Black']), "Error from Slack: [{$error}].");

        Http::assertSentCount(1);
    }

    public static function fatalErrorProvider()
    {
        return [
            'channel not found' => ['channel_not_found'],
            'invalid auth' => ['invalid_auth'],
            'archived channel' => ['is_archived'],
            'missing scope' => ['missing_scope'],
        ];
    }

    #[Test]
    public function it_doesnt_send_when_no_token_has_been_configured()
    {
        config(['statamic.forms-pro.connections.slack.token' => null]);

        Http::fake();

        $this->assertJobFails($this->job($this->form(), ['name' => 'Jack Black']), 'FORMS_PRO_SLACK_TOKEN');

        Http::assertNothingSent();
    }

    private function form(?array $fields = null): FormContract
    {
        return tap(Form::make('contact_us')->title('Contact Us')->formFields([
            'fields' => $fields ?? [
                ['handle' => 'name', 'field' => ['type' => 'text']],
                ['handle' => 'email', 'field' => ['type' => 'text']],
            ],
        ]))->save();
    }

    private function submission(FormContract $form, array $data): Submission
    {
        return $form->makeSubmission()->data($data);
    }

    private function config(array $overrides = []): array
    {
        return array_merge(['id' => 'abc', 'channel' => 'C0123ABCDEF', 'channel_name' => 'enquiries'], $overrides);
    }

    private function job(FormContract $form, array $data = [], array $config = []): SendSlackMessage
    {
        return new SendSlackMessage($this->submission($form, $data), Site::default(), $this->config($config));
    }

    private function send(FormContract $form, array $data, array $config = [])
    {
        $this->job($form, $data, $config)->handle(app(SlackClient::class));
    }

    private function blocksForForm(FormContract $form, array $data, array $config = []): array
    {
        return $this->blocksForSubmission($this->submission($form, $data), $config);
    }

    private function blocksForSubmission(Submission $submission, array $config = []): array
    {
        Http::fake(['slack.com/*' => Http::response(['ok' => true])]);

        (new SendSlackMessage($submission, Site::default(), $this->config($config)))->handle(app(SlackClient::class));

        return Http::recorded()->first()[0]->data()['blocks'];
    }
}
