<?php

namespace Tests\Fieldtypes;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Form;
use Statamic\Facades\Parse;
use Statamic\Fields\Field;
use Statamic\Fields\Values;
use Statamic\FormsPro\Fieldtypes\Address;
use Statamic\FormsPro\FormFieldtypes\Address as AddressFormFieldtype;
use Tests\TestCase;

class AddressTest extends TestCase
{
    #[Test]
    public function it_pre_processes_to_a_fully_keyed_value()
    {
        $fieldtype = $this->fieldtype(['default_country' => 'GB']);

        $this->assertEquals(
            ['line_1' => null, 'line_2' => null, 'city' => null, 'region' => null, 'postcode' => null, 'country' => 'GB'],
            $fieldtype->preProcess(null)
        );

        $this->assertEquals(
            ['line_1' => '4 Privet Drive', 'line_2' => null, 'city' => 'Little Whinging', 'region' => null, 'postcode' => null, 'country' => 'US'],
            $fieldtype->preProcess(['line_1' => '4 Privet Drive', 'city' => 'Little Whinging', 'country' => 'US'])
        );
    }

    #[Test]
    public function it_processes_submitted_values()
    {
        $fieldtype = $this->fieldtype();

        $this->assertEquals(
            ['line_1' => '4 Privet Drive', 'city' => 'Little Whinging'],
            $fieldtype->process(['line_1' => '4 Privet Drive', 'line_2' => '', 'city' => 'Little Whinging', 'postcode' => null])
        );

        $this->assertNull($fieldtype->process(['line_1' => '', 'city' => '', 'postcode' => null]));
        $this->assertNull($fieldtype->process(null));
    }

    #[Test]
    public function it_builds_dotted_validation_rules()
    {
        $rules = $this->fieldtype(['validate' => ['required']])->extraRules();

        $this->assertContains('required', $rules['address.line_1']);
        $this->assertContains('required', $rules['address.city']);
        $this->assertContains('required', $rules['address.country']);
        $this->assertNotContains('required', $rules['address.postcode']);
        $this->assertNotContains('required', $rules['address.region']);
    }

    #[Test]
    public function address_fields_are_not_required_when_the_field_is_optional()
    {
        $rules = $this->fieldtype()->extraRules();

        $this->assertNotContains('required', $rules['address.line_1']);
        $this->assertNotContains('required', $rules['address.city']);
    }

    #[Test]
    public function it_augments_to_values_with_country_name_and_formatted_address()
    {
        $augmented = $this->fieldtype()->augment([
            'line_1' => '4 Privet Drive',
            'city' => 'Little Whinging',
            'region' => 'Surrey',
            'postcode' => 'LW1 4PD',
            'country' => 'GB',
        ]);

        $this->assertInstanceOf(Values::class, $augmented);
        $this->assertEquals('United Kingdom', $augmented['country_name']);
        $this->assertEquals('4 Privet Drive, Little Whinging, Surrey, LW1 4PD, United Kingdom', $augmented['formatted']);

        $this->assertNull($this->fieldtype()->augment(null));
    }

    #[Test]
    public function it_pre_processes_index_values_to_a_formatted_string()
    {
        $this->assertEquals(
            '4 Privet Drive, Little Whinging, LW1 4PD, United Kingdom',
            $this->fieldtype()->preProcessIndex([
                'line_1' => '4 Privet Drive',
                'city' => 'Little Whinging',
                'postcode' => 'LW1 4PD',
                'country' => 'GB',
            ])
        );

        $this->assertNull($this->fieldtype()->preProcessIndex(null));
    }

    #[Test]
    public function the_form_fieldtype_resolves_the_addon_view()
    {
        $this->assertEquals(
            'forms-pro::forms.antlers.fields.address',
            (new AddressFormFieldtype)->view()
        );
    }

    #[Test]
    #[DataProvider('templateLanguages')]
    public function it_renders_the_frontend_view(string $language)
    {
        config(['statamic.templates.language' => $language]);

        $form = Form::make()->handle('contact')->formFields([
            'sections' => [
                [
                    'fields' => [
                        ['handle' => 'address', 'field' => ['type' => 'address', 'display' => 'Address']],
                    ],
                ],
            ],
        ]);

        Form::shouldReceive('find')->with('contact')->andReturn($form);
        Form::makePartial();

        $html = (string) Parse::template('{{ form:create in="contact" }}{{ form:fields }}{{ field }}{{ /form:fields }}{{ /form:create }}', [], trusted: true);

        $this->assertStringContainsString('data-forms-pro-address', $html);
        $this->assertStringContainsString('name="address[line_1]"', $html);
        $this->assertStringContainsString('name="address[postcode]"', $html);
        $this->assertStringContainsString('data-address-input="country"', $html);
        $this->assertStringContainsString('<option value="GB"', $html);
        $this->assertStringContainsString('autocomplete="address-line1"', $html);
        $this->assertStringNotContainsString('data-autocomplete=', $html);
        $this->assertStringNotContainsString('data-address-suggestions', $html);
    }

