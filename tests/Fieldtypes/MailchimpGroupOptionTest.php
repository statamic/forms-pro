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
        ]], new MailchimpGroupOption()->fieldsWithOptions($form));
    }
}
