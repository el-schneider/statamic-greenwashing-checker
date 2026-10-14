<?php

declare(strict_types=1);

use ElSchneider\GreenwashingChecker\Analysis\Category;
use ElSchneider\GreenwashingChecker\Analysis\Finding;
use ElSchneider\GreenwashingChecker\Analysis\Severity;
use ElSchneider\GreenwashingChecker\Storage\ApprovalStore;
use ElSchneider\GreenwashingChecker\Storage\UnitCache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Role;
use Statamic\Facades\User;

beforeEach(function () {
    $this->directory = sys_get_temp_dir().'/gwc-approvals-'.Str::uuid();
    $this->app->bind(ApprovalStore::class, fn () => new ApprovalStore($this->directory));
    Collection::make('products')->save();
    Blueprint::make('product')->setNamespace('collections.products')->setContents(['tabs' => ['main' => ['sections' => [['fields' => [['handle' => 'teaser', 'field' => ['type' => 'textarea']]]]]]]])->save();
    Entry::make()->id('approval-entry')->collection('products')->blueprint('product')->locale('default')->slug('approval-demo')->save();
    $this->user = User::make()->id('approver')->email('approver@example.test')->set('name', 'Editorial approver')->set('super', true);
    $this->actingAs($this->user);
    $this->hash = app(UnitCache::class)->hash('nachhaltig');
    $this->id = sha1($this->hash.'|nachhaltig|generic_claim');
    app(UnitCache::class)->put($this->hash, [new Finding($this->id, 'teaser', 'nachhaltig', true, Category::GenericClaim, Severity::High, 'Risk', [])]);
    $this->payload = ['reference' => 'entry::approval-entry', 'collection' => 'products', 'site' => 'default', 'unit_hash' => $this->hash, 'finding_id' => $this->id, 'reason' => 'Geprüfter Nachweis <b>DE/020/999</b>'];
});

afterEach(function () {
    File::deleteDirectory($this->directory);
});

it('persists documented approval across elapsed time and rebinds it to identical text paths', function () {
    $this->freezeTime();
    $approval = ['reason' => $this->payload['reason'], 'user' => 'approver', 'user_name' => 'Editorial approver', 'at' => now()->toIso8601String()];
    $this->postJson(cp_route('greenwashing-checker.approve'), $this->payload)->assertOk()->assertJsonPath('approval', $approval);
    expect(json_decode(File::get($this->directory.'/'.$this->hash.'.json'), true, flags: JSON_THROW_ON_ERROR))->toHaveKey($this->id);
    $cache = app(UnitCache::class);
    $cache->setDismissed($this->hash, $this->id, 'approver', true);
    $this->travel(31)->days();
    $cache->put($this->hash, [new Finding($this->id, 'elsewhere', 'nachhaltig', true, Category::GenericClaim, Severity::High, 'New analysis', [])]);
    expect($cache->result(['title' => $this->hash])['units']['title']['findings'][0]['approval'])->toBe($approval);
    $this->deleteJson(cp_route('greenwashing-checker.approve'), $this->payload)->assertOk()->assertJsonPath('approval', null);
    expect($cache->result(['title' => $this->hash])['units']['title']['findings'][0]['approval'])->toBeNull();
});

it('requires a nonblank bounded reason and a current known finding', function () {
    foreach (['', '   ', str_repeat('a', 2001)] as $reason) {
        $this->postJson(cp_route('greenwashing-checker.approve'), [...$this->payload, 'reason' => $reason])->assertUnprocessable();
    }
    $this->postJson(cp_route('greenwashing-checker.approve'), [...$this->payload, 'finding_id' => str_repeat('a', 40)])->assertNotFound();
    expect(File::exists($this->directory))->toBeFalse();
});

