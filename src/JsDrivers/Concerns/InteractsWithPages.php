<?php

namespace Statamic\FormsPro\JsDrivers\Concerns;

use Statamic\FormsPro\MultiPage\Pages;
use Statamic\Statamic;

trait InteractsWithPages
{
    public function addToFormData($data)
    {
        $ids = $this->pageIds();

        return array_merge(parent::addToFormData($data), [
            'initial_page' => $ids[0] ?? null,
            'page_ids' => $ids,
            'validation_url' => $this->validationUrl(),
            'show_page' => collect($ids)
                ->mapWithKeys(fn (string $id) => [$id => $this->renderShowPageJs($id)])
                ->all(),
        ]);
    }

    public function addToRenderablePageData($page, $data)
    {
        return [
            'show_page' => $this->renderShowPageJs($page->handle()),
        ];
    }

    private function pageIds(): array
    {
        return $this->form->formFields()->pages()->pluck('id')->all();
    }

    private function pageConfig(): array
    {
        $ids = $this->pageIds();

        return [
            'initialPage' => $ids[0] ?? null,
            'pageIds' => $ids,
            'validationUrl' => $this->validationUrl(),
        ];
    }

    private function validationUrl(): string
    {
        return route('statamic.forms-pro.pages.submit', $this->form->handle());
    }

    private function renderShowPageJs(string $id): string
    {
        return "currentPage === '{$id}'";
    }

    private function wrapInFormsProComponent(string $data): string
    {
        if ($this->component) {
            $data = "{$this->component}({$data})";
        }

        $config = Statamic::modify($this->pageConfig())->toJson()->entities();

        return "formsPro({$data}, {$config})";
    }
}