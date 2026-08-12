import { registerIconSet } from '@statamic/cms/ui';
import AddressFieldtype from './components/fieldtypes/AddressFieldtype.vue';
import HubSpotCommunicationsFieldtype from './components/fieldtypes/HubSpotCommunicationsFieldtype.vue';
import HubSpotPropertiesFieldtype from './components/fieldtypes/HubSpotPropertiesFieldtype.vue';
import HubSpotSubscriptionFieldtype from './components/fieldtypes/HubSpotSubscriptionFieldtype.vue';
import MailchimpGroupFieldtype from './components/fieldtypes/MailchimpGroupFieldtype.vue';
import MailchimpGroupOptionFieldtype from './components/fieldtypes/MailchimpGroupOptionFieldtype.vue';
import MailchimpMarketingPermissionsFieldtype from './components/fieldtypes/MailchimpMarketingPermissionsFieldtype.vue';
import MailchimpMergeFieldFieldtype from './components/fieldtypes/MailchimpMergeFieldFieldtype.vue';
import HubSpotConnection from './components/connections/HubSpotConnection.vue';
import KitConnection from './components/connections/KitConnection.vue';
import MailchimpConnection from './components/connections/MailchimpConnection.vue';
import MailcoachConnection from './components/connections/MailcoachConnection.vue';
import SlackConnection from './components/connections/SlackConnection.vue';
import TwilioConnection from './components/connections/TwilioConnection.vue';

registerIconSet('forms-pro', import.meta.glob('../svg/*.svg', { query: '?raw', import: 'default' }));

Statamic.booting(() => {
    Statamic.$components.register('address-fieldtype', AddressFieldtype);
    Statamic.$components.register('hubspot_communications-fieldtype', HubSpotCommunicationsFieldtype);
    Statamic.$components.register('hubspot_properties-fieldtype', HubSpotPropertiesFieldtype);
    Statamic.$components.register('hubspot_subscription-fieldtype', HubSpotSubscriptionFieldtype);
    Statamic.$components.register('mailchimp_group-fieldtype', MailchimpGroupFieldtype);
    Statamic.$components.register('mailchimp_group_option-fieldtype', MailchimpGroupOptionFieldtype);
    Statamic.$components.register('mailchimp_marketing_permissions-fieldtype', MailchimpMarketingPermissionsFieldtype);
    Statamic.$components.register('mailchimp_merge_field-fieldtype', MailchimpMergeFieldFieldtype);

    Statamic.$components.register('hubspot-connection', HubSpotConnection);
    Statamic.$components.register('kit-connection', KitConnection);
    Statamic.$components.register('mailchimp-connection', MailchimpConnection);
    Statamic.$components.register('mailcoach-connection', MailcoachConnection);
    Statamic.$components.register('slack-connection', SlackConnection);
    Statamic.$components.register('twilio-connection', TwilioConnection);
});
