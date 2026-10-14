<?php

declare(strict_types=1);

use ElSchneider\GreenwashingChecker\Ai\AiException;
use ElSchneider\GreenwashingChecker\Ai\TrackedJobs;
use ElSchneider\GreenwashingChecker\Jobs\AnalyzeEntryJob;
use ElSchneider\GreenwashingChecker\Storage\UnitCache;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Statamic\Facades\Addon;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Role;
use Statamic\Facades\User;

beforeEach(function () {
    $this->collection = Collection::make('products')->title('Products');
    $this->collection->save();
    $blueprint = Blueprint::make('product')->setNamespace('collections.products')->setContents([
        'tabs' => ['main' => ['sections' => [['fields' => [
            ['handle' => 'teaser', 'field' => ['type' => 'textarea', 'display' => 'Teaser']],
            ['handle' => 'private_note', 'field' => ['type' => 'text', 'greenwashing_checker' => false]],
            ['handle' => 'body', 'field' => ['type' => 'bard', 'sets' => [
                'quote' => ['fields' => [
                    ['handle' => 'quote', 'field' => ['type' => 'textarea']],
                    ['handle' => 'cards', 'field' => ['type' => 'replicator', 'sets' => [
                        'card' => ['fields' => [['handle' => 'copy', 'field' => ['type' => 'markdown']]]],
                    ]]],
                ]],
            ]]],
            ['handle' => 'blocks', 'field' => ['type' => 'replicator', 'sets' => [
                'text' => ['fields' => [['handle' => 'text', 'field' => ['type' => 'text']]]],
            ]]],
        ]]]]],
    ]);
    $blueprint->save();
    $this->entry = Entry::make()->id('product-1')->collection('products')->blueprint('product')->locale('default')->slug('demo')->data(['title' => 'Demo']);
    $this->entry->save();
    $this->user = User::make()->id('editor')->email('editor@example.test')->set('super', true);
    $this->actingAs($this->user);
    $this->payload = [
        'reference' => 'entry::product-1', 'collection' => 'products', 'blueprint' => 'product',
        'site' => 'default', 'values' => ['title' => 'Demo', 'teaser' => 'Ein nachhaltiges Produkt.', 'private_note' => 'umweltfreundlich', 'blocks' => [['type' => 'text', 'text' => 'ökologisch']]],
    ];
});

function claimResponse(array $overrides = []): array
{
    return array_replace(['unit_id' => 'teaser', 'quote' => 'nachhaltiges', 'category' => 'generic_claim', 'severity' => 'high', 'explanation' => 'Die Aussage könnte zu unbestimmt sein.', 'suggestions' => ['konkret beschriebenes']], $overrides);
}

it('analyzes unsaved values with sync queue and returns cached findings by exact path', function () {
    $fake = Prism::fake([StructuredResponseFake::make()->withStructured(['findings' => [claimResponse(), claimResponse(['unit_id' => 'blocks.0.text', 'quote' => 'ökologisch'])]])]);
    $response = $this->postJson(cp_route('greenwashing-checker.analyze'), $this->payload)->assertStatus(202)->assertJsonPath('status', 'queued');
    $status = $this->getJson($response->json('poll'))->assertOk()->assertJsonPath('status', 'completed');
    $result = $status->json('result');
    expect(array_keys($result['units']))->toBe(['title', 'teaser', 'blocks.0.text']);
    expect($result['units']['teaser']['findings'][0])->toMatchArray(['path' => 'teaser', 'anchored' => true, 'dismissed' => false, 'category' => 'generic_claim']);
    expect($result['units']['blocks.0.text']['findings'][0]['path'])->toBe('blocks.0.text');
    $this->postJson(cp_route('greenwashing-checker.analyze'), $this->payload)->assertOk()->assertJsonPath('status', 'completed')->assertJsonMissingPath('job');
    $fake->assertCallCount(1);
});

