<?php

declare(strict_types=1);

use ElSchneider\GreenwashingChecker\Storage\EntryIndex;
use ElSchneider\GreenwashingChecker\Storage\UnitCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Statamic\Facades\Addon;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Role;
use Statamic\Facades\User;
use Statamic\Facades\Utility;

beforeEach(function () {
    Collection::make('products')->title('Products')->save();
    Blueprint::make('product')->setNamespace('collections.products')->setContents([
        'tabs' => ['main' => ['sections' => [['fields' => [
            ['handle' => 'title', 'field' => ['type' => 'text']],
            ['handle' => 'copy', 'field' => ['type' => 'textarea']],
            ['handle' => 'private', 'field' => ['type' => 'textarea', 'greenwashing_checker' => false]],
        ]]]]],
    ])->save();
    $this->entry = Entry::make()->id('scan-1')->collection('products')->blueprint('product')->locale('default')->slug('scan')->data(['title' => 'Eco', 'copy' => 'Ein nachhaltiges Produkt.', 'private' => 'Secret'])->save();
    $this->entry = Entry::find('scan-1');
    $this->user = User::make()->id('scanner')->email('scanner@example.test')->set('super', true);
    $this->user->save();
    $this->actingAs($this->user);
});

function scanResponse(string $quote = 'nachhaltiges', string $severity = 'high'): StructuredResponseFake
{
    return StructuredResponseFake::make()->withStructured(['findings' => [[
        'unit_id' => 'copy', 'quote' => $quote, 'category' => 'generic_claim', 'severity' => $severity,
        'explanation' => 'Unbelegte Aussage.', 'suggestions' => [],
    ]]]);
}

it('previews saved eligible entries and opted-in text without provider calls or index writes', function () {
    $fake = Prism::fake();
    $this->artisan('greenwashing:scan', ['--collection' => ['products'], '--site' => 'default', '--dry-run' => true])
        ->expectsOutputToContain('entries: 1 · units: 2 · uncached: 2 · characters: 28')->assertExitCode(0);
    expect(app(EntryIndex::class)->find('scan-1'))->toBeNull();
    $this->getJson(cp_route('utilities.greenwashing-scan.preview'))->assertOk()
        ->assertJsonPath('totals.entries', 1)->assertJsonPath('totals.units', 2)->assertJsonPath('totals.characters', 28);
    $fake->assertCallCount(0);
});

it('reuses the per-text cache across command reruns and different saved entries', function () {
    $fake = Prism::fake([scanResponse()]);
    $this->artisan('greenwashing:scan')->assertExitCode(0);
    $record = app(EntryIndex::class)->find('scan-1');
    expect($record['counts'])->toBe(['open' => 1, 'high' => 1, 'ignored' => 0, 'approved' => 0]);
    Entry::make()->id('scan-2')->collection('products')->blueprint('product')->locale('default')->slug('same')->data($this->entry->data()->all())->save();
    $this->artisan('greenwashing:scan')->assertExitCode(0);
    $this->artisan('greenwashing:scan', ['--dry-run' => true])->expectsOutputToContain('uncached: 0 · characters: 0')->assertExitCode(0);
    expect(app(EntryIndex::class)->find('scan-2')['counts'])->toBe($record['counts']);
    $fake->assertCallCount(1);
});

it('asks for explanations in the app locale on command scans', function () {
    app()->setLocale('en');
    $fake = Prism::fake([scanResponse()]);
    $this->artisan('greenwashing:scan')->assertExitCode(0);
    $fake->assertRequest(function (array $requests) {
        $prompt = implode("\n", array_map(fn ($message) => $message->content, collect($requests)->flatten()->last()->systemPrompts()));
        expect($prompt)->toContain('explanation: 1–2 einfache Sätze auf en,');
    });
});

it('continues after an entry fails and exits nonzero while indexing later successful entries', function () {
    $this->entry->set('copy', 42)->save();
    Entry::make()->id('scan-2')->collection('products')->blueprint('product')->locale('default')->slug('valid')->data(['title' => 'Valid', 'copy' => 'Ein nachhaltiges Produkt.'])->save();
    $fake = Prism::fake([scanResponse()]);
    $this->artisan('greenwashing:scan')->expectsOutputToContain('Eco [scan-1; default]:')
        ->expectsOutputToContain('Failed entries: 1')->assertExitCode(1);
    expect(app(EntryIndex::class)->find('scan-1'))->toBeNull();
    expect(app(EntryIndex::class)->find('scan-2')['counts']['high'])->toBe(1);
    $fake->assertCallCount(1);
});

