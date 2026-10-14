<?php

declare(strict_types=1);

use ElSchneider\GreenwashingChecker\Content\ContentWalker;
use ElSchneider\GreenwashingChecker\Fieldtypes\GreenwashingChecker;
use ElSchneider\GreenwashingChecker\Listeners\InjectPanel;
use Statamic\Events\EntryBlueprintFound;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Fields\Field;
use Statamic\Statamic;

it('injects a computed selectable sidebar panel into enabled entry and creation blueprints only', function (string $locale) {
    app()->setLocale($locale);
    $collection = Collection::make('products');
    $collection->save();
    $entry = Entry::make()->collection($collection);
    foreach ([null, $entry] as $parentEntry) {
        $blueprint = Blueprint::make('product')->setContents(['tabs' => []])->setParent($parentEntry ?? $collection);
        (new InjectPanel)->handle(new EntryBlueprintFound($blueprint, $parentEntry));
        $field = $blueprint->fields()->get('greenwashing_checker');
        expect($field->type())->toBe('greenwashing_checker');
        expect($field->config()['visibility'])->toBe('computed');
        expect($blueprint->contents()['tabs']['sidebar']['sections'][0]['fields'][0]['handle'])->toBe('greenwashing_checker');
        $field->setParent($parentEntry ?? $collection);
        $meta = $field->fieldtype()->preload();
        expect($meta['collection'])->toBe('products');
        $annex = $locale === 'de' ? 'UGP-Richtlinie Anhang I Nr.' : 'UCPD Annex I point';
        $uwg = $locale === 'de' ? 'UWG Anhang Nr.' : 'UWG Annex no.';
        $articles = $locale === 'de' ? 'UGP-Richtlinie Art. 6/7' : 'UCPD Art. 6/7';
        expect($meta['normsBySite']['default'])->toBe([
            'generic_claim' => "$annex 4a · DE: $uwg 4a",
            'offset_neutrality' => "$annex 4c · DE: $uwg 4c",
            'scope_overreach' => "$annex 4b · DE: $uwg 4b",
            'unofficial_label' => "$annex 2a · DE: $uwg 2a",
            'legal_requirement_as_feature' => "$annex 10a · DE: $uwg 10a",
            'future_performance' => "$articles · DE: UWG § 5 Abs. 3 Nr. 4",
            'irrelevant_benefit' => "$articles · DE: UWG § 5 Abs. 3 Nr. 3",
            'misleading_environmental' => "$articles · DE: UWG § 5 Abs. 1, 2 Nr. 1",
            'durability_repair' => "$annex 23d–23i · DE: $uwg 23d a)–g)",
        ]);
    }
    config(['statamic.greenwashing-checker.collections' => ['news']]);
    $blueprint = Blueprint::make('disabled')->setContents(['tabs' => []])->setParent($collection);
    (new InjectPanel)->handle(new EntryBlueprintFound($blueprint));
    expect($blueprint->hasField('greenwashing_checker'))->toBeFalse();
    $generic = Blueprint::make('unrelated')->setContents(['tabs' => []]);
    (new InjectPanel)->handle(new EntryBlueprintFound($generic));
    expect($generic->hasField('greenwashing_checker'))->toBeFalse();
    expect((new GreenwashingChecker)->selectable())->toBeTrue();
})->with(['de', 'en']);

