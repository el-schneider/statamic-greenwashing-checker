<?php

declare(strict_types=1);

use ElSchneider\GreenwashingChecker\Settings;
use ElSchneider\GreenwashingChecker\Storage\UnitCache;
use Statamic\Facades\Addon;
use Statamic\Facades\Role;
use Statamic\Facades\User;

beforeEach(function () {
    $this->addon = Addon::get('el-schneider/statamic-greenwashing-checker');
});

it('offers configured defaults and every legal category in the native settings form', function () {
    $this->requireStatamic6('addon settings API');
    $fields = $this->addon->settingsBlueprint()->fields();
    expect($fields->get('threshold')->defaultValue())->toBe('all')
        ->and($fields->get('categories')->config()['options'])->toHaveCount(9)
        ->and($fields->get('collections')->defaultValue())->toBe(['*']);
});

it('uses config defaults until native settings explicitly override them', function () {
    $this->requireStatamic6('addon settings API');
    config(['statamic.greenwashing-checker.check_on_blur' => false, 'statamic.greenwashing-checker.collections' => ['products']]);
    expect(Settings::get('check_on_blur'))->toBeFalse()->and(Settings::collectionEnabled('products'))->toBeTrue()->and(Settings::collectionEnabled('news'))->toBeFalse();
    $this->addon->settings()->set(['check_on_blur' => true, 'collections' => ['*'], 'house_rules' => '{{ not_antlers }}'])->save();
    expect(Settings::get('check_on_blur'))->toBeTrue()->and(Settings::collectionEnabled('news'))->toBeTrue()->and(Settings::get('house_rules'))->toBe('{{ not_antlers }}');
    expect($this->addon->settingsBlueprint()->fields()->has('model'))->toBeFalse();
});

it('guards native settings read and write with the addon settings permission', function (array $permissions, bool $allowed) {
    $this->requireStatamic6('addon settings API');
    Role::make('settings-editor')->permissions(['access cp', ...$permissions])->save();
    $this->actingAs(User::make()->id('settings-editor')->email('settings@example.test')->assignRole('settings-editor'));
    $url = cp_route('addons.settings.edit', $this->addon->slug());
    $this->getJson($url)->assertStatus($allowed ? 200 : 403);
    $response = $this->patchJson(cp_route('addons.settings.update', $this->addon->slug()), [
        'substantiations' => [['scope' => 'EcoTrail', 'evidence' => 'EU Ecolabel DE/020/999']],
        'threshold' => 'high', 'categories' => ['generic_claim'],
        'audience' => [['collection' => ['products'], 'audience' => 'b2b']],
        'house_rules' => 'Keine unklaren Vergleiche.', 'collections' => ['*'],
        'check_on_blur' => false, 'on_publish' => 'off', 'placement' => 'tab', 'jurisdiction' => 'de',
    ]);
    $response->assertStatus($allowed ? 200 : 403);
    if ($allowed) {
        expect(Settings::get('threshold'))->toBe('high')->and(Settings::get('house_rules'))->toBe('Keine unklaren Vergleiche.')->and(Settings::get('audience'))->toBe([['collection' => 'products', 'audience' => 'b2b']]);
    } else {
        expect($this->addon->settings()->raw())->toBe([]);
    }
})->with([[[], false], [['edit el-schneider/statamic-greenwashing-checker settings'], true], [['configure addons'], true]]);

it('hashes every analysis rule but not the display threshold', function () {
    $this->requireStatamic6('addon settings API');
    $cache = app(UnitCache::class);
    $hash = $cache->hash('nachhaltig');
    config(['statamic.greenwashing-checker.threshold' => 'high']);
    expect($cache->hash('nachhaltig'))->toBe($hash);
    foreach (['substantiations' => [['scope' => 'X', 'evidence' => 'Y']], 'categories' => ['generic_claim'], 'house_rules' => 'Check comparisons.'] as $key => $value) {
        $this->addon->settings()->set($key, $value)->save();
        expect($cache->hash('nachhaltig'))->not->toBe($hash);
        $this->addon->settings()->delete();
    }
    $this->addon->settings()->set('audience', [['collection' => 'products', 'audience' => 'b2b']])->save();
    $rules = Settings::analysis('products');
    expect($cache->hash('nachhaltig', $rules))->not->toBe($hash)
        ->and($rules['categories'])->toBe(['future_performance', 'irrelevant_benefit', 'misleading_environmental']);
});