it('returns actionable full paths for Bard set fields and nested replicator copy', function () {
    $quotePath = 'body.2.attrs.values.quote';
    $copyPath = 'body.2.attrs.values.cards.0.copy';
    $this->payload['values'] = ['body' => [
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Die Jacke wiegt 420 Gramm.']]],
        ['type' => 'set', 'attrs' => ['values' => ['type' => 'quote', 'enabled' => false, 'quote' => 'Versteckt']]],
        ['type' => 'set', 'attrs' => ['values' => [
            'type' => 'quote', 'quote' => 'Gut für die Umwelt.',
            'cards' => [['type' => 'card', 'copy' => 'Diese Jacke ist nachhaltig.']],
        ]]],
    ]];
    $fake = Prism::fake([StructuredResponseFake::make()->withStructured(['findings' => [
        claimResponse(['unit_id' => $quotePath, 'quote' => 'Gut für die Umwelt', 'suggestions' => ['Für den Alltag']]),
        claimResponse(['unit_id' => $copyPath, 'quote' => 'nachhaltig', 'suggestions' => ['waschbar']]),
    ]])]);
    $response = $this->postJson(cp_route('greenwashing-checker.analyze'), $this->payload)->assertStatus(202);
    $result = $this->getJson($response->json('poll'))->assertJsonPath('status', 'completed')->json('result');
    expect(array_keys($result['units']))->toBe(['body', $quotePath, $copyPath]);
    expect($result['units'][$quotePath]['findings'][0])->toMatchArray(['path' => $quotePath, 'anchored' => true, 'suggestions' => ['Für den Alltag']]);
    expect($result['units'][$copyPath]['findings'][0])->toMatchArray(['path' => $copyPath, 'anchored' => true, 'suggestions' => ['waschbar']]);
    $fake->assertCallCount(1);
});

it('persists dismissals and rebinds cached findings to a different unit path', function () {
    $fake = Prism::fake([StructuredResponseFake::make()->withStructured(['findings' => [claimResponse()]])]);
    $response = $this->postJson(cp_route('greenwashing-checker.analyze'), $this->payload)->assertStatus(202);
    $unit = $this->getJson($response->json('poll'))->json('result.units.teaser');
    $this->postJson(cp_route('greenwashing-checker.dismiss'), [...$this->payload, 'unit_hash' => $unit['hash'], 'finding_id' => $unit['findings'][0]['id']])->assertOk()->assertExactJson(['dismissed' => true]);
    $this->payload['values']['title'] = $this->payload['values']['teaser'];
    $result = $this->postJson(cp_route('greenwashing-checker.analyze'), $this->payload)->assertOk()->json('result');
    expect($result['units']['title']['findings'][0])->toMatchArray(['dismissed' => true, 'path' => 'title']);
    expect(app(UnitCache::class)->get($unit['hash'])['dismissals'][$unit['findings'][0]['id']]['user'])->toBe('editor');
    $restored = ['unit_hash' => $unit['hash'], 'finding_id' => $unit['findings'][0]['id'], 'dismissed' => false];
    $this->postJson(cp_route('greenwashing-checker.dismiss'), [...$this->payload, ...$restored])->assertOk()->assertExactJson(['dismissed' => false]);
    $result = $this->postJson(cp_route('greenwashing-checker.analyze'), $this->payload)->assertOk()->json('result');
    expect($result['units']['title']['findings'][0]['dismissed'])->toBeFalse();
    expect($result['units']['teaser']['findings'][0]['dismissed'])->toBeFalse();
    expect(app(UnitCache::class)->get($unit['hash'])['dismissals'])->toBe([]);
    $fake->assertCallCount(1);
});

it('drops unknown targets loudly and preserves unanchored quotes with at most three suggestions', function () {
    Log::spy();
    Prism::fake([StructuredResponseFake::make()->withStructured(['findings' => [claimResponse(['unit_id' => 'invented']), claimResponse(['quote' => 'not in the text', 'suggestions' => ['a', 'b', 'c', 'd']])]])]);
    $response = $this->postJson(cp_route('greenwashing-checker.analyze'), $this->payload)->assertStatus(202);
    $status = $this->getJson($response->json('poll'))->assertJsonPath('status', 'completed');
    expect($status->json('result.units.teaser.findings.0'))->toMatchArray(['anchored' => false, 'suggestions' => ['a', 'b', 'c']]);
    Log::shouldHaveReceived('warning')->once()->with('Greenwashing response contained unknown TARGET units.', ['count' => 1]);
});

