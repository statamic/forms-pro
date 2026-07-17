import { formatFor } from './formats';
import attachAutocomplete from './autocomplete';

export function init(root = document) {
    root.querySelectorAll('[data-forms-pro-address]').forEach(bind);
}

function bind(el) {
    if (el.formsProAddressBound) return;
    el.formsProAddressBound = true;

    const labels = JSON.parse(el.dataset.labels || '{}');
    const country = input(el, 'country');

    const localize = () => {
        const format = formatFor(country?.value);

        localizeAddressField(el, 'postcode', format.postcode, labels);
        localizeAddressField(el, 'region', format.region, labels, format.region_required);
    };

    country?.addEventListener('change', localize);
    localize();

    if (el.dataset.autocomplete) {
        attachAutocomplete(el, JSON.parse(el.dataset.autocomplete));
    }
}

function input(el, handle) {
    return el.querySelector(`[data-address-input="${handle}"]`);
}

function localizeAddressField(el, handle, labelKey, labels, required = false) {
    const wrapper = el.querySelector(`[data-address-field="${handle}"]`);
    if (!wrapper) return;

    const field = input(wrapper, handle);

    if (field.dataset.originallyRequired === undefined) {
        field.dataset.originallyRequired = field.required;
    }

    if (labelKey === null) {
        wrapper.hidden = true;
        field.required = false;
        return;
    }

    wrapper.hidden = false;
    field.required = required || field.dataset.originallyRequired === 'true';

    const label = wrapper.querySelector('label');

    if (label && labels[labelKey]) {
        label.textContent = labels[labelKey];
    }
}
