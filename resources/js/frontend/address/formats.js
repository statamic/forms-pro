import formats from './formats.json';

export function formatFor(country) {
    return { ...formats.default, ...(formats.countries[country] ?? {}) };
}
