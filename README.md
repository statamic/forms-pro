# Forms Pro

Build polished multi-page experiences, publish branded forms without creating a template, explore responses with charts and insights, and send submissions directly to the tools you already use.

[Forms Pro](https://statamic.com/addons/statamic/forms-pro) turns forms into complete experiences and workflows — not just places to collect a name and email address.

- **Launch without building a page.** [Automagic Forms](https://statamic.dev/frontend/forms-pro#automagic-forms) gives every form its own branded, shareable URL.
- **Make long forms feel manageable.** Split them into [multiple pages](https://statamic.dev/frontend/forms-pro#multi-page-forms) and guide visitors down different paths based on their answers.
- **See what people are telling you.** Turn responses into customizable [charts and insights](https://statamic.dev/frontend/forms-pro#form-summaries) inside the Control Panel.
- **Put submissions to work.** Send them directly to [Google Sheets, HubSpot, Mailchimp, Slack, and more](https://statamic.dev/frontend/forms-pro#connections).
- **Reuse one form across many entries.** Give every event, listing, or entry [its own submissions, limits, and notifications](https://statamic.dev/frontend/forms-pro#unique-instances).
- **Collect better data.** Add [address autocomplete](https://statamic.dev/frontend/forms-pro#address-fieldtype), additional fieldtypes, and [Cloudflare Turnstile](https://statamic.dev/frontend/forms-pro#cloudflare-turnstile) protection.

You can try every Forms Pro feature locally for free. When you're ready, installation takes two commands.

## Installation

1. Install the Forms Pro add-on via Composer:

    ```bash
    composer require statamic/forms-pro
    ```

2. Publish the configuration file to `config/forms-pro.php`:

    ```bash
    php artisan vendor:publish --tag=forms-pro-config
    ```

That's it. Forms Pro's features are now ready and waiting in the Control Panel.

## Documentation

Read the docs on the [Statamic documentation](https://statamic.dev/frontend/forms-pro).

## Commercial Addon

Forms Pro is a **commercial addon**. You can use it for free while in development, but you need a license to use it on a live site. Learn more or buy a license on [The Statamic Marketplace](https://statamic.com/addons/statamic/forms-pro)!