it('renders evidence, selected categories, B2B limits and supplemental house guidance in the prompt', function () {
    $this->requireStatamic6('addon settings API');
    $this->addon->settings()->set(['audience' => [['collection' => 'products', 'audience' => 'b2b']], 'house_rules' => 'Vergleichsbasis nennen.'])->save();
    $rules = Settings::analysis('products');
    $prompt = view('greenwashing-checker::prompts.system', [
        'categories' => array_map(ElSchneider\GreenwashingChecker\Analysis\Category::from(...), $rules['categories']),
        'audience' => $rules['audience'], 'houseRules' => $rules['house_rules'], 'explanationLocale' => 'de', 'contentLocale' => 'de',
    ])->render();
    expect($prompt)->toContain('B2B', 'keine per-se-Verstöße melden', 'Vergleichsbasis nennen.', 'nicht abschalten')
        ->not->toContain('- generic_claim');
});

it('invalidates cached analysis when the context budget changes', function () {
    $cache = app(UnitCache::class);
    config(['statamic.greenwashing-checker.max_context_chars' => 8000]);
    $before = $cache->hash('nachhaltig');
    config(['statamic.greenwashing-checker.max_context_chars' => 100]);
    expect($cache->hash('nachhaltig'))->not->toBe($before);
});

it('explains every setting and category in both CP languages without changing the analysis rules', function (string $locale) {
    $this->requireStatamic6('addon settings API');
    app()->setLocale($locale);
    $fields = $this->addon->settingsBlueprint()->fields();
    foreach (['jurisdiction', 'jurisdiction_sites', 'substantiations', 'threshold', 'categories', 'audience', 'house_rules', 'collections', 'check_on_blur', 'on_publish', 'placement'] as $handle) {
        $instructions = $fields->get($handle)->config()['instructions'];
        expect(__($instructions))->not->toBe($instructions)->not->toBeEmpty();
    }
    foreach (ElSchneider\GreenwashingChecker\Analysis\Category::cases() as $category) {
        $option = $fields->get('categories')->config()['options'][$category->value];
        expect($option)->toContain($category->label(), $locale === 'de' ? 'Beispiel:' : 'Example:');
    }
    $legal = __($fields->get('legal_basis_de')->config()['instructions']);
    expect($legal)->toContain('2024/825', '2005/29/', 'https://eur-lex.europa.eu/', 'https://www.recht.bund.de/', 'https://www.umweltbundesamt.de/', 'https://commission.europa.eu/', $locale === 'de' ? 'keine Rechtsberatung' : 'not legal advice');
})->with(['de', 'en']);

it('clears CP-only editorial settings without falling back to legacy published config', function (string $handle, mixed $default, mixed $empty) {
    $this->requireStatamic6('addon settings API');
    config(['statamic.greenwashing-checker.'.$handle => $default]);
    $this->addon->settings()->set($handle, $default)->save();
    expect(Settings::get($handle))->toBe($default);
    $this->actingAs(User::make()->id('settings-editor')->email('settings@example.test')->makeSuper());
    $this->patchJson(cp_route('addons.settings.update', $this->addon->slug()), [
        'jurisdiction' => 'de', 'threshold' => 'all', 'on_publish' => 'warn', 'placement' => 'sidebar',
        $handle => $empty,
    ])->assertOk();
    expect(Settings::get($handle))->toBe($empty)
        ->and($this->addon->settingsBlueprint()->fields()->get($handle)->defaultValue())->toBe($empty);
})->with([
    ['substantiations', [['scope' => 'Product', 'evidence' => 'Verified evidence']], []],
    ['audience', [['collection' => 'products', 'audience' => 'b2b']], []],
    ['house_rules', 'Additional guidance', ''],
    ['jurisdiction_sites', ['default' => 'eu'], []],
]);

it('reads settings from the config file only on Statamic 5, with CP-only rules empty', function () {
    if (Settings::hasSettingsApi()) {
        $this->markTestSkipped('Statamic 6 only fallback counterpart: config-only settings apply on Statamic 5.');
    }
    config(['statamic.greenwashing-checker.collections' => ['products'], 'statamic.greenwashing-checker.house_rules' => 'Ignored on v5', 'statamic.greenwashing-checker.check_on_blur' => false]);
    expect(Settings::get('collections'))->toBe(['products'])
        ->and(Settings::get('check_on_blur'))->toBeFalse()
        ->and(Settings::get('house_rules'))->toBe('')
        ->and(Settings::get('substantiations'))->toBe([])
        ->and(Settings::collectionEnabled('products'))->toBeTrue()
        ->and(Settings::collectionEnabled('news'))->toBeFalse();
});
