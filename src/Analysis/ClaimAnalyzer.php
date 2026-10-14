<?php

declare(strict_types=1);

namespace ElSchneider\GreenwashingChecker\Analysis;

use ElSchneider\GreenwashingChecker\Ai\AiErrorCode;
use ElSchneider\GreenwashingChecker\Ai\AiException;
use ElSchneider\GreenwashingChecker\Ai\AiModel;
use ElSchneider\GreenwashingChecker\Content\TextUnit;
use ElSchneider\GreenwashingChecker\Settings;
use ElSchneider\GreenwashingChecker\Storage\UnitCache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\EnumSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

final class ClaimAnalyzer
{
    public const PROMPT_VERSION = '5';

    /** @param list<TextUnit> $targets
     * @param  list<TextUnit>  $context
     * @return array<string, list<Finding>>
     */
    public function analyze(array $targets, array $context, string $explanationLocale, string $contentLocale, ?array $rules = null): array
    {
        if ($targets === []) {
            return [];
        }
        $rules ??= Settings::analysis();
        if ($rules['categories'] === []) {
            return array_fill_keys(array_column($targets, 'path'), []);
        }
        $model = AiModel::fromString(config('statamic.greenwashing-checker.model'));
        if (! $model->isConfigured()) {
            throw new AiException(AiErrorCode::Configuration);
        }
        $schema = new ObjectSchema('analysis', 'Greenwashing risks', [
            new ArraySchema('findings', 'Only risks in TARGET units', new ObjectSchema('finding', 'A risk', [
                new StringSchema('unit_id', 'Exact TARGET path'),
                new StringSchema('quote', 'Shortest verbatim claim substring'),
                new EnumSchema('category', 'Risk category', array_column(Category::cases(), 'value')),
                new EnumSchema('severity', 'Risk severity', array_column(Severity::cases(), 'value')),
                new StringSchema('explanation', 'One or two plain-language risk sentences'),
                new ArraySchema('suggestions', 'One to three drop-in replacements; use placeholders for unknown facts', new StringSchema('suggestion', 'Replacement text'), minItems: 1, maxItems: 3),
            ], ['unit_id', 'quote', 'category', 'severity', 'explanation', 'suggestions'])),
        ], ['findings']);
        $data = compact('targets', 'context', 'explanationLocale', 'contentLocale');
        $data['categories'] = array_map(Category::from(...), $rules['categories']);
        $data['substantiations'] = $rules['substantiations'];
        $data['audience'] = $rules['audience'];
        $data['houseRules'] = $rules['house_rules'];
        $response = Prism::structured()->using($model->provider, $model->model)
            ->withClientOptions(['timeout' => max(1, min(110, (int) config('statamic.greenwashing-checker.timeout')))])
            ->withSchema($schema)
            ->withSystemPrompt(view('greenwashing-checker::prompts.system', $data)->render())
            ->withPrompt(view('greenwashing-checker::prompts.user', $data)->render())
            ->asStructured();

        $validator = Validator::make($response->structured, [
            'findings' => ['present', 'array'],
            'findings.*' => ['array:unit_id,quote,category,severity,explanation,suggestions'],
            'findings.*.unit_id' => ['required', 'string'],
            'findings.*.quote' => ['required', 'string'],
            'findings.*.category' => ['required', Rule::enum(Category::class)],
            'findings.*.severity' => ['required', Rule::enum(Severity::class)],
            'findings.*.explanation' => ['required', 'string'],
            'findings.*.suggestions' => ['present', 'array'],
            'findings.*.suggestions.*' => ['required', 'string'],
        ]);
        if ($validator->fails()) {
            throw new AiException(AiErrorCode::InvalidResponse);
        }
        $units = collect($targets)->keyBy('path');
        $findings = array_fill_keys($units->keys()->all(), []);
        $unknown = $withoutSuggestions = 0;
        foreach ($validator->validated()['findings'] as $raw) {
            if (! $unit = $units->get($raw['unit_id'])) {
                $unknown++;

                continue;
            }
            $category = Category::from($raw['category']);
            if (! in_array($category->value, $rules['categories'], true)) {
                continue;
            }
            if ($raw['suggestions'] === []) {
                $withoutSuggestions++;
            }
            $normalise = fn (string $text): string => preg_replace('/\s+/u', ' ', trim($text));
            $findings[$unit->path][] = new Finding(
                sha1(app(UnitCache::class)->hash($unit->text, $rules).'|'.$raw['quote'].'|'.$category->value),
                $unit->path, $raw['quote'], str_contains($normalise($unit->text), $normalise($raw['quote'])),
                $category, $rules['audience'] === 'b2b' ? Severity::Medium : $category->severity(), $raw['explanation'], array_slice($raw['suggestions'], 0, 3),
            );
        }
        if ($withoutSuggestions > 0) {
            Log::info('Greenwashing response contained findings without suggestions.', ['count' => $withoutSuggestions]);
        }
        if ($unknown > 0) {
            Log::warning('Greenwashing response contained unknown TARGET units.', ['count' => $unknown]);
        }

        return $findings;
    }
}
