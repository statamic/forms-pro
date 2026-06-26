import alpineDriver from './frontend/alpine-driver';

document.addEventListener('alpine:init', () => {
    Alpine.data('formsPro', alpineDriver);
});