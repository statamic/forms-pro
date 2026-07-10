import AutomagicForm from "./pages/AutomagicForm.vue";
import AutomagicFormUrlFieldtype from "./components/fieldtypes/AutomagicFormUrlFieldtype.vue";

Statamic.booting(() => {
    Statamic.$inertia.register('forms-pro::AutomagicForm', AutomagicForm);

    Statamic.$components.register('automagic_form_url-fieldtype', AutomagicFormUrlFieldtype);
});
