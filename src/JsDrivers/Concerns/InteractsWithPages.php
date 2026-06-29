<?php

namespace Statamic\FormsPro\JsDrivers\Concerns;

use Statamic\Fields\Tab;
use Statamic\Statamic;

trait InteractsWithPages
{
    public function addToRenderablePageData($page, $data)
    {
        return [
            'show_page' => $this->renderShowPageJs($page->handle()),
        ];
    }

    private function renderShowPageJs(string $id): string
    {
        return "formsPro.currentPage === '{$id}'";
    }

    private function wrapInFormsProComponent(string $data): string
    {
        if ($this->component) {
            $data = "{$this->component}({$data})";
        }

        $config = Statamic::modify($this->getConfig())->toJson()->entities();

        return "formsPro({$data}, {$config})";
    }

    private function getConfig(): array
    {
        $pages = $this->form->blueprint()->tabs()
            ->map(fn (Tab $tab) => [
                'id' => $tab->handle(),
                'display' => $tab->display(),
                'instructions' => $tab->instructions(),
            ])
            ->values();

        return [
            'pages' => $pages,
            'initialPage' => $pages->first()['id'],
        ];
    }
}