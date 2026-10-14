<?php

declare(strict_types=1);

use ElSchneider\GreenwashingChecker\Analysis\Category;
use ElSchneider\GreenwashingChecker\Jurisdictions;
use ElSchneider\GreenwashingChecker\ServiceProvider;
use ElSchneider\GreenwashingChecker\Settings;
use ElSchneider\GreenwashingChecker\Storage\UnitCache;
use Illuminate\Validation\ValidationException;
use Statamic\Facades\Addon;
use Statamic\Facades\User;

beforeEach(function () {
    $this->addon = Addon::get('el-schneider/statamic-greenwashing-checker');
});

it('resolves the entry site override before the global jurisdiction in the CP language', function () {
    $this->requireStatamic6('CP jurisdiction settings');
    app()->setLocale('en');
    $this->addon->settings()->set(['jurisdiction' => 'de', 'jurisdiction_sites' => ['fr' => 'eu']])->save();
    expect(Jurisdictions::references('de')['generic_claim'])->toBe('UCPD Annex I point 4a · DE: UWG Annex no. 4a')
        ->and(Jurisdictions::references('fr')['generic_claim'])->toBe('UCPD Annex I point 4a')
        ->and(Jurisdictions::references('unmapped')['durability_repair'])->toBe('UCPD Annex I point 23d–23i · DE: UWG Annex no. 23d a)–g)');
    app()->setLocale('de');
    expect(Jurisdictions::references('fr')['generic_claim'])->toBe('UGP-Richtlinie Anhang I Nr. 4a');
});

it('merges published jurisdiction overrides by category while replacing source lists', function () {
    config(['statamic.greenwashing-checker.jurisdictions' => [
        'de' => ['references' => ['generic_claim' => 'Custom official reference'], 'sources' => [['label' => 'Official source', 'url' => 'https://example.test/official']]],
        'custom' => ['name' => 'Custom display pack', 'references' => array_fill_keys(array_column(Category::cases(), 'value'), 'Official reference'), 'sources' => []],
    ]]);
    (new ServiceProvider($this->app))->register();
    $definitions = config('statamic.greenwashing-checker.jurisdictions');
    expect(array_keys($definitions))->toBe(['eu', 'de', 'custom'])
        ->and($definitions['de']['name'])->toBe('greenwashing-checker::settings.jurisdictions.de')
        ->and($definitions['de']['references'])->toHaveCount(9)
        ->and($definitions['de']['sources'])->toBe([['label' => 'Official source', 'url' => 'https://example.test/official']])
        ->and(Jurisdictions::references('default')['generic_claim'])->toBe('Custom official reference');
});

