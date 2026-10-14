<?php

declare(strict_types=1);

use ElSchneider\GreenwashingChecker\Analysis\Category;
use ElSchneider\GreenwashingChecker\Analysis\Finding;
use ElSchneider\GreenwashingChecker\Analysis\Severity;
use ElSchneider\GreenwashingChecker\Storage\ApprovalStore;
use ElSchneider\GreenwashingChecker\Storage\UnitCache;
use ElSchneider\GreenwashingChecker\Storage\UnitFileException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    $this->directory = sys_get_temp_dir().'/greenwashing-units-'.Illuminate\Support\Str::uuid();
    $this->store = new UnitCache($this->directory.'/units');
    $this->hash = $this->store->hash('nachhaltig');
    $this->finding = new Finding(sha1('finding'), 'copy', 'nachhaltig', true, Category::GenericClaim, Severity::High, 'Risk', ['specific']);
});

afterEach(function () {
    File::deleteDirectory($this->directory);
});

it('retains results and ignores after cache flush and reopening the unit store', function () {
    $this->store->put($this->hash, [$this->finding]);
    expect($this->store->setDismissed($this->hash, $this->finding->id, 'reviewer', true))->toBeTrue();
    $record = $this->store->get($this->hash);
    Cache::flush();
    $this->travel(365)->days();
    $reopened = new UnitCache($this->directory.'/units');
    expect($reopened->get($this->hash))->toBe($record)
        ->and($reopened->result(['other.copy' => $this->hash])['units']['other.copy']['findings'][0])
        ->toMatchArray(['path' => 'other.copy', 'dismissed' => true, 'quote' => 'nachhaltig']);
    $reopened->put($this->hash, [$this->finding]);
    expect($reopened->get($this->hash)['dismissals'])->toBe($record['dismissals']);
    expect($reopened->setDismissed($this->hash, $this->finding->id, 'reviewer', false))->toBeTrue();
    expect($reopened->get($this->hash)['dismissals'])->toBe([]);
});

it('reports corrupt unit files by basename, logs them and leaves them for repair', function (string $contents) {
    File::ensureDirectoryExists($this->directory.'/units');
    $path = $this->directory.'/units/'.$this->hash.'.json';
    File::put($path, $contents);
    Log::spy();
    try {
        $this->store->get($this->hash);
        $this->fail('Corrupt unit file was accepted.');
    } catch (UnitFileException $exception) {
        expect($exception->getMessage())->toContain($this->hash.'.json')->not->toContain($this->directory);
    }
    Log::shouldHaveReceived('error')->once()->with('greenwashing-checker: unit file could not be read', Mockery::on(fn ($context) => $context['file'] === $this->hash.'.json'));
    expect(File::get($path))->toBe($contents);
})->with(['{broken', 'null', '42', '{}', '{"findings":[{}],"dismissals":[]}', '{"findings":[],"dismissals":{"id":false}}']);

it('rejects hashes that could escape the unit directory', function (string $hash) {
    expect(fn () => $this->store->get($hash))->toThrow(InvalidArgumentException::class);
    expect(fn () => $this->store->put($hash, []))->toThrow(InvalidArgumentException::class);
    expect(File::exists($this->directory))->toBeFalse();
})->with(['../outside', str_repeat('a', 63), str_repeat('A', 64), str_repeat('a', 64)."\n"]);

it('prunes only unreferenced unit files and never touches approvals', function () {
    $unused = $this->store->hash('obsolete text');
    $this->store->put($this->hash, [$this->finding]);
    $this->store->put($unused, []);
    $approvals = new ApprovalStore($this->directory.'/approvals');
    $approvals->set($unused, $this->finding->id, ['reason' => 'Evidence']);
    expect($this->store->prune([$this->hash], true))->toBe(1)
        ->and($this->store->get($unused))->not->toBeNull()
        ->and($this->store->prune([$this->hash]))->toBe(1)
        ->and($this->store->get($unused))->toBeNull()
        ->and($this->store->get($this->hash)['findings'][0]['quote'])->toBe('nachhaltig')
        ->and($approvals->all($unused))->toBe([$this->finding->id => ['reason' => 'Evidence']])
        ->and($this->store->prune([$this->hash]))->toBe(0);
});

it('fails pruning when a unit file cannot be deleted rather than reporting success', function () {
    $this->store->put($this->hash, []);
    $files = Mockery::mock(File::getFacadeRoot())->makePartial();
    $files->shouldReceive('delete')->with($this->directory.'/units/'.$this->hash.'.json')->once()->andReturn(false);
    File::swap($files);
    expect(fn () => $this->store->prune([]))->toThrow(RuntimeException::class, 'Cannot prune analysis file '.$this->hash.'.json.');
    expect($this->store->get($this->hash))->not->toBeNull();
});
