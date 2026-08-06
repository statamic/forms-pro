import { registerIconSet } from '@statamic/cms/ui';
import AddressFieldtype from './components/fieldtypes/AddressFieldtype.vue';
import MailcoachConnection from './components/connections/MailcoachConnection.vue';
import SlackConnection from './components/connections/SlackConnection.vue';

registerIconSet('forms-pro', import.meta.glob('../svg/*.svg', { query: '?raw', import: 'default' }));

Statamic.booting(() => {
    Statamic.$components.register('address-fieldtype', AddressFieldtype);

    Statamic.$components.register('mailcoach-connection', MailcoachConnection);
    Statamic.$components.register('slack-connection', SlackConnection);
});