it('continues after a provider failure without caching or indexing that entry', function () {
    Entry::make()->id('scan-2')->collection('products')->blueprint('product')->locale('default')->slug('valid')->data(['title' => 'Valid', 'copy' => 'Ein umweltfreundliches Produkt.'])->save();
    $fake = Prism::fake([
        StructuredResponseFake::make()->withStructured(['findings' => [['invalid' => true]]]),
        scanResponse('umweltfreundliches'),
    ]);
    $this->artisan('greenwashing:scan')->expectsOutputToContain('Failed entries: 1')->assertExitCode(1);
    expect(app(EntryIndex::class)->find('scan-1'))->toBeNull();
    expect(app(EntryIndex::class)->find('scan-2')['counts']['open'])->toBe(1);
    $fake->assertCallCount(2);
});

it('honours enabled collections and rejects unknown collection or site filters', function () {
    $fake = Prism::fake();
    config(['statamic.greenwashing-checker.collections' => []]);
    $this->artisan('greenwashing:scan')->assertExitCode(0);
    $this->getJson(cp_route('utilities.greenwashing-scan.preview'))->assertJsonPath('totals.entries', 0);
    $this->artisan('greenwashing:scan', ['--collection' => ['typo']])->assertExitCode(1);
    $this->artisan('greenwashing:scan', ['--site' => 'typo'])->assertExitCode(1);
    $this->getJson(cp_route('utilities.greenwashing-scan.preview', ['collection' => 'typo']))->assertUnprocessable();
    $fake->assertCallCount(0);
});

it('keeps index summaries after cache flush and marks edited text or changed analysis rules stale', function () {
    $this->requireStatamic6('CP-only house_rules setting');
    Prism::fake([scanResponse()]);
    $this->artisan('greenwashing:scan')->assertExitCode(0);
    $url = cp_route('utilities.greenwashing-scan.entries');
    $this->getJson($url)->assertJsonPath('rows.0.stale', false);
    Cache::flush();
    $this->getJson($url)->assertJsonPath('rows.0.counts.open', 1)->assertJsonPath('rows.0.stale', false);
    $this->entry->set('copy', 'Changed text')->save();
    $this->getJson($url)->assertJsonPath('rows.0.stale', true);
    $this->entry->set('copy', 'Ein nachhaltiges Produkt.')->save();
    Addon::get('el-schneider/statamic-greenwashing-checker')->settings()->set('house_rules', 'Evidence required')->save();
    $this->getJson($url)->assertJsonPath('rows.0.stale', true);
});

it('indexes CP checks of unsaved text as stale and cached checks of saved text as current', function () {
    $fake = Prism::fake([scanResponse(), scanResponse('umweltfreundliches')]);
    $payload = ['reference' => 'entry::scan-1', 'collection' => 'products', 'site' => 'default', 'blueprint' => 'product', 'values' => $this->entry->data()->all()];
    $this->postJson(cp_route('greenwashing-checker.analyze'), $payload)->assertAccepted();
    $this->getJson(cp_route('utilities.greenwashing-scan.entries'))->assertJsonPath('rows.0.stale', false);
    $payload['values']['copy'] = 'Ein umweltfreundliches Produkt.';
    $this->postJson(cp_route('greenwashing-checker.analyze'), $payload)->assertAccepted();
    $this->getJson(cp_route('utilities.greenwashing-scan.entries'))->assertJsonPath('rows.0.stale', true);
    $payload['values'] = $this->entry->data()->all();
    $this->postJson(cp_route('greenwashing-checker.analyze'), $payload)->assertOk();
    $this->getJson(cp_route('utilities.greenwashing-scan.entries'))->assertJsonPath('rows.0.stale', false);
    $fake->assertCallCount(2);
});

it('protects the native utility, preview, start and navigation with the scan permission', function () {
    Role::make('cp-only')->permissions(['access cp', 'edit products entries', 'access greenwashing_scan utility'])->save();
    $this->actingAs(User::make()->id('reader')->email('reader@example.test')->assignRole('cp-only'));
    $fake = Prism::fake();
    Queue::fake();
    $this->getJson(cp_route('utilities.greenwashing-scan'))->assertForbidden();
    $this->getJson(cp_route('utilities.greenwashing-scan.entries'))->assertForbidden();
    $this->getJson(cp_route('utilities.greenwashing-scan.preview'))->assertForbidden();
    $this->postJson(cp_route('utilities.greenwashing-scan.start'))->assertForbidden();
    expect(Utility::authorized()->has('greenwashing_scan'))->toBeFalse();
    Queue::assertNothingPushed();
    $fake->assertCallCount(0);
});