it('requests one to three quote-scoped suggestions with placeholders for missing facts', function () {
    $fake = Prism::fake([StructuredResponseFake::make()->withStructured(['findings' => []])]);
    $this->postJson(cp_route('greenwashing-checker.analyze'), $this->payload)->assertAccepted();
    $fake->assertRequest(function (array $requests) {
        $request = collect($requests)->flatten()->last();
        $suggestions = $request->schema()->toArray()['properties']['findings']['items']['properties']['suggestions'];
        expect($suggestions)->toMatchArray(['minItems' => 1, 'maxItems' => 3]);
        $prompt = implode("\n", array_map(fn ($message) => $message->content, $request->systemPrompts()));
        expect($prompt)->toContain('suggestions: 1–3', 'Jede suggestion ersetzt NUR quote', 'passende Wortgruppe', '[Nachweis einfügen]', 'niemals leer');
    });
    $fake->assertCallCount(1);
});

it('keeps findings without suggestions and logs their count once per analysis', function () {
    Log::spy();
    $fake = Prism::fake([StructuredResponseFake::make()->withStructured(['findings' => [
        claimResponse(['suggestions' => []]),
        claimResponse(['unit_id' => 'blocks.0.text', 'quote' => 'ökologisch', 'suggestions' => []]),
        claimResponse(['unit_id' => 'invented', 'suggestions' => []]),
    ]])]);
    $queued = $this->postJson(cp_route('greenwashing-checker.analyze'), $this->payload)->assertAccepted();
    $result = $this->getJson($queued->json('poll'))->assertJsonPath('status', 'completed')->json('result');
    expect($result['units']['teaser']['findings'])->toHaveCount(1);
    expect($result['units']['teaser']['findings'][0])->toMatchArray(['quote' => 'nachhaltiges', 'suggestions' => []]);
    expect($result['units']['blocks.0.text']['findings'])->toHaveCount(1);
    expect($result['units']['blocks.0.text']['findings'][0])->toMatchArray(['quote' => 'ökologisch', 'suggestions' => []]);
    $this->postJson(cp_route('greenwashing-checker.analyze'), $this->payload)->assertOk()
        ->assertJsonPath('result.units.teaser.findings.0.suggestions', []);
    Log::shouldHaveReceived('info')->once()->with('Greenwashing response contained findings without suggestions.', ['count' => 2]);
    $fake->assertCallCount(1);
});

it('rejects invalid model output through the safe failed-job contract', function () {
    Prism::fake([StructuredResponseFake::make()->withStructured(['findings' => [['unit_id' => 'teaser']]])]);
    $response = $this->postJson(cp_route('greenwashing-checker.analyze'), $this->payload)->assertStatus(202);
    $this->getJson($response->json('poll'))->assertJsonPath('status', 'failed')->assertJsonPath('error.code', 'invalid_response')->assertJsonMissingPath('result');
});

it('logs the original provider failure once at the job boundary and returns only a safe error', function () {
    Log::spy();
    $original = new PrismException('provider-private-detail', 401);
    Prism::shouldReceive('structured')->once()->andThrow($original);
    $response = $this->postJson(cp_route('greenwashing-checker.analyze'), $this->payload)->assertStatus(202);
    $this->getJson($response->json('poll'))->assertJsonPath('status', 'failed')
        ->assertJsonPath('error.code', 'authentication')->assertDontSee('provider-private-detail');
    Log::shouldHaveReceived('error')->once()->with('greenwashing-checker: analysis failed', Mockery::on(
        fn (array $context) => $context['exception'] === $original && $context['code'] === 'authentication'
            && $context['model'] === 'openai/test-model' && $context['job'] === $response->json('job')
            && $context['entry'] === 'entry::product-1'
            && ! array_key_exists('api_key', $context)
    ));
});