it('requires both entry edit access and approval permission for approve and revoke', function (array $permissions, bool $allowed) {
    Role::make('reviewer')->permissions(['access cp', ...$permissions])->save();
    $this->actingAs(User::make()->id('reviewer')->email('reviewer@example.test')->assignRole('reviewer'));
    $this->postJson(cp_route('greenwashing-checker.approve'), $this->payload)->assertStatus($allowed ? 200 : 403);
    $this->deleteJson(cp_route('greenwashing-checker.approve'), $this->payload)->assertStatus($allowed ? 200 : 403);
    if (! $allowed) {
        expect(File::exists($this->directory))->toBeFalse();
    }
    expect(Statamic\Statamic::jsonVariables(request())['greenwashingChecker']['canApprove'])->toBe(in_array('approve greenwashing findings', $permissions, true));
})->with([
    [['edit products entries'], false],
    [['approve greenwashing findings'], false],
    [['edit products entries', 'approve greenwashing findings'], true],
]);

it('rejects decisions for disabled or mismatched collections and requires create access for new entries', function () {
    config(['statamic.greenwashing-checker.collections' => []]);
    $this->postJson(cp_route('greenwashing-checker.approve'), $this->payload)->assertForbidden();
    $this->postJson(cp_route('greenwashing-checker.dismiss'), $this->payload)->assertForbidden();
    config(['statamic.greenwashing-checker.collections' => '*']);
    $this->postJson(cp_route('greenwashing-checker.approve'), [...$this->payload, 'site' => 'missing'])->assertUnprocessable();
    Role::make('creator')->permissions(['access cp', 'approve greenwashing findings'])->save();
    $this->actingAs(User::make()->id('creator')->email('creator@example.test')->assignRole('creator'));
    $new = [...$this->payload, 'reference' => null];
    $this->postJson(cp_route('greenwashing-checker.approve'), $new)->assertForbidden();
    $this->postJson(cp_route('greenwashing-checker.dismiss'), $new)->assertForbidden();
    Role::find('creator')->permissions(['access cp', 'approve greenwashing findings', 'create products entries'])->save();
    $this->postJson(cp_route('greenwashing-checker.approve'), $new)->assertOk();
});

it('does not let an editor revoke an existing approval without decision permission', function () {
    $this->postJson(cp_route('greenwashing-checker.approve'), $this->payload)->assertOk();
    $before = File::get($this->directory.'/'.$this->hash.'.json');
    Role::make('editor-only')->permissions(['access cp', 'edit products entries'])->save();
    $this->actingAs(User::make()->id('editor-only')->email('editor-only@example.test')->assignRole('editor-only'));
    $this->deleteJson(cp_route('greenwashing-checker.approve'), $this->payload)->assertForbidden();
    expect(File::get($this->directory.'/'.$this->hash.'.json'))->toBe($before);
});

it('preserves unrelated flat-file decisions and invalidates approval on text changes', function () {
    $store = app(ApprovalStore::class);
    $store->set($this->hash, $this->id, ['reason' => 'Reviewed', 'user' => 'approver', 'user_name' => 'Reviewer', 'at' => now()->toIso8601String()]);
    $other = sha1('other-finding');
    $store->set($this->hash, $other, ['reason' => 'Other decision']);
    $store->set($this->hash, $other, null);
    expect((new ApprovalStore($this->directory))->all($this->hash))->toHaveKey($this->id)->not->toHaveKey($other);
    $cache = app(UnitCache::class);
    $changed = $cache->hash('nachhaltiger');
    $cache->put($changed, [new Finding($this->id, 'teaser', 'nachhaltiger', true, Category::GenericClaim, Severity::High, 'Risk', [])]);
    expect($cache->result(['teaser' => $changed])['units']['teaser']['findings'][0]['approval'])->toBeNull();
    expect(Statamic\Facades\Permission::get('approve greenwashing findings'))->not->toBeNull();
});

it('fails loudly on a corrupted approval file rather than losing editorial decisions', function (string $contents) {
    File::ensureDirectoryExists($this->directory);
    File::put($this->directory.'/'.$this->hash.'.json', $contents);
    app(ApprovalStore::class)->all($this->hash);
})->with(['{broken', 'null', '42'])->throws(ElSchneider\GreenwashingChecker\Storage\ApprovalFileException::class);