    #[Test]
    #[DataProvider('templateLanguages')]
    public function it_renders_the_suggestions_template_when_autocomplete_is_configured(string $language)
    {
        config([
            'statamic.templates.language' => $language,
            'statamic.forms-pro.address.autocomplete.provider' => 'geoapify',
            'statamic.forms-pro.address.autocomplete.key' => 'test-key',
        ]);

        $form = Form::make()->handle('contact')->formFields([
            'sections' => [
                [
                    'fields' => [
                        ['handle' => 'address', 'field' => ['type' => 'address', 'display' => 'Address']],
                    ],
                ],
            ],
        ]);

        Form::shouldReceive('find')->with('contact')->andReturn($form);
        Form::makePartial();

        $html = (string) Parse::template('{{ form:create in="contact" }}{{ form:fields }}{{ field }}{{ /form:fields }}{{ /form:create }}', [], trusted: true);

        $this->assertStringContainsString('<template data-address-suggestions>', $html);
        $this->assertStringContainsString('data-address-option', $html);
        $this->assertStringContainsString('data-address-label', $html);
        $this->assertStringContainsString('data-address-attribution', $html);
    }

    public static function templateLanguages(): array
    {
        return [
            'antlers' => ['antlers'],
            'blade' => ['blade'],
        ];
    }

    #[Test]
    #[DataProvider('templateLanguages')]
    public function only_required_address_fields_get_the_required_attribute(string $language)
    {
        config(['statamic.templates.language' => $language]);

        $form = Form::make()->handle('contact')->formFields([
            'sections' => [
                [
                    'fields' => [
                        ['handle' => 'address', 'field' => ['type' => 'address', 'display' => 'Address', 'validate' => ['required']]],
                    ],
                ],
            ],
        ]);

        Form::shouldReceive('find')->with('contact')->andReturn($form);
        Form::makePartial();

        $html = (string) Parse::template('{{ form:create in="contact" }}{{ form:fields }}{{ field }}{{ /form:fields }}{{ /form:create }}', [], trusted: true);

        $this->assertStringContainsString('required', $this->inputFor($html, 'line_1'));
        $this->assertStringNotContainsString('required', $this->inputFor($html, 'line_2'));
        $this->assertStringNotContainsString('required', $this->inputFor($html, 'region'));
        $this->assertStringNotContainsString('required', $this->inputFor($html, 'postcode'));
    }

    private function inputFor(string $html, string $handle): string
    {
        preg_match('/<input[^>]*data-address-input="'.$handle.'"[^>]*>/s', $html, $matches);

        return $matches[0];
    }

    #[Test]
    #[DataProvider('templateLanguages')]
    public function it_binds_address_fields_to_the_alpine_js_driver(string $language)
    {
        config(['statamic.templates.language' => $language]);

        $form = Form::make()->handle('contact')->formFields([
            'sections' => [
                [
                    'fields' => [
                        ['handle' => 'address', 'field' => ['type' => 'address', 'display' => 'Address', 'default_country' => 'GB']],
                    ],
                ],
            ],
        ]);

        Form::shouldReceive('find')->with('contact')->andReturn($form);
        Form::makePartial();

        $html = (string) Parse::template('{{ form:create in="contact" js="alpine" }}{{ form:fields }}{{ field }}{{ /form:fields }}{{ /form:create }}', [], trusted: true);

        $this->assertStringContainsString('x-model="address.line_1"', $html);
        $this->assertStringContainsString('x-model="address.country"', $html);
        $this->assertStringContainsString('&quot;line_1&quot;:null', $html);
        $this->assertStringContainsString('&quot;country&quot;:&quot;GB&quot;', $html);
    }

    #[Test]
    public function it_exposes_autocomplete_config_when_a_provider_is_configured()
    {
        config([
            'statamic.forms-pro.address.autocomplete.provider' => 'geoapify',
            'statamic.forms-pro.address.autocomplete.key' => 'test-key',
        ]);

        $data = $this->fieldtype()->extraRenderableFieldData();

        $this->assertEquals(
            ['provider' => 'geoapify', 'key' => 'test-key'],
            json_decode($data['autocomplete'], true)
        );
    }

    #[Test]
    public function autocomplete_config_is_omitted_without_a_provider()
    {
        $data = $this->fieldtype()->extraRenderableFieldData();

        $this->assertNull($data['autocomplete']);
    }

    #[Test]
    public function it_preloads_the_autocomplete_config_for_the_vue_fieldtype()
    {
        $this->assertNull($this->fieldtype()->preload()['autocomplete']);

        config([
            'statamic.forms-pro.address.autocomplete.provider' => 'google',
            'statamic.forms-pro.address.autocomplete.key' => 'test-key',
        ]);

        $this->assertEquals(
            ['provider' => 'google', 'key' => 'test-key'],
            $this->fieldtype()->preload()['autocomplete']
        );
    }

    #[Test]
    public function it_generates_a_fake_value()
    {
        $value = $this->fieldtype(['default_country' => 'GB'])->fakeValue();

        $this->assertIsString($value['line_1']);
        $this->assertNotEmpty($value['line_1']);
        $this->assertNull($value['line_2']);
        $this->assertIsString($value['city']);
        $this->assertNotEmpty($value['city']);
        $this->assertIsString($value['region']);
        $this->assertIsString($value['postcode']);
        $this->assertSame('GB', $value['country']);
    }

    private function fieldtype(array $config = []): Address
    {
        $field = new Field('address', array_merge(['type' => 'address'], $config));

        return $field->fieldtype();
    }
}