it('grants a non-super scanner utility access but limits rows and jobs to editable entries', function () {
    Role::make('scanner')->permissions(['access cp', 'scan greenwashing findings'])->save();
    $this->actingAs(User::make()->id('limited')->email('limited@example.test')->assignRole('scanner'));
    expect(Utility::authorized()->has('greenwashing_scan'))->toBeTrue();
    $this->get(cp_route('utilities.greenwashing-scan'))->assertOk();
    $this->getJson(cp_route('utilities.greenwashing-scan.preview'))->assertJsonPath('totals.entries', 0);
    Queue::fake();
    $this->postJson(cp_route('utilities.greenwashing-scan.start'))->assertAccepted()->assertJsonCount(0, 'jobs');
    Queue::assertNothingPushed();
});

it('queues per-entry jobs and exposes owner-scoped completion through polling', function () {
    $fake = Prism::fake([scanResponse()]);
    config(['queue.default' => 'database']);
    Queue::fake();
    $response = $this->postJson(cp_route('utilities.greenwashing-scan.start'))->assertAccepted()->assertJsonCount(1, 'jobs');
    $poll = $response->json('jobs.0.poll');
    $this->getJson($poll)->assertJsonPath('status', 'queued');
    Queue::assertPushed(ElSchneider\GreenwashingChecker\Jobs\ScanEntryJob::class, function ($job) {
        app()->call([$job, 'handle']);

        return $job->entryId === 'scan-1';
    });
    $this->getJson($poll)->assertJsonPath('status', 'completed')->assertJsonPath('result.counts.high', 1);
    $this->actingAs(User::make()->id('other')->email('other@example.test')->set('super', true));
    $this->getJson($poll)->assertNotFound();
    $fake->assertCallCount(1);
});

it('updates ignored and approved counts immediately and orders open risks before ignored-only entries', function () {
    $directory = sys_get_temp_dir().'/scan-approvals-'.Illuminate\Support\Str::uuid();
    $this->app->instance(ElSchneider\GreenwashingChecker\Storage\ApprovalStore::class, new ElSchneider\GreenwashingChecker\Storage\ApprovalStore($directory));
    try {
        $fake = Prism::fake([scanResponse(), scanResponse('umweltfreundliches')]);
        $this->artisan('greenwashing:scan')->assertExitCode(0);
        Entry::make()->id('scan-2')->collection('products')->blueprint('product')->locale('default')->slug('two')->data(['title' => 'Other', 'copy' => 'Ein umweltfreundliches Produkt.'])->save();
        $this->artisan('greenwashing:scan')->assertExitCode(0);
        $cache = app(UnitCache::class);
        $hash = $cache->hash('Ein nachhaltiges Produkt.', ElSchneider\GreenwashingChecker\Settings::analysis('products'));
        $id = $cache->get($hash)['findings'][0]['id'];
        $decision = ['reference' => 'entry::scan-1', 'collection' => 'products', 'site' => 'default', 'unit_hash' => $hash, 'finding_id' => $id];
        $this->postJson(cp_route('greenwashing-checker.dismiss'), $decision)->assertOk();
        $this->getJson(cp_route('utilities.greenwashing-scan.entries'))->assertJsonPath('rows.0.id', 'scan-2')
            ->assertJsonPath('rows.1.counts', ['open' => 0, 'high' => 0, 'ignored' => 1, 'approved' => 0]);
        $this->postJson(cp_route('greenwashing-checker.approve'), [...$decision, 'reason' => 'Documented evidence'])->assertOk();
        expect(app(EntryIndex::class)->find('scan-1')['counts'])->toBe(['open' => 0, 'high' => 0, 'ignored' => 0, 'approved' => 1]);
        $this->deleteJson(cp_route('greenwashing-checker.approve'), $decision)->assertOk();
        expect(app(EntryIndex::class)->find('scan-1')['counts']['ignored'])->toBe(1);
        $fake->assertCallCount(2);
    } finally {
        $this->app['files']->deleteDirectory($directory);
    }
});