it('excludes opted-out nested text and containers and preserves full Bard field paths', function () {
    $blueprint = Blueprint::make()->setContents(['tabs' => ['main' => ['sections' => [['fields' => [
        ['handle' => 'private', 'field' => ['type' => 'text', 'greenwashing_checker' => false]],
        ['handle' => 'blocks', 'field' => ['type' => 'replicator', 'sets' => ['copy' => ['fields' => [
            ['handle' => 'body', 'field' => ['type' => 'bard', 'sets' => ['note' => ['fields' => [
                ['handle' => 'text', 'field' => ['type' => 'text', 'greenwashing_checker' => false]],
            ]]]]],
            ['handle' => 'secret', 'field' => ['type' => 'textarea', 'greenwashing_checker' => false]],
        ]]]]],
        ['handle' => 'ignored_group', 'field' => ['type' => 'group', 'greenwashing_checker' => false, 'fields' => [['handle' => 'text', 'field' => ['type' => 'text']]]]],
    ]]]]]]);
    $values = ['private' => 'nachhaltig', 'ignored_group' => ['text' => 'grün'], 'blocks' => [['type' => 'copy', 'secret' => 'öko', 'body' => [
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hallo'], ['type' => 'hardBreak'], ['type' => 'text', 'text' => 'Welt']]],
        ['type' => 'set', 'attrs' => ['values' => ['type' => 'note', 'text' => 'Nicht prüfen']]],
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Weiter']]],
    ]]]];
    $units = (new ContentWalker)->walk($blueprint->fields(), $values, fn (Field $field) => ($field->config()['greenwashing_checker'] ?? true) !== false);
    expect($units)->toHaveCount(1);
    expect($units[0]->path)->toBe('blocks.0.body');
    expect($units[0]->text)->toBe("Hallo\nWelt\n\nWeiter");
});

it('provides configuration state without credentials in the script payload', function () {
    $data = Statamic::jsonVariables(request());
    expect($data['greenwashingChecker'])->toBe(['configured' => true, 'canApprove' => false, 'onPublish' => 'warn', 'checkOnBlur' => true]);
    config(['statamic.greenwashing-checker.model' => 'bad', 'statamic.greenwashing-checker.check_on_blur' => false]);
    $data = Statamic::jsonVariables(request());
    expect($data['greenwashingChecker'])->toBe(['configured' => false, 'canApprove' => false, 'onPublish' => 'warn', 'checkOnBlur' => false]);
});

it('labels single and multiple findings in German and English', function (string $locale, string $singular, string $plural) {
    expect(trans_choice('greenwashing-checker::messages.statuses.findings', 1, [], $locale))->toBe($singular);
    expect(trans_choice('greenwashing-checker::messages.statuses.findings', 2, [], $locale))->toBe($plural);
    expect(trans_choice('greenwashing-checker::messages.field_indicator', 1, [], $locale))->toBe('Greenwashing · '.$singular);
    expect(trans_choice('greenwashing-checker::messages.field_indicator', 2, [], $locale))->toBe('Greenwashing · '.$plural);
})->with([
    ['de', '1 Hinweis', '2 Hinweise'],
    ['en', '1 finding', '2 findings'],
]);

it('injects only the configured placement and makes manual fields selectable', function (string $placement, ?string $tab) {
    config(['statamic.greenwashing-checker.placement' => $placement]);
    $collection = Collection::make('products');
    $blueprint = Blueprint::make('product')->setContents(['tabs' => []])->setParent($collection);
    (new InjectPanel)->handle(new EntryBlueprintFound($blueprint));
    expect($blueprint->hasField('greenwashing_checker'))->toBe($tab !== null);
    if ($tab !== null) {
        expect($blueprint->contents()['tabs'][$tab]['sections'][0]['fields'][0]['handle'])->toBe('greenwashing_checker');
    }
    expect((new GreenwashingChecker)->selectable())->toBeTrue();
})->with([['sidebar', 'sidebar'], ['tab', 'greenwashing'], ['none', null]]);

it('exposes collection autosave and revisions flags without entry data', function () {
    $collection = Collection::make('products')->autosaveInterval(15)->revisionsEnabled(true);
    $field = new Field('check', ['type' => 'greenwashing_checker']);
    $field->setParent($collection);
    config(['statamic.autosave.enabled' => true, 'statamic.revisions.enabled' => true]);
    Statamic::pro(true);
    $meta = $field->fieldtype()->preload();
    expect($meta['autosave'])->toBeTrue()->and($meta['revisions'])->toBeTrue();
    $collection->autosaveInterval(false)->revisionsEnabled(false);
    $meta = $field->fieldtype()->preload();
    expect($meta['autosave'])->toBeFalse()->and($meta['revisions'])->toBeFalse();
});
