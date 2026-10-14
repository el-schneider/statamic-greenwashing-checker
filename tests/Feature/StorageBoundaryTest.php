<?php

declare(strict_types=1);

use ElSchneider\GreenwashingChecker\Storage\ApprovalStore;
use ElSchneider\GreenwashingChecker\Storage\UnitCache;
use Illuminate\Support\Str;

it('prunes unreferenced units and leaves foreign files in the store alone', function () {
    $directory = sys_get_temp_dir().'/gwc-prune-'.Str::uuid();
    mkdir($directory, recursive: true);
    $stale = str_repeat('a', 64);
    $kept = str_repeat('b', 64);
    file_put_contents("{$directory}/{$stale}.json", '{}');
    file_put_contents("{$directory}/{$kept}.json", '{}');
    file_put_contents("{$directory}/notes.json", '{}');

    try {
        $pruned = (new UnitCache($directory))->prune([$kept]);

        expect($pruned)->toBe(1)
            ->and(file_exists("{$directory}/{$stale}.json"))->toBeFalse()
            ->and(file_exists("{$directory}/{$kept}.json"))->toBeTrue()
            ->and(file_exists("{$directory}/notes.json"))->toBeTrue();
    } finally {
        $this->app['files']->deleteDirectory($directory);
    }
});

it('refuses approval hashes that would leave the approvals directory', function (string $hash) {
    $store = new ApprovalStore(sys_get_temp_dir().'/gwc-approvals-'.Str::uuid());

    expect(fn () => $store->all($hash))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $store->set($hash, str_repeat('c', 40), null))->toThrow(InvalidArgumentException::class);
})->with(['../escape', 'ABC', '']);
