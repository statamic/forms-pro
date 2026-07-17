import providers from './providers';

const DEBOUNCE_MS = 250;
const MIN_QUERY_LENGTH = 3;

export default function attachAutocomplete(el, config) {
    const createProvider = providers[config.provider];
    const target = el.querySelector('[data-address-input="line_1"]');
    const template = el.querySelector('template[data-address-suggestions]');

    if (!createProvider || !target || !template) return;

    const provider = createProvider();

    let timeout = null;
    let requestId = 0;
    let activeIndex = -1;
    let suggestions = [];

    const { list, option, attribution } = createList(template, target, provider);

    list.addEventListener('mousedown', (e) => e.preventDefault());

    target.setAttribute('role', 'combobox');
    target.setAttribute('aria-expanded', 'false');
    target.setAttribute('aria-controls', list.id);
    target.setAttribute('aria-autocomplete', 'list');
    target.autocomplete = 'off';

    const close = () => {
        list.hidden = true;
        activeIndex = -1;
        target.setAttribute('aria-expanded', 'false');
        target.removeAttribute('aria-activedescendant');
    };

    const open = () => {
        list.hidden = false;
        target.setAttribute('aria-expanded', 'true');
    };

    const select = async (suggestion) => {
        close();

        const address = await provider.retrieve(suggestion, config).catch(() => null);
        if (!address) return;

        el.querySelectorAll('[data-address-input]').forEach((field) => {
            field.value = address[field.dataset.addressInput] ?? '';
            field.dispatchEvent(new Event('input', { bubbles: true }));
            field.dispatchEvent(new Event('change', { bubbles: true }));
        });
    };

    const render = () => {
        list.querySelectorAll('[role="option"]').forEach((rendered) => rendered.remove());

        suggestions.forEach((suggestion, index) => {
            const node = option.cloneNode(true);

            node.id = `${list.id}-option-${index}`;
            node.setAttribute('role', 'option');
            node.setAttribute('aria-selected', 'false');
            node.addEventListener('mousedown', () => select(suggestion));

            (node.querySelector('[data-address-label]') ?? node).textContent = suggestion.label;

            list.insertBefore(node, attribution);
        });

        suggestions.length ? open() : close();
    };

    const highlight = (index) => {
        activeIndex = index;

        list.querySelectorAll('[role="option"]').forEach((rendered, i) => {
            rendered.setAttribute('aria-selected', i === index ? 'true' : 'false');
        });

        index >= 0
            ? target.setAttribute('aria-activedescendant', `${list.id}-option-${index}`)
            : target.removeAttribute('aria-activedescendant');
    };

    const suggest = (value) => {
        clearTimeout(timeout);

        const query = value.trim();

        if (query.length < MIN_QUERY_LENGTH) {
            suggestions = [];
            close();
            return;
        }

        timeout = setTimeout(async () => {
            const id = ++requestId;
            const results = await provider.suggest(query, config).catch(() => []);

            if (id !== requestId) return;

            suggestions = results;
            render();
            highlight(-1);
        }, DEBOUNCE_MS);
    };

    target.addEventListener('input', () => suggest(target.value));

    target.addEventListener('focus', () => {
        if (suggestions.length) open();
    });

    target.addEventListener('keydown', (e) => {
        if (list.hidden) return;

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            highlight(Math.min(activeIndex + 1, suggestions.length - 1));
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            highlight(Math.max(activeIndex - 1, 0));
        } else if (e.key === 'Enter' && activeIndex >= 0) {
            e.preventDefault();
            select(suggestions[activeIndex]);
        } else if (e.key === 'Escape') {
            close();
        }
    });

    target.addEventListener('blur', close);
}

/**
 * Builds the listbox from the `[data-address-suggestions]` template in the view,
 * allowing developers to customize the markup as needed.
 */
function createList(template, target, provider) {
    const list = template.content.firstElementChild.cloneNode(true);

    list.id = `${target.id}-suggestions`;
    list.setAttribute('role', 'listbox');
    list.hidden = true;

    const option = list.querySelector('[data-address-option]');
    option.remove();

    const attribution = list.querySelector('[data-address-attribution]');
    attribution.setAttribute('role', 'presentation');
    attribution.innerHTML = provider.attribution;

    target.insertAdjacentElement('afterend', list);

    return { list, option, attribution };
}
