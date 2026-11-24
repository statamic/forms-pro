<?php

namespace Tests\Fieldtypes;

use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Form;
use Statamic\FormsPro\Fieldtypes\MailchimpGroupOption;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Tests\TestCase;

class MailchimpGroupOptionTest extends TestCase
{
    use PreventsSavingStacheItemsToDisk;

    #[Test]
    public function it_lists_the_options_of_every_form_field_that_has_some()
    {
        $form = tap(Form::make('test')->formFields(['fields' => [
            ['handle' => 'email', 'field' => ['type' => 'text']],
            ['handle' => 'interests', 'field' => ['type' => 'checkboxes', 'options' => [
                ['key' => 'News', 'value' => null],
                ['key' => 'Offers', 'value' => 'Special offers'],
            ]]],
        ]]))->save();

        $this->assertEquals(['interests' => [
            ['value' => 'News', 'label' => 'News'],
            ['value' => 'Offers', 'label' => 'Special offers'],
        ]], (new MailchimpGroupOption)->fieldsWithOptions($form));
    }

    #[Test]
    public function it_lists_the_options_of_image_choice_fields()
    {
        $form = tap(Form::make('test')->formFields(['fields' => [
            ['handle' => 'plan', 'field' => ['type' => 'image_choice', 'options' => [
                ['key' => 'basic', 'label' => 'Basic'],
                ['key' => 'pro'],
            ]]],
        ]]))->save();

        $this->assertEquals(['plan' => [
            ['value' => 'basic', 'label' => 'Basic'],
            ['value' => 'pro', 'label' => 'pro'],
        ]], (new MailchimpGroupOption)->fieldsWithOptions($form));
    }

    #[Test]
    public function it_ignores_taggable_fields_since_their_options_are_only_suggestions()
    {
        $form = tap(Form::make('test')->formFields(['fields' => [
            ['handle' => 'tags', 'field' => ['type' => 'taggable', 'options' => ['News', 'Offers']]],
        ]]))->save();

        $this->assertEquals([], (new MailchimpGroupOption)->fieldsWithOptions($form));
    }
}
