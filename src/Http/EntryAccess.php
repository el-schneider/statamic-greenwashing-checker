<?php

declare(strict_types=1);

namespace ElSchneider\GreenwashingChecker\Http;

use ElSchneider\GreenwashingChecker\Settings;
use Illuminate\Http\Request;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Facades\User;

final class EntryAccess
{
    public static function authorize(Request $request): array
    {
        $data = $request->validate([
            'reference' => ['nullable', 'string', 'regex:/^entry::[^:]+$/'],
            'collection' => ['required', 'string'], 'site' => ['required', 'string'],
        ]);
        $collection = Collection::findByHandle($data['collection']);
        abort_if($collection === null, 404);
        abort_unless(Settings::collectionEnabled($collection->handle()), 403);
        $site = Site::get($data['site']);
        abort_unless($site && $collection->sites()->contains($site->handle()), 422);
        $user = User::current();
        abort_unless($user && $user->can('access cp'), 403);
        $entry = null;
        if ($data['reference'] ?? null) {
            $entry = Entry::find(substr($data['reference'], 7));
            abort_if($entry === null, 404);
            abort_unless($user->can('edit', $entry), 403);
            abort_unless($entry->collectionHandle() === $collection->handle() && $entry->locale() === $site->handle(), 422);
        } else {
            abort_unless($user->can('create', [EntryContract::class, $collection, $site]), 403);
        }

        return [$collection, $site, $entry, $user];
    }
}
