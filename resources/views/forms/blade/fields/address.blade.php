<div
    data-forms-pro-address
    data-labels="{{ $address_labels }}"
    @if ($autocomplete) data-autocomplete="{{ $autocomplete }}" @endif
    @if (isset($js_driver)) {!! $js_attributes !!} @endif
>
    @foreach ($address_fields as $addressField)
        <div data-address-field="{{ $addressField['address_handle'] }}">
            <label for="{{ $addressField['id'] }}">{{ $addressField['display'] }}</label>
            @if ($addressField['address_handle'] === 'country')
                <select
                    id="{{ $addressField['id'] }}"
                    name="{{ $addressField['name'] }}"
                    autocomplete="country"
                    data-address-input="country"
                    @if (str_contains($js_driver ?? '', 'alpine')) x-model="{{ $addressField['handle'] }}" @endif
                    @required(in_array('required', $addressField['validate'] ?? []))
                    @if ($addressField['error']) aria-invalid="true"
                    aria-describedby="{{ $addressField['id'] }}-error" @endif
                >
                    <option value=""></option>
                    @foreach ($countries as $country)
                        <option value="{{ $country['code'] }}" @selected($country['code'] === $addressField['value'])>{{ $country['name'] }}</option>
                    @endforeach
                </select>
            @else
                <input
                    type="text"
                    id="{{ $addressField['id'] }}"
                    name="{{ $addressField['name'] }}"
                    value="{{ $addressField['value'] ?? '' }}"
                    autocomplete="{{ $addressField['html_autocomplete'] }}"
                    data-address-input="{{ $addressField['address_handle'] }}"
                    @if (str_contains($js_driver ?? '', 'alpine')) x-model="{{ $addressField['handle'] }}" @endif
                    @required(in_array('required', $addressField['validate'] ?? []))
                    @if ($addressField['error']) aria-invalid="true"
                    aria-describedby="{{ $addressField['id'] }}-error" @endif
                >
            @endif
            @if ($addressField['error'])
                <p id="{{ $addressField['id'] }}-error" data-address-error>{{ $addressField['error'] }}</p>
            @endif
        </div>
    @endforeach

    @if ($autocomplete)
        {{--
            The autocomplete dropdown. Cloned after the "Line 1" input is focused.
            - `data-address-option` is an individual suggestion.
            - `data-address-attribution` displays attribution for Google Maps or Geoapify.
        --}}
        <template data-address-suggestions>
            <ul>
                <li data-address-option><span data-address-label></span></li>
                <li data-address-attribution></li>
            </ul>
        </template>
    @endif
</div>