it('fails at application boot for an unknown configured global jurisdiction', function (array $settings, string $key) {
    $this->requireStatamic6('CP jurisdiction settings');
    $this->addon->settings()->set('jurisdiction', 'de')->save();
    foreach ($settings as $name => $value) {
        config(['statamic.greenwashing-checker.'.$name => $value]);
    }
    try {
        (new ServiceProvider($this->app))->register();
        $this->fail('Unknown jurisdiction was accepted at boot.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($key);
    }
})->with([
    [['jurisdiction' => 'unknown'], 'jurisdiction'],
]);

it('rejects unknown jurisdiction settings without writing them', function (array $changes, string $key) {
    $this->requireStatamic6('CP jurisdiction settings');
    $this->actingAs(User::make()->id('reviewer')->email('reviewer@example.test')->makeSuper());
    $this->patchJson(cp_route('addons.settings.update', $this->addon->slug()), array_replace([
        'jurisdiction' => 'de', 'jurisdiction_sites' => [],
        'threshold' => 'all', 'on_publish' => 'warn', 'placement' => 'sidebar',
    ], $changes))->assertUnprocessable()->assertJsonValidationErrors($key);
    expect($this->addon->settings()->raw())->toBe([]);
})->with([
    [['jurisdiction' => 'unknown'], 'jurisdiction'],
    [['jurisdiction_sites' => ['french' => 'unknown']], 'jurisdiction_sites.french'],
]);

it('does not change prompt semantics or cached analysis when display jurisdiction changes', function () {
    $this->requireStatamic6('CP jurisdiction settings');
    $cache = app(UnitCache::class);
    $hash = $cache->hash('nachhaltig');
    $prompt = fn () => view('greenwashing-checker::prompts.system', [
        'categories' => Category::cases(), 'audience' => 'b2c', 'houseRules' => '', 'explanationLocale' => 'de', 'contentLocale' => 'fr',
    ])->render();
    $before = $prompt();
    $this->addon->settings()->set(['jurisdiction' => 'eu', 'jurisdiction_sites' => ['default' => 'de']])->save();
    config(['statamic.greenwashing-checker.jurisdictions.de.references.generic_claim' => 'Different display reference']);
    expect($cache->hash('nachhaltig'))->toBe($hash)
        ->and($prompt())->toBe($before)
        ->and($before)->toContain('UGP-Richtlinie 2005/29/EG', 'EU-Richtlinie 2024/825', 'UCPD Annex I point 4a')
        ->and(Settings::analysis())->not->toHaveKeys(['jurisdiction', 'jurisdiction_sites', 'jurisdictions']);
});

it('shows the selected global jurisdiction sources through native field conditions', function () {
    $this->requireStatamic6('CP jurisdiction settings');
    $fields = $this->addon->settingsBlueprint()->fields();
    expect($fields->get('legal_basis_eu')->config()['if'])->toBe(['jurisdiction' => 'equals eu'])
        ->and($fields->get('legal_basis_de')->config()['if'])->toBe(['jurisdiction' => 'equals de'])
        ->and($fields->get('legal_basis_eu')->config()['instructions'])->toContain('https://eur-lex.europa.eu/', 'https://commission.europa.eu/')->not->toContain('recht.bund.de')
        ->and($fields->get('legal_basis_de')->config()['instructions'])->toContain('https://www.recht.bund.de/', 'https://www.umweltbundesamt.de/');
});

it('preloads references for every publish site, including entry creation', function () {
    $this->requireStatamic6('CP jurisdiction settings');
    Statamic\Facades\Site::setSites([
        'default' => ['name' => 'German', 'locale' => 'de', 'url' => '/'],
        'french' => ['name' => 'French', 'locale' => 'fr', 'url' => '/fr'],
    ]);
    $this->addon->settings()->set('jurisdiction_sites', ['french' => 'eu'])->save();
    app()->setLocale('en');
    $field = new Statamic\Fields\Field('checker', ['type' => 'greenwashing_checker']);
    $field->setParent(Statamic\Facades\Collection::make('products'));
    $meta = $field->fieldtype()->preload();
    expect($meta['normsBySite']['french']['generic_claim'])->toBe('UCPD Annex I point 4a')
        ->and($meta['normsBySite']['default']['generic_claim'])->toBe('UCPD Annex I point 4a · DE: UWG Annex no. 4a');
});

it('saves and clears a native site override map independently of legacy config', function () {
    $this->requireStatamic6('CP jurisdiction settings');
    config(['statamic.greenwashing-checker.jurisdiction_sites' => ['default' => 'eu']]);
    $this->actingAs(User::make()->id('reviewer')->email('reviewer@example.test')->makeSuper());
    $url = cp_route('addons.settings.update', $this->addon->slug());
    $values = ['jurisdiction' => 'de', 'threshold' => 'all', 'on_publish' => 'warn', 'placement' => 'sidebar'];
    $this->patchJson($url, [...$values, 'jurisdiction_sites' => ['default' => 'de']])->assertOk();
    expect(Settings::get('jurisdiction_sites'))->toBe(['default' => 'de']);
    $this->patchJson($url, [...$values, 'jurisdiction_sites' => []])->assertOk();
    expect(Settings::get('jurisdiction_sites'))->toBe([]);
});

it('fails at boot for an unknown jurisdiction in a manually edited CP settings file', function () {
    $this->requireStatamic6('CP jurisdiction settings');
    $path = $this->addon->settings()->path();
    $this->app['files']->ensureDirectoryExists(dirname($path));
    $this->app['files']->put($path, "jurisdiction_sites:\n  default: unknown\n");
    try {
        (new ServiceProvider($this->app))->register();
        $this->fail('Unknown saved site jurisdiction was accepted.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('jurisdiction_sites.default');
    }
});

it('restores configured behaviour selections when they are cleared in the CP', function (string $handle, array $default) {
    $this->requireStatamic6('CP jurisdiction settings');
    config(['statamic.greenwashing-checker.'.$handle => $default]);
    $this->actingAs(User::make()->id('reviewer')->email('reviewer@example.test')->makeSuper());
    $this->patchJson(cp_route('addons.settings.update', $this->addon->slug()), [
        'jurisdiction' => 'de', 'threshold' => 'all', 'on_publish' => 'warn', 'placement' => 'sidebar',
        $handle => [],
    ])->assertOk();
    expect(Settings::get($handle))->toBe($default);
})->with([['categories', ['generic_claim']], ['collections', ['products']]]);