it('sorts open HIGH first, then open count, with ignored-only entries last', function () {
    $index = app(EntryIndex::class);
    foreach ([['a', 2, 0, 0], ['b', 1, 1, 0], ['c', 1, 0, 0], ['d', 0, 0, 3], ['e', 0, 0, 0]] as [$id, $open, $high, $ignored]) {
        $entry = Entry::make()->id($id)->collection('products')->blueprint('product')->locale('default')->slug($id)->data(['title' => $id]);
        $entry->save();
        $findings = [];
        for ($i = 0; $i < $open + $ignored; $i++) {
            $findings[] = ['severity' => $i < $high ? 'high' : 'medium', 'dismissed' => $i >= $open, 'approval' => null];
        }
        if ($id === 'e') {
            $findings[] = ['severity' => 'high', 'dismissed' => false, 'approval' => ['reason' => 'Evidence']];
        }
        $index->record($entry, ['units' => ['title' => ['hash' => app(UnitCache::class)->hash($id), 'findings' => $findings]], 'analyzed_at' => now()->toIso8601String()]);
    }
    $this->getJson(cp_route('utilities.greenwashing-scan.entries'))->assertJsonPath('rows.*.id', ['b', 'a', 'c', 'e', 'd']);
});

it('keeps the last findings visible as stale when saved text cannot be read and hides clean entries', function () {
    Prism::fake([scanResponse(), StructuredResponseFake::make()->withStructured(['findings' => []])]);
    $this->artisan('greenwashing:scan')->assertExitCode(0);
    Entry::make()->id('clean')->collection('products')->blueprint('product')->locale('default')->slug('clean')->data(['title' => 'Clean'])->save();
    $this->artisan('greenwashing:scan')->assertExitCode(0);
    $this->entry->set('copy', 42)->save();
    $this->getJson(cp_route('utilities.greenwashing-scan.entries'))->assertOk()->assertJsonCount(1, 'rows')
        ->assertJsonPath('rows.0.stale', true)->assertJsonPath('rows.0.error', 'Field [copy] must contain a string, got int.');
    $this->getJson(cp_route('utilities.greenwashing-scan.preview'))->assertOk()->assertJsonPath('entries.0.id', 'scan-1')
        ->assertJsonStructure(['entries' => [['error']]]);
});

it('iterates filtered saved entries lazily across multiple chunks', function () {
    Collection::make('disabled')->save();
    Entry::make()->id('disabled')->collection('disabled')->locale('default')->slug('disabled')->data(['title' => 'Disabled'])->save();
    config(['statamic.greenwashing-checker.collections' => ['products']]);
    for ($i = 2; $i <= 121; $i++) {
        Entry::make()->id('scan-'.$i)->collection('products')->blueprint('product')->locale('default')->slug('entry-'.$i)->data(['title' => 'Product '.$i])->save();
    }
    // Keep the real query: filtering after hydration returns the same IDs but wastes memory.
    $hydratedChunks = [];
    $this->app->bind(Statamic\Contracts\Entries\QueryBuilder::class, function () use (&$hydratedChunks) {
        $query = Mockery::mock(Statamic\Stache\Query\EntryQueryBuilder::class, [Statamic\Facades\Stache::store('entries')])
            ->makePartial()->shouldAllowMockingProtectedMethods();
        $query->shouldReceive('getItems')->withArgs(function ($keys) use (&$hydratedChunks) {
            $hydratedChunks[] = $keys->count();
            expect($keys->count())->toBeLessThanOrEqual(100)
                ->and($keys->all())->not->toContain('disabled::disabled');

            return true;
        })->passthru();

        return $query;
    });
    $entries = app(ElSchneider\GreenwashingChecker\Scanning\EntryScanner::class)->entries(['products'], 'default');
    expect($hydratedChunks)->toBe([]);
    expect($entries)->toBeInstanceOf(Illuminate\Support\LazyCollection::class)
        ->and($entries->count())->toBe(121)
        ->and($entries->pluck('id')->all())->not->toContain('disabled');
    expect(app(ElSchneider\GreenwashingChecker\Scanning\EntryScanner::class)->entries(['disabled'])->count())->toBe(0)
        ->and(app(ElSchneider\GreenwashingChecker\Scanning\EntryScanner::class)->entries(['products'], 'other-site')->count())->toBe(0);
    expect($hydratedChunks)->toBe([100, 21, 100, 21, 0]);
});