it('logs queue worker failures once and stores a safe terminal error', function () {
    Log::spy();
    $jobs = app(TrackedJobs::class);
    $id = $jobs->create('greenwashing-checker', 'editor');
    $original = new RuntimeException('worker-private-detail');
    (new AnalyzeEntryJob($id, [], [], 'de', 'de', [], [], 'entry::product-1'))->failed($original);
    $this->getJson(cp_route('greenwashing-checker.jobs.show', ['job' => $id]))->assertJsonPath('status', 'failed')
        ->assertJsonPath('error.code', 'unexpected')->assertDontSee('worker-private-detail');
    Log::shouldHaveReceived('error')->once()->with('greenwashing-checker: analysis failed', Mockery::on(
        fn (array $context) => $context['exception'] === $original && $context['job'] === $id
    ));
});

it('does not disclose job results to another user or scope', function () {
    $jobs = app(TrackedJobs::class);
    foreach ([$jobs->create('greenwashing-checker', 'other'), $jobs->create('other-addon', 'editor')] as $id) {
        $this->getJson(cp_route('greenwashing-checker.jobs.show', ['job' => $id]))->assertNotFound();
    }
});

it('rejects guests before analysis', function () {
    $this->app['auth']->forgetGuards();
    $fake = Prism::fake();
    $this->postJson(cp_route('greenwashing-checker.analyze'), $this->payload)->assertUnauthorized();
    $fake->assertCallCount(0);
});

it('rejects a CP user without edit permission and does not call the provider', function () {
    Role::make('cp')->permissions(['access cp'])->save();
    $this->actingAs(User::make()->id('reader')->email('reader@example.test')->set('roles', ['cp']));
    $fake = Prism::fake();
    $this->postJson(cp_route('greenwashing-checker.analyze'), $this->payload)->assertForbidden();
    $fake->assertCallCount(0);
});

it('authorizes new entries with the collection and site policy', function () {
    Role::make('creator')->permissions(['access cp', 'create products entries'])->save();
    $this->actingAs(User::make()->id('creator')->email('creator@example.test')->set('roles', ['creator']));
    $this->payload['reference'] = null;
    Prism::fake([StructuredResponseFake::make()->withStructured(['findings' => []])]);
    $this->postJson(cp_route('greenwashing-checker.analyze'), $this->payload)->assertStatus(202);
});

it('rejects a new entry without create permission', function () {
    Role::make('cp')->permissions(['access cp'])->save();
    $this->actingAs(User::make()->id('reader')->email('reader@example.test')->set('roles', ['cp']));
    $this->payload['reference'] = null;
    $this->postJson(cp_route('greenwashing-checker.analyze'), $this->payload)->assertForbidden();
});

it('rejects disabled collections before sending any text', function () {
    config(['statamic.greenwashing-checker.collections' => ['news']]);
    $fake = Prism::fake();
    $this->postJson(cp_route('greenwashing-checker.analyze'), $this->payload)->assertForbidden();
    $fake->assertCallCount(0);
});

it('reports missing credentials or invalid model as configuration errors', function (string $model) {
    Log::spy();
    config(['prism.providers.openai.api_key' => '', 'statamic.greenwashing-checker.model' => $model]);
    $fake = Prism::fake();
    $this->postJson(cp_route('greenwashing-checker.analyze'), $this->payload)->assertStatus(409)->assertJsonPath('code', 'configuration')->assertJsonPath('retryable', false);
    $fake->assertCallCount(0);
    Log::shouldHaveReceived('error')->once()->with('greenwashing-checker: configuration failed', Mockery::on(
        fn (array $context) => $context['exception'] instanceof AiException && $context['code'] === 'configuration'
            && $context['model'] === $model && $context['entry'] === 'entry::product-1'
            && ! array_key_exists('api_key', $context)
    ));
})->with(['openai/test-model', 'invalid']);

