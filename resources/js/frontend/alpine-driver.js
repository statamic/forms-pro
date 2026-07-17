export default function alpineDriver(data = {}, config = {}) {
    const { pages, initialPage } = config;

    return {
        ...data,

        init() {
            this.$watch('formsPro.currentPage', () => {
                this.$el.querySelector('input[name="_page"]').value = this.formsPro.currentPage;
            });
        },

        formsPro: {
            pages,
            currentPage: initialPage,
            submitting: false,
            errors: {},
            visited: [initialPage],

            get page() {
                return this.pages.find(page => page.id === this.currentPage);
            },

            get pageIndex() {
                return this.pages.findIndex(page => page.id === this.currentPage);
            },

            get isFirstPage() {
                return this.pageIndex === 0;
            },

            get isFinalPage() {
                return this.pageIndex === this.pages.length - 1;
            },

            goTo(pageId) {
                this.errors = {};
                this.currentPage = pageId;
            },

            goToPage(pageId) {
                if (pageId === this.currentPage || !this.visited.includes(pageId)) return;

                this.goTo(pageId);
                this.visited = this.visited.slice(0, this.visited.indexOf(pageId) + 1);
            },

            goToFirstPage() {
                this.goTo(this.pages[0].id);
                this.visited = [this.pages[0].id];
            },

            goToPreviousPage() {
                if (this.visited.length <= 1) return;

                this.visited.pop();
                this.goTo(this.visited[this.visited.length - 1]);
            },

            async submit(e) {
                if (this.isFinalPage) return;

                e.preventDefault();

                this.errors = {};
                this.submitting = true;

                try {
                    const form = e.target.closest('form');

                    const response = await fetch(form.action, {
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
                        this.errors = result.error ?? {};
                    }
                } catch (error) {
                    console.error("Forms Pro: Failed to submit the page:", error);
                } finally {
                    this.submitting = false;
                }
            },
        },
    };
}
