import { registerIconSet } from '@statamic/cms/ui';
import AutomagicForm from './pages/automagic-forms/Index.vue';
import AutomagicFormSuccess from './pages/automagic-forms/Success.vue';
import AutomagicFormRestricted from './pages/automagic-forms/Restricted.vue';
import AutomagicFormUrlFieldtype from './components/fieldtypes/AutomagicFormUrlFieldtype.vue';
import AutomagicFormBackgroundFieldtype from './components/fieldtypes/AutomagicFormBackgroundFieldtype.vue';
import AddressFieldtype from './components/fieldtypes/AddressFieldtype.vue';
import GoogleSheetsTabsFieldtype from './components/fieldtypes/GoogleSheetsTabsFieldtype.vue';
import HubSpotCommunicationsFieldtype from './components/fieldtypes/HubSpotCommunicationsFieldtype.vue';
import HubSpotPropertiesFieldtype from './components/fieldtypes/HubSpotPropertiesFieldtype.vue';
import HubSpotSubscriptionFieldtype from './components/fieldtypes/HubSpotSubscriptionFieldtype.vue';
import MailchimpGroupFieldtype from './components/fieldtypes/MailchimpGroupFieldtype.vue';
import MailchimpGroupOptionFieldtype from './components/fieldtypes/MailchimpGroupOptionFieldtype.vue';
import MailchimpMarketingPermissionsFieldtype from './components/fieldtypes/MailchimpMarketingPermissionsFieldtype.vue';
import MailchimpMergeFieldFieldtype from './components/fieldtypes/MailchimpMergeFieldFieldtype.vue';
import GoogleSheetsConnector from './components/connections/GoogleSheetsConnector.vue';
import HubSpotConnector from './components/connections/HubSpotConnector.vue';
import KitConnector from './components/connections/KitConnector.vue';
import MailchimpConnector from './components/connections/MailchimpConnector.vue';
import MailcoachConnector from './components/connections/MailcoachConnector.vue';
import SlackConnector from './components/connections/SlackConnector.vue';
import TwilioConnector from './components/connections/TwilioConnector.vue';

registerIconSet('forms-pro', import.meta.glob('../svg/*.svg', { query: '?raw', import: 'default' }));

Statamic.booting(() => {
    Statamic.$inertia.register('forms-pro::AutomagicForm/Index', AutomagicForm);
    Statamic.$inertia.register('forms-pro::AutomagicForm/Success', AutomagicFormSuccess);
    Statamic.$inertia.register('forms-pro::AutomagicForm/Restricted', AutomagicFormRestricted);

    Statamic.$components.register('address-fieldtype', AddressFieldtype);
    Statamic.$components.register('automagic_form_url-fieldtype', AutomagicFormUrlFieldtype);
    Statamic.$components.register('automagic_form_background-fieldtype', AutomagicFormBackgroundFieldtype);
    Statamic.$components.register('google_sheets_tabs-fieldtype', GoogleSheetsTabsFieldtype);
    Statamic.$components.register('hubspot_communications-fieldtype', HubSpotCommunicationsFieldtype);
    Statamic.$components.register('hubspot_properties-fieldtype', HubSpotPropertiesFieldtype);
    Statamic.$components.register('hubspot_subscription-fieldtype', HubSpotSubscriptionFieldtype);
    Statamic.$components.register('mailchimp_group-fieldtype', MailchimpGroupFieldtype);
    Statamic.$components.register('mailchimp_group_option-fieldtype', MailchimpGroupOptionFieldtype);
    Statamic.$components.register('mailchimp_marketing_permissions-fieldtype', MailchimpMarketingPermissionsFieldtype);
    Statamic.$components.register('mailchimp_merge_field-fieldtype', MailchimpMergeFieldFieldtype);

    Statamic.$components.register('google-sheets-connector', GoogleSheetsConnector);
    Statamic.$components.register('hubspot-connector', HubSpotConnector);
    Statamic.$components.register('kit-connector', KitConnector);
    Statamic.$components.register('mailchimp-connector', MailchimpConnector);
    Statamic.$components.register('mailcoach-connector', MailcoachConnector);
    Statamic.$components.register('slack-connector', SlackConnector);
    Statamic.$components.register('twilio-connector', TwilioConnector);
});