it('rejects mismatched references and malformed values at the boundary', function () {
    $this->payload['blueprint'] = 'unrelated';
    $this->postJson(cp_route('greenwashing-checker.analyze'), $this->payload)->assertStatus(422);
    $this->payload['blueprint'] = 'product';
    $this->payload['values']['teaser'] = 123;
    $this->postJson(cp_route('greenwashing-checker.analyze'), $this->payload)->assertStatus(422);
});

it('returns an empty unit object without a provider call for empty values', function () {
    $this->payload['values'] = [];
    $fake = Prism::fake();
    $this->postJson(cp_route('greenwashing-checker.analyze'), $this->payload)->assertOk()->assertJsonPath('status', 'completed')->assertSee('"units":{}', false);
    $fake->assertCallCount(0);
});

it('returns 404 for unknown dismissals', function () {
    $this->postJson(cp_route('greenwashing-checker.dismiss'), [...$this->payload, 'unit_hash' => str_repeat('a', 64), 'finding_id' => str_repeat('b', 40)])->assertNotFound();
});

it('round trips analysis and dismissals when tracking and locks use Laravel 13 restricted cache', function () {
    $directory = sys_get_temp_dir().'/greenwashing-cache-'.Illuminate\Support\Str::uuid();
    config(['statamic.stache.cache_store' => 'array', 'cache.default' => 'restricted', 'cache.stores.restricted' => ['driver' => 'file', 'path' => $directory], 'cache.serializable_classes' => false]);
    $this->app->forgetInstance('cache.store');
    $this->app->forgetInstance(TrackedJobs::class);
    try {
        Prism::fake([StructuredResponseFake::make()->withStructured(['findings' => [claimResponse()]])]);
        $response = $this->postJson(cp_route('greenwashing-checker.analyze'), $this->payload)->assertStatus(202);
        $unit = $this->getJson($response->json('poll'))->assertJsonPath('status', 'completed')->json('result.units.teaser');
        $this->postJson(cp_route('greenwashing-checker.dismiss'), [...$this->payload, 'unit_hash' => $unit['hash'], 'finding_id' => $unit['findings'][0]['id']])->assertOk();
        $this->postJson(cp_route('greenwashing-checker.analyze'), $this->payload)->assertOk()->assertJsonPath('result.units.teaser.findings.0.dismissed', true);
    } finally {
        $this->app['files']->deleteDirectory($directory);
    }
});

it('sends only changed units as targets while context is capped and prefers title', function () {
    $fake = Prism::fake([
        StructuredResponseFake::make()->withStructured(['findings' => []]),
        StructuredResponseFake::make()->withStructured(['findings' => []]),
    ]);
    config(['statamic.greenwashing-checker.max_context_chars' => 7]);
    $this->postJson(cp_route('greenwashing-checker.analyze'), $this->payload)->assertStatus(202);
    $this->payload['values']['teaser'] = 'Ein umweltfreundliches Produkt mit langem Begleittext.';
    $this->postJson(cp_route('greenwashing-checker.analyze'), $this->payload)->assertStatus(202);
    $fake->assertRequest(function (array $requests) {
        $prompt = collect($requests)->flatten()->last()->prompt();
        preg_match('/TARGET \(.*?\):\s*(\[.*?\])\s*CONTEXT/s', $prompt, $matches);
        $targets = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
        expect(array_column($targets, 'path'))->toBe(['teaser']);
        preg_match('/CONTEXT \(.*?\):\s*(\[.*?\])\s*Unternehmensnachweise/s', $prompt, $matches);
        $context = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
        expect($context[0]['path'])->toBe('title');
        expect(array_sum(array_map(fn ($unit) => mb_strlen($unit['text']), $context)))->toBe(7);
    });
    $fake->assertCallCount(2);
});

