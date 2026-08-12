<?php

namespace Statamic\FormsPro\FormConnections\Concerns;

use Statamic\Contracts\Forms\Submission;
use Statamic\Support\Arr;
use Statamic\Support\Str;

trait ReadsSubmissionValues
{
    /**
     * Whether a field was opted into. Services model consent as a boolean, but a
     * form can express it as a toggle, a checkbox group, or a plain string.
     */
    private function isChecked(Submission $submission, ?string $handle): bool
    {
        if (! $handle) {
            return false;
        }

        $value = $submission->get($handle);

        return match (true) {
            is_bool($value) => $value,
            is_array($value) => filled($value),
            default => filled($value) && ! in_array(Str::lower((string) $value), ['0', 'no', 'off', 'false']),
        };
    }

    private function value(Submission $submission, ?string $handle): ?string
    {
        if (! $handle) {
            return null;
        }

        $value = $submission->get($handle);

        return match (true) {
            is_array($value) => implode(', ', Arr::flatten($value)) ?: null,
            is_scalar($value) => (string) $value,
            default => null,
        };
    }
}
