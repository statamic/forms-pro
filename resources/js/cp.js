import Form from './pages/Form.vue';

Statamic.booting(() => {
    Statamic.$inertia.register('forms-pro::Form', Form);
});