it('returns the cached snapshot when a unit is pruned during response assembly', function () {
    $this->payload['values'] = ['teaser' => 'Ein nachhaltiges Produkt.'];
    Prism::fake([StructuredResponseFake::make()->withStructured(['findings' => [claimResponse()]])]);
    $response = $this->postJson(cp_route('greenwashing-checker.analyze'), $this->payload)->assertStatus(202);
    $unit = $this->getJson($response->json('poll'))->json('result.units.teaser');
    $files = Mockery::mock(Illuminate\Support\Facades\File::getFacadeRoot())->makePartial();
    $files->shouldReceive('get')->withArgs(fn ($path) => str_ends_with($path, '/'.$unit['hash'].'.json'))->once()->andReturnUsing(function ($path) {
        $contents = file_get_contents($path);
        unlink($path);

        return $contents;
    });
    Illuminate\Support\Facades\File::swap($files);
    $this->postJson(cp_route('greenwashing-checker.analyze'), $this->payload)->assertOk()
        ->assertJsonPath('result.units.teaser.findings.0.quote', 'nachhaltiges');
});

it('analyzes identical uncached text once and rebinds findings and dismissals to both paths', function () {
    $this->payload['values'] = ['teaser' => 'Ein nachhaltiges Produkt.', 'blocks' => [['type' => 'text', 'text' => 'Ein nachhaltiges Produkt.']]];
    $fake = Prism::fake([StructuredResponseFake::make()->withStructured(['findings' => [claimResponse()]])]);
    $response = $this->postJson(cp_route('greenwashing-checker.analyze'), $this->payload)->assertStatus(202);
    $result = $this->getJson($response->json('poll'))->assertJsonPath('status', 'completed')->json('result');
    expect($result['units']['teaser']['findings'][0]['path'])->toBe('teaser');
    expect($result['units']['blocks.0.text']['findings'][0]['path'])->toBe('blocks.0.text');
    expect($result['units']['blocks.0.text']['hash'])->toBe($result['units']['teaser']['hash']);
    $fake->assertRequest(function (array $requests) {
        $prompt = collect($requests)->flatten()->last()->prompt();
        preg_match('/TARGET \(.*?\):\s*(\[.*?\])\s*CONTEXT/s', $prompt, $matches);
        $targets = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
        expect(array_column($targets, 'path'))->toBe(['teaser']);
    });
    $unit = $result['units']['teaser'];
    $this->postJson(cp_route('greenwashing-checker.dismiss'), [...$this->payload, 'unit_hash' => $unit['hash'], 'finding_id' => $unit['findings'][0]['id']])->assertOk();
    $cached = $this->postJson(cp_route('greenwashing-checker.analyze'), $this->payload)->assertOk()->json('result');
    expect($cached['units']['teaser']['findings'][0]['dismissed'])->toBeTrue();
    expect($cached['units']['blocks.0.text']['findings'][0]['dismissed'])->toBeTrue();
    $fake->assertCallCount(1);
});

it('bounds the client timeout below the running job stale threshold', function (int $configured, int $expected) {
    config(['statamic.greenwashing-checker.timeout' => $configured]);
    $fake = Prism::fake([StructuredResponseFake::make()->withStructured(['findings' => []])]);
    $response = $this->postJson(cp_route('greenwashing-checker.analyze'), $this->payload)->assertStatus(202);
    $this->getJson($response->json('poll'))->assertJsonPath('status', 'completed');
    $fake->assertRequest(function (array $requests) use ($expected) {
        expect(collect($requests)->flatten()->last()->clientOptions()['timeout'])->toBe($expected);
    });
})->with([[60, 60], [180, 110], [0, 1]]);

it('returns a readable 422 for missing replicator types and invalid nested Bard JSON without calling the provider', function (array $values) {
    $fake = Prism::fake();
    $this->payload['values'] = $values;
    $this->postJson(cp_route('greenwashing-checker.analyze'), $this->payload)->assertStatus(422)
        ->assertJsonPath('message', __('greenwashing-checker::messages.errors.invalid_values'));
    $fake->assertCallCount(0);
})->with([
    [['blocks' => [['text' => 'Missing type']]]],
    [['blocks' => [['type' => 42, 'text' => 'Invalid type']]]],
    [['body' => '{broken']],
]);

