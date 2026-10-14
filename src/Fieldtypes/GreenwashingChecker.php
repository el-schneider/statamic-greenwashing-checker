<?php

declare(strict_types=1);

namespace ElSchneider\GreenwashingChecker\Fieldtypes;

use ElSchneider\GreenwashingChecker\Jurisdictions;
use Statamic\Facades\Site;
use Statamic\Fields\Fieldtype;

final class GreenwashingChecker extends Fieldtype
{
    protected $selectable = true;

    protected $categories = ['special'];

    public function process($data)
    {
        return null;
    }

    public function preload(): array
    {
        $parent = $this->field()->parent();

        $collection = $parent instanceof \Statamic\Contracts\Entries\Entry
            ? $parent->collection()
            : ($parent instanceof \Statamic\Contracts\Entries\Collection ? $parent : null);

        return [
            'collection' => $collection?->handle(),
            'revisions' => $collection?->revisionsEnabled() ?? false,
            'autosave' => $collection?->autosaveInterval() !== null,
            'normsBySite' => Site::all()->mapWithKeys(fn ($site) => [$site->handle() => Jurisdictions::references($site->handle())])->all(),
        ];
    }
}
