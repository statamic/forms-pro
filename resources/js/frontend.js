import { init as initAddressFields } from './frontend/address';

document.readyState === 'loading'
    ? document.addEventListener('DOMContentLoaded', () => initAddressFields())
    : initAddressFields();

export { initAddressFields };