it('validates restore state and refuses to change dismissals without CP access', function () {
    Prism::fake([StructuredResponseFake::make()->withStructured(['findings' => [claimResponse()]])]);
    $response = $this->postJson(cp_route('greenwashing-checker.analyze'), $this->payload)->assertStatus(202);
    $unit = $this->getJson($response->json('poll'))->json('result.units.teaser');
    $payload = ['unit_hash' => $unit['hash'], 'finding_id' => $unit['findings'][0]['id']];
    $this->postJson(cp_route('greenwashing-checker.dismiss'), [...$this->payload, ...$payload])->assertOk();
    $this->postJson(cp_route('greenwashing-checker.dismiss'), [...$this->payload, ...$payload, 'dismissed' => 'not-boolean'])->assertStatus(422);
    Role::make('no-cp')->permissions([])->save();
    $this->actingAs(User::make()->id('no-cp')->email('no-cp@example.test')->set('roles', ['no-cp']));
    $this->postJson(cp_route('greenwashing-checker.dismiss'), [...$this->payload, ...$payload, 'dismissed' => false])->assertForbidden();
    expect(app(UnitCache::class)->get($unit['hash'])['dismissals'])->toHaveKey($unit['findings'][0]['id']);
});

it('filters medium risks without another model call when the display threshold changes', function () {
    $fake = Prism::fake([StructuredResponseFake::make()->withStructured(['findings' => [claimResponse(['category' => 'misleading_environmental', 'severity' => 'medium'])]])]);
    $queued = $this->postJson(cp_route('greenwashing-checker.analyze'), $this->payload)->assertAccepted();
    $this->getJson($queued->json('poll'))->assertJsonCount(1, 'result.units.teaser.findings');
    config(['statamic.greenwashing-checker.threshold' => 'high']);
    $this->postJson(cp_route('greenwashing-checker.analyze'), $this->payload)->assertOk()->assertJsonCount(0, 'result.units.teaser.findings');
    config(['statamic.greenwashing-checker.threshold' => 'all']);
    $this->postJson(cp_route('greenwashing-checker.analyze'), $this->payload)->assertOk()->assertJsonCount(1, 'result.units.teaser.findings');
    $fake->assertCallCount(1);
});

it('excludes disabled and B2B blacklist categories even if the provider returns them', function () {
    $this->requireStatamic6('CP-only audience setting');
    config(['statamic.greenwashing-checker.categories' => ['misleading_environmental']]);
    Addon::get('el-schneider/statamic-greenwashing-checker')->settings()->set('audience', [['collection' => 'products', 'audience' => 'b2b']])->save();
    $fake = Prism::fake([StructuredResponseFake::make()->withStructured(['findings' => [claimResponse(), claimResponse(['category' => 'misleading_environmental', 'severity' => 'medium'])]])]);
    $queued = $this->postJson(cp_route('greenwashing-checker.analyze'), $this->payload)->assertAccepted();
    $this->getJson($queued->json('poll'))->assertJsonCount(1, 'result.units.teaser.findings')->assertJsonPath('result.units.teaser.findings.0.category', 'misleading_environmental');
    $fake->assertCallCount(1);
});

it('logs malformed publish values with their exact field path before returning a safe validation error', function () {
    Log::spy();
    $this->payload['values'] = ['blocks' => [['text' => 'nachhaltig']]];
    $this->postJson(cp_route('greenwashing-checker.analyze'), $this->payload)->assertUnprocessable()->assertJsonMissingPath('exception');
    Log::shouldHaveReceived('warning')->once()->with('Greenwashing publish values could not be read.', Mockery::on(fn (array $context) => $context['exception'] instanceof UnexpectedValueException && str_contains($context['exception']->getMessage(), 'blocks.0')));
});

it('requires entry authorization for dismiss and restore, not just CP access', function () {
    $fake = Prism::fake([StructuredResponseFake::make()->withStructured(['findings' => [claimResponse()]])]);
    $response = $this->postJson(cp_route('greenwashing-checker.analyze'), $this->payload)->assertAccepted();
    $unit = $this->getJson($response->json('poll'))->json('result.units.teaser');
    Role::make('cp-only')->permissions(['access cp'])->save();
    $this->actingAs(User::make()->id('cp-only')->email('cp-only@example.test')->assignRole('cp-only'));
    foreach ([true, false] as $dismissed) {
        $this->postJson(cp_route('greenwashing-checker.dismiss'), [...$this->payload, 'unit_hash' => $unit['hash'], 'finding_id' => $unit['findings'][0]['id'], 'dismissed' => $dismissed])->assertForbidden();
    }
    expect(app(UnitCache::class)->get($unit['hash'])['dismissals'])->toBe([]);
});

