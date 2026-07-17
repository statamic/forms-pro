import AddressFieldtype from './components/fieldtypes/AddressFieldtype.vue';

Statamic.booting(() => {
    Statamic.component('address-fieldtype', AddressFieldtype);
});