it('paginates the risk overview without limiting preview or scan jobs', function () {
    $cache = app(UnitCache::class);
    $index = app(EntryIndex::class);
    for ($i = 1; $i <= 61; $i++) {
        $entry = Entry::make()->id('risk-'.$i)->collection('products')->blueprint('product')->locale('default')->slug('risk-'.$i)->data(['title' => 'Risk '.$i]);
        $entry->save();
        $index->record($entry, ['units' => ['title' => ['hash' => $cache->hash('Risk '.$i), 'findings' => array_fill(0, $i, ['severity' => 'high', 'dismissed' => false, 'approval' => null])]], 'analyzed_at' => now()->toIso8601String()]);
    }
    Entry::find('risk-1')->set('copy', 42)->save();
    Illuminate\Support\Facades\Log::spy();
    $url = cp_route('utilities.greenwashing-scan.entries');
    $this->getJson($url)->assertOk()->assertJsonCount(50, 'rows')->assertJsonPath('rows.0.id', 'risk-61')
        ->assertJsonPath('meta.total', 61)->assertJsonPath('meta.last_page', 2);
    Illuminate\Support\Facades\Log::shouldNotHaveReceived('warning');
    $this->getJson($url.'?page=2')->assertOk()->assertJsonCount(11, 'rows')->assertJsonPath('rows.0.id', 'risk-11')
        ->assertJsonPath('meta.current_page', 2)->assertJsonPath('rows.10.error', 'Field [copy] must contain a string, got int.');
    $this->getJson(cp_route('utilities.greenwashing-scan.preview'))->assertJsonPath('totals.entries', 62);
    Queue::fake();
    $this->postJson(cp_route('utilities.greenwashing-scan.start'))->assertAccepted()->assertJsonCount(62, 'jobs');
});

it('deduplicates uncached text across entries in utility and CLI cost previews', function () {
    Entry::make()->id('same')->collection('products')->blueprint('product')->locale('default')->slug('same')->data($this->entry->data()->all())->save();
    $fake = Prism::fake();
    $this->getJson(cp_route('utilities.greenwashing-scan.preview'))->assertOk()
        ->assertJsonPath('totals', ['entries' => 2, 'units' => 4, 'uncached' => 2, 'characters' => 28])
        ->assertJsonPath('entries.1.uncached', 0)->assertJsonPath('entries.1.characters', 0);
    $this->artisan('greenwashing:scan', ['--dry-run' => true])
        ->expectsOutputToContain('entries: 2 · units: 4 · uncached: 2 · characters: 28')->assertExitCode(0);
    $fake->assertCallCount(0);
});

it('refreshes cached entry summaries synchronously and reports them as skipped without queueing', function () {
    $fake = Prism::fake([scanResponse()]);
    $this->artisan('greenwashing:scan')->assertExitCode(0);
    Entry::make()->id('same')->collection('products')->blueprint('product')->locale('default')->slug('same')->data($this->entry->data()->all())->save();
    Entry::make()->id('new')->collection('products')->blueprint('product')->locale('default')->slug('new')->data(['title' => 'Uncached'])->save();
    Queue::fake();
    $this->postJson(cp_route('utilities.greenwashing-scan.start'))->assertAccepted()
        ->assertJsonCount(1, 'jobs')->assertJsonPath('jobs.0.entry', 'new')->assertJsonPath('skipped', 2);
    expect(app(EntryIndex::class)->find('same')['counts']['high'])->toBe(1);
    Queue::assertPushed(ElSchneider\GreenwashingChecker\Jobs\ScanEntryJob::class, 1);
    $fake->assertCallCount(1);
});

it('completes overlapping entry scans as skipped instead of failing or calling a provider', function () {
    $fake = Prism::fake();
    $jobs = app(ElSchneider\GreenwashingChecker\Ai\TrackedJobs::class);
    $id = $jobs->create('greenwashing-checker-scan', $this->user->id());
    $lock = Cache::lock('greenwashing-checker:scan:scan-1', 130);
    $lock->get();
    try {
        app()->call([new ElSchneider\GreenwashingChecker\Jobs\ScanEntryJob($id, 'scan-1', $this->user->id(), 'de'), 'handle']);
        expect($jobs->find($id)->status)->toBe(ElSchneider\GreenwashingChecker\Ai\JobStatus::Completed)
            ->and($jobs->find($id)->result['skipped'])->toBeTrue()
            ->and($jobs->find($id)->result['message'])->toBe('Bereits in Arbeit');
        $fake->assertCallCount(0);
    } finally {
        $lock->release();
    }
});