it('assigns severity from the legal category rather than provider opinion', function (string $category, string $severity) {
    Prism::fake([StructuredResponseFake::make()->withStructured(['findings' => [claimResponse(['category' => $category, 'severity' => $severity === 'high' ? 'medium' : 'high'])]])]);
    $queued = $this->postJson(cp_route('greenwashing-checker.analyze'), $this->payload)->assertAccepted();
    $this->getJson($queued->json('poll'))->assertJsonPath('result.units.teaser.findings.0.severity', $severity);
})->with([
    ['generic_claim', 'high'], ['offset_neutrality', 'high'], ['scope_overreach', 'high'], ['unofficial_label', 'high'], ['legal_requirement_as_feature', 'high'],
    ['future_performance', 'medium'], ['irrelevant_benefit', 'medium'], ['misleading_environmental', 'medium'], ['durability_repair', 'medium'],
]);

it('keeps every enabled B2B category medium regardless of the provider severity', function (string $category) {
    $this->requireStatamic6('CP-only audience setting');
    Addon::get('el-schneider/statamic-greenwashing-checker')->settings()->set('audience', [['collection' => 'products', 'audience' => 'b2b']])->save();
    Prism::fake([StructuredResponseFake::make()->withStructured(['findings' => [claimResponse(['category' => $category, 'severity' => 'high'])]])]);
    $queued = $this->postJson(cp_route('greenwashing-checker.analyze'), $this->payload)->assertAccepted();
    $this->getJson($queued->json('poll'))->assertJsonPath('result.units.teaser.findings.0.severity', 'medium');
})->with(['future_performance', 'irrelevant_benefit', 'misleading_environmental']);

it('returns a readable corrupt unit filename through analysis and decision endpoints without provider calls', function () {
    $directory = sys_get_temp_dir().'/corrupt-unit-http-'.Illuminate\Support\Str::uuid();
    $this->app->instance(UnitCache::class, new UnitCache($directory));
    $this->payload['values'] = ['teaser' => 'Ein nachhaltiges Produkt.'];
    $fake = Prism::fake([StructuredResponseFake::make()->withStructured(['findings' => [claimResponse()]])]);
    try {
        $queued = $this->postJson(cp_route('greenwashing-checker.analyze'), $this->payload)->assertAccepted();
        $unit = $this->getJson($queued->json('poll'))->json('result.units.teaser');
        $path = $directory.'/'.$unit['hash'].'.json';
        Illuminate\Support\Facades\File::put($path, '{broken');
        $decision = [
            'reference' => $this->payload['reference'], 'collection' => 'products', 'site' => 'default',
            'unit_hash' => $unit['hash'], 'finding_id' => $unit['findings'][0]['id'],
        ];
        Log::spy();
        foreach ([
            ['greenwashing-checker.analyze', $this->payload],
            ['greenwashing-checker.dismiss', $decision],
            ['greenwashing-checker.approve', [...$decision, 'reason' => 'Evidence']],
        ] as [$route, $payload]) {
            $response = $this->postJson(cp_route($route), $payload)->assertStatus(500)->assertJsonPath('code', 'unit_file')
                ->assertJsonPath('retryable', false);
            expect($response->json('message'))->toContain($unit['hash'].'.json')->not->toContain($directory);
        }
        Log::shouldHaveReceived('error')->times(3)->with('greenwashing-checker: unit file could not be read', Mockery::on(fn ($context) => $context['file'] === $unit['hash'].'.json'));
        expect(Illuminate\Support\Facades\File::get($path))->toBe('{broken');
        $fake->assertCallCount(1);
    } finally {
        Illuminate\Support\Facades\File::deleteDirectory($directory);
    }
});
