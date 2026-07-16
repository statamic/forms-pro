import AutomagicForm from "./pages/automagic-forms/Index.vue";
import AutomagicFormSuccess from "./pages/automagic-forms/Success.vue";
import AutomagicFormUrlFieldtype from "./components/fieldtypes/AutomagicFormUrlFieldtype.vue";

Statamic.booting(() => {
    Statamic.$inertia.register('forms-pro::AutomagicForm/Index', AutomagicForm);
    Statamic.$inertia.register('forms-pro::AutomagicForm/Success', AutomagicFormSuccess);

    Statamic.$components.register('automagic_form_url-fieldtype', AutomagicFormUrlFieldtype);
});
