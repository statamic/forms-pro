import { registerIconSet } from '@statamic/cms/ui';
import AddressFieldtype from './components/fieldtypes/AddressFieldtype.vue';
import MailchimpGroupFieldtype from './components/fieldtypes/MailchimpGroupFieldtype.vue';
import MailchimpGroupOptionFieldtype from './components/fieldtypes/MailchimpGroupOptionFieldtype.vue';
import MailchimpMarketingPermissionsFieldtype from './components/fieldtypes/MailchimpMarketingPermissionsFieldtype.vue';
import MailchimpMergeFieldFieldtype from './components/fieldtypes/MailchimpMergeFieldFieldtype.vue';
import MailchimpConnection from './components/connections/MailchimpConnection.vue';
import MailcoachConnection from './components/connections/MailcoachConnection.vue';
import SlackConnection from './components/connections/SlackConnection.vue';

registerIconSet('forms-pro', import.meta.glob('../svg/*.svg', { query: '?raw', import: 'default' }));

Statamic.booting(() => {
    Statamic.$components.register('address-fieldtype', AddressFieldtype);
    Statamic.$components.register('mailchimp_group-fieldtype', MailchimpGroupFieldtype);
    Statamic.$components.register('mailchimp_group_option-fieldtype', MailchimpGroupOptionFieldtype);
    Statamic.$components.register('mailchimp_marketing_permissions-fieldtype', MailchimpMarketingPermissionsFieldtype);
    Statamic.$components.register('mailchimp_merge_field-fieldtype', MailchimpMergeFieldFieldtype);

    Statamic.$components.register('mailchimp-connection', MailchimpConnection);
    Statamic.$components.register('mailcoach-connection', MailcoachConnection);
    Statamic.$components.register('slack-connection', SlackConnection);
});
