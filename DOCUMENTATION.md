## Installation
1. You can install the Forms Pro addon via Composer:

   ```bash
   composer require statamic/forms-pro
   ```
   
2. You can now use Forms Pro's features when building and configuring forms in the Control Panel.

## Multi-page Forms
Multi-page Forms let you split long forms across multiple pages, so visitors can complete them a few fields at a time.

Pages can be added via the **Form Builder** in the Control Panel. Drag a "Page Separator" to wherever you want to split the form. Each page can have its own display name, help text and previous/next button labels.

When a visitor submits a page, Statamic validates its fields and saves a "partial" submission. The submission is only finalized (dispatching events and triggering connections) once the final page is submitted.

> [!WARNING]  
> The `SubmissionCreating`, `SubmissionCreated`, `SubmissionSaving` and `SubmissionSaved` events are dispatched when creating partial submissions, just like any other submission.
> If you're listening to these events in your code, and _don't_ want to receive incomplete submissions, you should listen to either the [`FormSubmitted`](https://statamic.dev/backend-apis/events/events#formsubmitted) or [`SubmissionFinalized`](https://statamic.dev/backend-apis/events/events#submissionfinalized) events instead.

> [!WARNING]
> When moving between pages, fields are pre-populated from the visitor's partial submission. If you're using [static caching](https://statamic.dev/static-caching), you should wrap your forms in the [`{{ nocache }}`](https://statamic.dev/tags/nocache) tag to prevent submitted data from being cached.

### Templating
Provided you're using the `{{ form:create }}` tag to render your form, you **don't need to make any changes to your template** to support multi-page forms.

The `{{ sections }}` and `{{ form:fields }}` loops are automatically scoped to the current page. Submitting the form will take you to the next page.

Forms Pro makes the following variables available on each page:

| Variable              | Description                                                                 |
|-----------------------|-----------------------------------------------------------------------------|
| `page:display`        | The page's display name.                                                    |
| `page:instructions`   | The page's help text.                                                       |
| `button_label`        | Label for the submit button.                                                |
| `previous_page_url`   | URL to navigate back to the previous page. Not available on the first page. |
| `previous_page_label` | Label for the "previous page" link.                                         |

Apart from wiring up button labels and a link back to the previous page, you can use an existing template as-is:

```antlers
{{ form:survey }}
    {{ if success }}
        <p>Success!</p>
    {{ /if }}

    <h2>{{ page:display }}</h2>
    <p>{{ page:instructions }}</p>

    {{ sections }}
        <fieldset>
            <legend>{{ display }}</legend>
            {{ form:fields }}
                <div>
                    <label>{{ display }}</label>
                    <p>{{ instructions }}</p>
                    {{ field }}
                    {{ if error }}
                        <p>{{ error }}</p>
                    {{ /if }}
                </div>
            {{ /form:fields }}
        </fieldset>
    {{ /sections }}

    {{ if previous_page_url }}
        <a href="{{ previous_page_url }}">{{ previous_page_label }}</a>
    {{ /if }}

    <button>{{ button_label }}</button>
{{ /form:survey }}
```

### Submitting with JavaScript
Out of box, every page is submitted with a native `<form>` POST, incurring a full page reload.

Forms Pro ships with two Alpine JS drivers — `forms_pro_alpine` and `forms_pro_alpine_precognition` — that manage page state on the frontend and submit intermediate pages over AJAX, letting your visitors move between pages without a full page reload. They build on Statamic's [core Alpine drivers](https://statamic.dev/tags/form-create#logic-conditional-fields).

Forms Pro is responsible for submitting intermediate pages, leaving the final submission up to you.

To use them, first publish the frontend assets:

```bash
php artisan vendor:publish --tag=forms-pro-frontend
```

Then load the script, alongside Statamic's helpers:

```html
<script src="/vendor/statamic/frontend/js/helpers.js"></script>
<script src="/vendor/forms-pro/frontend/js/forms-pro.js"></script>
```

In your template, add `js="forms_pro_alpine"` (or `forms_pro_alpine_precognition`) to the form tag to enable the driver.

```diff
- {{ form:survey }}
+ {{ form:survey js="forms_pro_alpine" }}
```

Next, loop through `{{ pages }}` and wrap each page in `<template x-if="{{ show_page }}">`. Make sure anything page-related (like the page's display name and button labels) is _inside_ the loop and without the `page:` prefix.

Also, add click handler to the previous/next buttons to call `formsPro.goToPreviousPage()` and `formsPro.submit($event)` respectively.

```antlers
{{ form:survey js="forms_pro_alpine" }}
   {{-- ... --}}
   
   {{ pages }}
       <template x-if="{{ show_page }}">
            <h2>{{ display }}</h2>
            <p>{{ instructions }}</p>
            
            {{ sections }}
                {{-- ... --}}
            {{ /sections }}
            
            {{ if previous_page_label }}
                <button type="button" @click="formsPro.goToPreviousPage()">{{ previous_page_label }}</button>
            {{ /if }}

            <button @click="formsPro.submit($event)" :disabled="formsPro.submitting">{{ button_label }}</button>
       </template>
   {{ /pages }}
   
   {{-- ... --}}
{{ /form:survey }}
```

Finally, render validation errors for each field using `formsPro.errors['{{ handle }}']` in addition to your existing error handling. Forms Pro's error handling will be used for intermediate pages, but you'll need to display errors for the final page separately.

```php
<small
    x-show="formsPro.errors['{{ handle }}']"
    x-text="formsPro.errors['{{ handle }}']"
></small>
```

The following helpers are available in your templates:

#### State

| Property               | Description                                                    |
|------------------------|----------------------------------------------------------------|
| `formsPro.currentPage` | Handle of the page currently being shown.                      |
| `formsPro.pages`       | Array of all pages.                                            |
| `formsPro.errors`      | Validation errors for the current page, keyed by field handle. |
| `formsPro.submitting`  | `true` while a page is being submitted over AJAX.              |
| `formsPro.visited`     | Handles of the pages visited so far.                           |

#### Getters

| Property               | Description                            |
|------------------------|----------------------------------------|
| `formsPro.page`        | The current page object.               |
| `formsPro.pageIndex`   | Zero-based index of the current page.  |
| `formsPro.isFirstPage` | Whether the current page is the first. |
| `formsPro.isFinalPage` | Whether the current page is the last.  |

#### Methods

| Method                        | Description                                                                       |
|-------------------------------|-----------------------------------------------------------------------------------|
| `formsPro.submit($event)`     | Submit the current page over AJAX and advance to the next.                        |
| `formsPro.goToPage(pageId)`   | Jump to a previously visited page. Forward jumps and unvisited pages are ignored. |
| `formsPro.goToPreviousPage()` | Go back to the previous page.                                                     |
| `formsPro.goToFirstPage()`    | Jump back to the first page.                                                      |

All methods are namespaced under `formsPro` to avoid conflicts with your own Alpine data. This means that methods need to be called with `()` — bare references like `@click="formsPro.submit"` won't bind correctly.

### Building your own driver
While the Alpine drivers are the easiest way to get started, you can also build your own driver.

Statamic's [custom JS drivers](https://statamic.dev/tags/form-create#custom-js-drivers) documentation covers how a driver is built and registered; this section covers what a driver needs to do to support multi-page forms specifically.

A page-aware driver has two responsibilities: **tracking the active page**, and **submitting intermediate pages over AJAX**.

#### Tracking page state
Render every page up-front (using the `{{ pages }}` loop), then only show the current page to the visitor. At a minimum, you'll want to keep track of:

- the current page
- the pages the visitor has already visited (so you can offer a "previous" button)
- the validation errors for the current page

The `{{ form:create }}` tag outputs a hidden `_page` input with the current page's ID. You should keep this up to date as the visitor moves between pages.

#### Submitting a page

When the visitor submits a page, you should:

- Send a `POST` request to the form's `action` URL
- Include the `_page` field, so Forms Pro knows which page to process
- Send an `X-Requested-With: XMLHttpRequest` header so Statamic responds with JSON rather than a redirect

On a **successful** response (`200`), the page's fields will be validated, a partial submission will be created, and the ID of the next page will be returned.

```json
{
   "success": true,
   "submission_created": true,
   "submission": { ... },
   "redirect": "https://example.com/survey?page=page_2",
   "next_page": "page_2"
}
```

When filled, you should advance to the next page using JavaScript. When empty, you should redirect the visitor to the `redirect` URL or show a success message.

On a **validation failure** (`400`), Statamic returns the errors for the current page's fields:

```json
{
    "errors": ["The name field is required."],
    "error": { "name": "The name field is required." }
}
```

You may use the `error` object (keyed by field handle) to display messages against every field, and keep the visitor on the current page.

The final page's submission is left up to you — let the native `<form>` POST handle it, or submit it over AJAX like the intermediate pages.

### Logic
By default, submitting a page takes the visitor to the next page in sequence. However, **logic** can be used to skip pages, or branch out to different pages based on the answers they provide.

Logic can be configured in the **Form Builder**, under the "Logic" tab when inspecting a page. Pages can have multiple rules, and each rule can have multiple conditions, paired with a destination (the page to jump to when those conditions are met). You can also manage logic from the "Logic" page in the Control Panel.

When a page is submitted, its rules are evaulated in order. The first rule whose conditions pass wins, and the visitor is taken to its destination. If no rule matches, they continue to the next page in sequence. When there's nowhere left to go, the submission is finalized.

Unlike field logic, page logic is evaluated on the server, so logic behaves identically whether you're using plain POSTs or one of the JS drivers.

#### Combining conditions with _and_ / _or_

Every condition is joined to the previous one with **and** or **or**. Conditions are grouped so that **and** binds more tightly than **or**: a new group begins at each **or**, a group passes when _all_ of its conditions pass, and the rule passes when _any_ group passes.

Confused? Here's a visual example:

> `country` is `United States` **and** `state` is `California`
> **or** `country` is `Canada`

...is evaluated as `(country is United States and state is California) or (country is Canada)`.

## Uninstalling
To uninstall, run:

```bash
composer remove statamic/forms-pro
```

After uninstalling, multi-page forms will be flattened into a single page and dedicated form pages will 404.