it('logs and skips corrupt entry summaries during decisions and overview reads', function (string $contents) {
    $directory = sys_get_temp_dir().'/corrupt-index-'.Illuminate\Support\Str::uuid();
    $index = new EntryIndex($directory);
    $this->app->instance(EntryIndex::class, $index);
    $this->app->instance(ElSchneider\GreenwashingChecker\Storage\ApprovalStore::class, new ElSchneider\GreenwashingChecker\Storage\ApprovalStore($directory.'/approvals'));
    Illuminate\Support\Facades\File::ensureDirectoryExists($directory);
    $path = $directory.'/corrupt.json';
    Illuminate\Support\Facades\File::put($path, $contents);
    Illuminate\Support\Facades\Log::spy();
    try {
        Prism::fake([scanResponse()]);
        $this->artisan('greenwashing:scan')->assertExitCode(0);
        $hash = app(UnitCache::class)->hash('Ein nachhaltiges Produkt.');
        $id = app(UnitCache::class)->get($hash)['findings'][0]['id'];
        $decision = ['reference' => 'entry::scan-1', 'collection' => 'products', 'site' => 'default', 'unit_hash' => $hash, 'finding_id' => $id];
        $this->postJson(cp_route('greenwashing-checker.dismiss'), $decision)->assertOk();
        expect($index->find('scan-1')['counts']['ignored'])->toBe(1);
        $this->postJson(cp_route('greenwashing-checker.approve'), [...$decision, 'reason' => 'Evidence checked'])->assertOk();
        expect($index->find('scan-1')['counts']['approved'])->toBe(1);
        Illuminate\Support\Facades\File::put($directory.'/'.hash('sha256', 'scan-1').'.json', $contents);
        $this->getJson(cp_route('utilities.greenwashing-scan.entries'))->assertOk()->assertJsonCount(0, 'rows');
        Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->atLeast()->once();
        expect(Illuminate\Support\Facades\File::get($path))->toBe($contents);
    } finally {
        Illuminate\Support\Facades\File::deleteDirectory($directory);
    }
})->with(['invalid JSON' => '{broken', 'scalar JSON' => '42', 'missing fields' => '{}']);

it('rescans after cache flush without provider calls and retains ignored findings and site names', function () {
    Statamic\Facades\Site::setSites(['default' => ['name' => 'Deutsch', 'locale' => 'de', 'url' => '/']]);
    $fake = Prism::fake([scanResponse()]);
    $this->artisan('greenwashing:scan')->assertExitCode(0);
    $cache = app(UnitCache::class);
    $hash = $cache->hash('Ein nachhaltiges Produkt.', ElSchneider\GreenwashingChecker\Settings::analysis('products'));
    $id = $cache->get($hash)['findings'][0]['id'];
    $this->postJson(cp_route('greenwashing-checker.dismiss'), [
        'reference' => 'entry::scan-1', 'collection' => 'products', 'site' => 'default', 'unit_hash' => $hash, 'finding_id' => $id,
    ])->assertOk();
    $this->artisan('cache:clear')->assertExitCode(0);
    $this->artisan('greenwashing:scan')->assertExitCode(0);
    $this->getJson(cp_route('utilities.greenwashing-scan.entries'))->assertOk()
        ->assertJsonPath('rows.0.counts.ignored', 1)->assertJsonPath('rows.0.counts.open', 0)
        ->assertJsonPath('rows.0.site_name', 'Deutsch');
    expect($cache->result(['copy' => $hash])['units']['copy']['findings'][0]['dismissed'])->toBeTrue();
    $fake->assertCallCount(1);
});

it('counts dry-run pruning and prunes automatically only on a full real scan', function () {
    $fake = Prism::fake([scanResponse()]);
    $cache = app(UnitCache::class);
    $obsolete = $cache->hash('Text no entry uses');
    $cache->put($obsolete, []);
    $this->artisan('greenwashing:scan', ['--dry-run' => true, '--prune' => true, '--collection' => ['products']])
        ->expectsOutputToContain('Would prune unit files: 1')->assertExitCode(0);
    expect($cache->get($obsolete))->not->toBeNull();
    $fake->assertCallCount(0);
    $this->artisan('greenwashing:scan', ['--collection' => ['products']])->assertExitCode(0);
    expect($cache->get($obsolete))->not->toBeNull();
    $this->artisan('greenwashing:scan', ['--dry-run' => true])
        ->expectsOutputToContain('Would prune unit files: 1')->assertExitCode(0);
    $this->artisan('greenwashing:scan')->expectsOutputToContain('Pruned unit files: 1')->assertExitCode(0);
    expect($cache->get($obsolete))->toBeNull();
    $this->artisan('greenwashing:scan')->expectsOutputToContain('Pruned unit files: 0')->assertExitCode(0);
    $fake->assertCallCount(1);
});

