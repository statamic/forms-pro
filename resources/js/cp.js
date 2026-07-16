import AutomagicForm from "./pages/AutomagicForm.vue";
import AutomagicFormSuccess from "./pages/AutomagicFormSuccess.vue";
import AutomagicFormUrlFieldtype from "./components/fieldtypes/AutomagicFormUrlFieldtype.vue";

Statamic.booting(() => {
    Statamic.$inertia.register('forms-pro::AutomagicForm', AutomagicForm);
    Statamic.$inertia.register('forms-pro::AutomagicFormSuccess', AutomagicFormSuccess);

    Statamic.$components.register('automagic_form_url-fieldtype', AutomagicFormUrlFieldtype);
});