it('revokes a permanent approval after its finding record is pruned and marks the summary stale', function () {
    $this->postJson(cp_route('greenwashing-checker.approve'), $this->payload)->assertOk();
    $entry = Entry::find('approval-entry');
    $entry->set('teaser', 'nachhaltig')->save();
    $cache = app(UnitCache::class);
    app(ElSchneider\GreenwashingChecker\Storage\EntryIndex::class)->record($entry, $cache->result(['teaser' => $this->hash]));
    app(UnitCache::class)->prune([]);
    $this->deleteJson(cp_route('greenwashing-checker.approve'), $this->payload)->assertOk()->assertJsonPath('approval', null);
    expect(app(ApprovalStore::class)->all($this->hash))->toBe([]);
    $this->getJson(cp_route('utilities.greenwashing-scan.entries'))->assertOk()->assertJsonPath('rows.0.stale', true)
        ->assertJsonPath('rows.0.counts.approved', 1);
    $this->deleteJson(cp_route('greenwashing-checker.approve'), $this->payload)->assertNotFound();
    $cache->put($this->hash, [new Finding($this->id, 'teaser', 'nachhaltig', true, Category::GenericClaim, Severity::High, 'Risk', [])]);
    $this->postJson(cp_route('greenwashing-checker.analyze'), [
        'reference' => 'entry::approval-entry', 'collection' => 'products', 'site' => 'default', 'blueprint' => 'product', 'values' => ['teaser' => 'nachhaltig'],
    ])->assertOk();
    $this->getJson(cp_route('utilities.greenwashing-scan.entries'))->assertJsonPath('rows.0.stale', false)->assertJsonPath('rows.0.counts.open', 1);
});

it('returns and logs a readable corrupt approval filename on cached CP checks', function () {
    File::ensureDirectoryExists($this->directory);
    File::put($this->directory.'/'.$this->hash.'.json', '{broken');
    Illuminate\Support\Facades\Log::spy();
    $response = $this->postJson(cp_route('greenwashing-checker.analyze'), [
        'reference' => 'entry::approval-entry', 'collection' => 'products', 'site' => 'default', 'blueprint' => 'product', 'values' => ['teaser' => 'nachhaltig'],
    ])->assertStatus(500)->assertJsonPath('code', 'approval_file');
    expect($response->json('message'))->toContain($this->hash.'.json')->not->toContain($this->directory);
    Illuminate\Support\Facades\Log::shouldHaveReceived('error')->once()->with('greenwashing-checker: approval file could not be read', Mockery::on(
        fn ($context) => str_contains($context['exception']->getMessage(), $this->hash.'.json')
    ));
    expect(File::get($this->directory.'/'.$this->hash.'.json'))->toBe('{broken');
});

it('reports corrupt approval files through the queued panel polling contract', function () {
    File::ensureDirectoryExists($this->directory);
    File::put($this->directory.'/'.$this->hash.'.json', '{broken');
    app(UnitCache::class)->prune([]);
    Illuminate\Support\Facades\Log::spy();
    Prism\Prism\Facades\Prism::fake([Prism\Prism\Testing\StructuredResponseFake::make()->withStructured(['findings' => [[
        'unit_id' => 'teaser', 'quote' => 'nachhaltig', 'category' => 'generic_claim', 'severity' => 'medium', 'explanation' => 'Risk', 'suggestions' => [],
    ]]])]);
    $queued = $this->postJson(cp_route('greenwashing-checker.analyze'), [
        'reference' => 'entry::approval-entry', 'collection' => 'products', 'site' => 'default', 'blueprint' => 'product', 'values' => ['teaser' => 'nachhaltig'],
    ])->assertAccepted();
    $failed = $this->getJson($queued->json('poll'))->assertJsonPath('status', 'failed')->assertJsonPath('error.code', 'approval_file');
    expect($failed->json('error.message'))->toContain($this->hash.'.json')->not->toContain($this->directory);
    Illuminate\Support\Facades\Log::shouldHaveReceived('error')->once()->with('greenwashing-checker: analysis failed', Mockery::on(fn ($context) => $context['code'] === 'approval_file'));
});
