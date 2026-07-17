import alpineDriver from './frontend/alpine-driver';
import { init as initAddressFields } from './frontend/address';

document.addEventListener('alpine:init', () => {
    Alpine.data('formsPro', alpineDriver);
});

document.readyState === 'loading'
    ? document.addEventListener('DOMContentLoaded', () => initAddressFields())
    : initAddressFields();

export { initAddressFields };
