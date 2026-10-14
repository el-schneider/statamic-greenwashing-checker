<?php

declare(strict_types=1);

namespace ElSchneider\GreenwashingChecker;

use ElSchneider\GreenwashingChecker\Analysis\Category;
use Statamic\Facades\Addon;

final class Settings
{
    public const CP_ONLY_DEFAULTS = ['substantiations' => [], 'house_rules' => '', 'audience' => [], 'jurisdiction_sites' => []];

    public static function get(string $key): mixed
    {
        $addon = Addon::get('el-schneider/statamic-greenwashing-checker');

        // Statamic 5 has no addon settings API: the published config file is the only
        // store there, and CP-only rules (see CP_ONLY_DEFAULTS) are simply empty.
        if ($addon === null || ! method_exists($addon, 'settings')) {
            return self::CP_ONLY_DEFAULTS[$key] ?? config('statamic.greenwashing-checker.'.$key);
        }

        // Raw values keep editorial guidance out of Antlers evaluation.
        $values = $addon->settings()->raw();

        // Cleared optional fields disappear from native files. Their absence is the empty state.
        return $values[$key] ?? self::CP_ONLY_DEFAULTS[$key] ?? config('statamic.greenwashing-checker.'.$key);
    }

    /** Whether the running Statamic major provides the addon settings API (Statamic 6+). */
    public static function hasSettingsApi(): bool
    {
        $addon = Addon::get('el-schneider/statamic-greenwashing-checker');

        return $addon !== null && method_exists($addon, 'settings');
    }

    public static function collectionEnabled(string $collection): bool
    {
        $collections = self::get('collections');

        return $collections === '*' || in_array('*', $collections, true) || in_array($collection, $collections, true);
    }

    public static function analysis(string $collection = ''): array
    {
        $audience = collect(self::get('audience'))->firstWhere('collection', $collection)['audience'] ?? 'b2c';
        $categories = array_values(array_filter(self::get('categories'), fn (string $category) => $audience !== 'b2b' || in_array($category, [Category::FuturePerformance->value, Category::IrrelevantBenefit->value, Category::MisleadingEnvironmental->value], true)));

        return [
            'substantiations' => self::get('substantiations'),
            'categories' => $categories,
            'audience' => $audience,
            'house_rules' => self::get('house_rules'),
        ];
    }
}
