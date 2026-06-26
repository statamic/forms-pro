export default function alpineDriver(data = {}, config = {}) {
    // - `data` spreads at the root so x-model bindings keep working.
    // - `config` is kept separate so the page state never depends on a custom
    //   component forwarding it — `() => ({...})` works just as well.
    const { initialPage, pageIds, validationUrl } = config;

    return {
        ...data,
        currentPage: initialPage,
        pageIds,
        validationUrl,
        visited: [initialPage],
        pageErrors: {},
        isValidating: false,

        get pageIndex() {
            return this.pageIds.indexOf(this.currentPage);
        },
        get totalPages() {
            return this.pageIds.length;
        },
        get isFirstPage() {
            return this.pageIndex === 0;
        },
        get isLastPage() {
            return this.pageIndex === this.pageIds.length - 1;
        },

        prevPage() {
            if (this.isFirstPage) return;

            // TODO(forms-pro): pop the real visited stack instead of pageIndex - 1
            // once branching lands — "previous" is where the user came from. Todo #13.
            this.goTo(this.pageIds[this.pageIndex - 1]);
            this.pageErrors = {};
        },

        async nextPage() {
            if (this.isValidating || this.isLastPage) return;

            this.isValidating = true;
            this.pageErrors = {};

            try {
                const form = this.$el.closest('form');

                const response = await fetch(`${this.validationUrl}/${this.currentPage}`, {
                    method: 'POST',
                    body: new FormData(form),
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        Accept: 'application/json',
                    },
                });

                const result = await response.json();

                if (response.ok) {
                    this.goTo(result.next_page);
                    this.visited.push(result.next_page);
                } else {
                    this.pageErrors = result.errors || {};
                    this.$dispatch('forms-pro:validation-failed', { errors: this.pageErrors });
                }
            } catch (error) {
                console.error('Page validation failed:', error);
            } finally {
                this.isValidating = false;
            }
        },

        goToPage(pageId) {
            if (!this.pageIds.includes(pageId) || pageId === this.currentPage) return;

            // Forward jumps must run through nextPage() to be validated; allow back only.
            if (this.pageIds.indexOf(pageId) > this.pageIndex) return;

            this.goTo(pageId);
            this.pageErrors = {};
        },

        goTo(pageId) {
            this.$dispatch('forms-pro:page-change', { from: this.currentPage, to: pageId });
            this.currentPage = pageId;
        },

        goToFirstPage() {
            this.visited = [this.pageIds[0]];
            this.pageErrors = {};
            this.goTo(this.pageIds[0]);
        },
    };
}