it('protects current hashes across unscanned collections and sites rather than stale summaries', function () {
    Statamic\Facades\Site::setSites([
        'default' => ['name' => 'Deutsch', 'locale' => 'de', 'url' => '/'],
        'french' => ['name' => 'French', 'locale' => 'fr', 'url' => '/fr'],
    ]);
    Collection::make('pages')->sites(['default', 'french'])->save();
    Blueprint::make('page')->setNamespace('collections.pages')->setContents([
        'tabs' => ['main' => ['sections' => [['fields' => [
            ['handle' => 'title', 'field' => ['type' => 'text']],
        ]]]]],
    ])->save();
    $page = Entry::make()->id('unscanned')->collection('pages')->blueprint('page')->locale('french')->slug('page')->data(['title' => 'French text']);
    $page->save();
    $cache = app(UnitCache::class);
    $protected = $cache->hash('French text', ElSchneider\GreenwashingChecker\Settings::analysis('pages'));
    $cache->put($protected, []);
    $obsolete = $cache->hash('Old page text');
    $cache->put($obsolete, []);
    app(EntryIndex::class)->record($page, ['units' => ['title' => ['hash' => $obsolete, 'findings' => []]], 'analyzed_at' => now()->toIso8601String()]);
    $fake = Prism::fake([scanResponse()]);
    $this->artisan('greenwashing:scan', ['--collection' => ['products'], '--site' => 'default', '--prune' => true])
        ->expectsOutputToContain('Pruned unit files: 1')->assertExitCode(0);
    expect($cache->get($protected))->not->toBeNull()->and($cache->get($obsolete))->toBeNull();
    $fake->assertCallCount(1);
});

it('does not delete any units when a current entry cannot be read during pruning', function () {
    $cache = app(UnitCache::class);
    $unused = $cache->hash('Retain until references can be established');
    $cache->put($unused, []);
    $this->entry->set('copy', 42)->save();
    $fake = Prism::fake();
    $this->artisan('greenwashing:scan', ['--dry-run' => true, '--prune' => true])
        ->expectsOutputToContain('Pruning failed:')->assertExitCode(1);
    expect($cache->get($unused))->not->toBeNull();
    expect(fn () => app(ElSchneider\GreenwashingChecker\Scanning\EntryScanner::class)->prune())->toThrow(UnexpectedValueException::class);
    expect($cache->get($unused))->not->toBeNull();
    $fake->assertCallCount(0);
});

it('shows corrupt unit filenames in the overview, preview and CLI while retaining the last summary', function () {
    $directory = sys_get_temp_dir().'/corrupt-scan-units-'.Illuminate\Support\Str::uuid();
    $cache = new UnitCache($directory);
    $this->app->instance(UnitCache::class, $cache);
    $fake = Prism::fake([scanResponse()]);
    try {
        $this->artisan('greenwashing:scan')->assertExitCode(0);
        $hash = $cache->hash('Ein nachhaltiges Produkt.', ElSchneider\GreenwashingChecker\Settings::analysis('products'));
        Illuminate\Support\Facades\File::put($directory.'/'.$hash.'.json', '{broken');
        $overview = $this->getJson(cp_route('utilities.greenwashing-scan.entries'))->assertOk()
            ->assertJsonPath('rows.0.stale', true)->assertJsonPath('rows.0.counts.high', 1);
        $preview = $this->getJson(cp_route('utilities.greenwashing-scan.preview'))->assertOk();
        expect($overview->json('rows.0.error'))->toContain($hash.'.json')->not->toContain($directory);
        expect($preview->json('entries.0.error'))->toContain($hash.'.json')->not->toContain($directory);
        $this->artisan('greenwashing:scan', ['--collection' => ['products']])
            ->expectsOutputToContain($hash.'.json')->assertExitCode(1);
        $fake->assertCallCount(1);
    } finally {
        Illuminate\Support\Facades\File::deleteDirectory($directory);
    }
});
