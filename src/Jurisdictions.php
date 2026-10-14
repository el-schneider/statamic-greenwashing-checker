<?php

declare(strict_types=1);

namespace ElSchneider\GreenwashingChecker;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class Jurisdictions
{
    public static function references(string $site): array
    {
        $key = Settings::get('jurisdiction_sites')[$site] ?? Settings::get('jurisdiction');
        $definition = config('statamic.greenwashing-checker.jurisdictions')[$key];

        return array_map(
            fn ($references) => implode(' · ', array_map(fn (string $reference) => __($reference), Arr::wrap($references))),
            $definition['references'],
        );
    }

    public static function validate(array $settings): void
    {
        $keys = array_keys(config('statamic.greenwashing-checker.jurisdictions'));
        Validator::make(array_replace([
            'jurisdiction' => config('statamic.greenwashing-checker.jurisdiction'),
            'jurisdiction_sites' => [],
        ], $settings), [
            'jurisdiction' => ['required', 'string', Rule::in($keys)],
            // Native array fields store an empty override map as null.
            'jurisdiction_sites' => ['nullable', 'array'],
            'jurisdiction_sites.*' => ['required', 'string', Rule::in($keys)],
        ])->validate();
    }
}
