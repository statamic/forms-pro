let api;

export function init(root = document) {
    const widgets = root.querySelectorAll('[data-forms-pro-turnstile]');

    if (!widgets.length) return;

    loadApi().then((turnstile) => widgets.forEach((widget) => bind(widget, turnstile)));
}

function loadApi() {
    return (api ??= new Promise((resolve) => {
        window.formsProTurnstileReady = () => resolve(window.turnstile);

        const script = document.createElement('script');
        script.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit&onload=formsProTurnstileReady';
        script.async = true;
        document.head.appendChild(script);
    }));
}

function bind(widget, turnstile) {
    if (widget.formsProTurnstileBound) return;
    widget.formsProTurnstileBound = true;

    const form = widget.closest('form');
    if (!form) return;

    let token = null;

    form.addEventListener('submit', (event) => {
        if (!token) event.preventDefault();
    }, { capture: true });

    const gate = (allowed) => form
        .querySelectorAll('button:not([type=button]):not([type=reset]), input[type=submit]')
        .forEach((button) => (button.disabled = !allowed));

    gate(false);

    const reset = () => {
        token = null;
        gate(false);
    };

    turnstile.render(widget, {
        sitekey: widget.dataset.sitekey,
        callback: (newToken) => {
            token = newToken;
            gate(true);
        },
        'expired-callback': reset,
        'error-callback': reset,
    });
}
