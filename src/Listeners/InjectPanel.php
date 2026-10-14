<?php

declare(strict_types=1);

namespace ElSchneider\GreenwashingChecker\Listeners;

use ElSchneider\GreenwashingChecker\Settings;
use InvalidArgumentException;
use Statamic\Contracts\Entries\Collection;
use Statamic\Events\EntryBlueprintFound;

final class InjectPanel
{
    public function handle(EntryBlueprintFound $event): void
    {
        // Creation resolves a collection blueprint before an entry exists.
        $collection = $event->entry?->collection() ?? $event->blueprint->parent();
        if (! $collection instanceof Collection) {
            return;
        }
        if (! Settings::collectionEnabled($collection->handle())) {
            return;
        }
        $placement = Settings::get('placement');
        if ($placement === 'none') {
            return;
        }
        if (! in_array($placement, ['sidebar', 'tab'], true)) {
            throw new InvalidArgumentException('Invalid Greenwashing Checker placement.');
        }
        $event->blueprint->ensureField('greenwashing_checker', [
            'type' => 'greenwashing_checker', 'visibility' => 'computed',
            'display' => 'Greenwashing Checker', 'hide_display' => true, 'listable' => 'hidden',
        ], $placement === 'tab' ? 'greenwashing' : 'sidebar');
    }
}
