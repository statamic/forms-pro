<?php

return [
    'slack_connection_channel_instructions' => 'Private channels only appear once the app has been invited to them.',
    'slack_connection_description' => 'Post submissions to a Slack channel.',
    'slack_connection_fields_instructions' => 'Leave empty to include every field.',
    'slack_connection_heading' => 'New :form submission',
    'slack_connection_delete_confirmation' => 'Are you sure you want to delete this Slack notification?',
    'slack_connection_include_fields_instructions' => 'Displays the submitted values as a table.',
    'slack_connection_link_to_submission_instructions' => 'Add a button linking to the submission in the Control Panel.',
    'slack_connection_api_error' => 'Couldn\'t connect to Slack.',
    'slack_connection_test_sent' => 'Test message sent.',
    'slack_connection_truncated' => 'This submission was too long to show in full.',
    'slack_connection_view_submission' => 'View submission',

    'slack_setup_heading' => 'Connect your Slack workspace',
    'slack_setup_intro' => 'Slack needs to know about your site before it\'ll accept messages. You only need to do this once.',
    'slack_setup_create_app' => 'Create a Slack app in the workspace you\'d like submissions sent to.',
    'slack_setup_manifest_toggle' => 'Set it up by hand, or update an existing app',
    'slack_setup_manifest' => 'Paste this manifest into the app\'s "App Manifest" page.',
    'slack_setup_install' => 'Choose "Install to Workspace", select the workspace you\'d like to install it into, and click Allow.',
    'slack_setup_token' => 'Copy the Bot User OAuth Token from the app\'s "OAuth &amp; Permissions" page into your site\'s :file file:',
    'slack_setup_developer' => 'This step needs access to the server, so you may need a developer to do it for you. Once :variable is set, reload this page.',
];
