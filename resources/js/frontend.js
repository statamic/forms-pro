import alpineDriver from './frontend/alpine-driver';
import { init as initAddressFields } from './frontend/address';
import { init as initTurnstile } from './frontend/turnstile';

document.addEventListener('alpine:init', () => {
    Alpine.data('formsPro', alpineDriver);
});

const init = () => {
    initAddressFields();
    initTurnstile();
};

document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', init) : init();

export { initAddressFields, initTurnstile };
