<?php

declare(strict_types=1);

use ElSchneider\GreenwashingChecker\Content\ContentWalker;
use Statamic\Facades\Blueprint;
use Statamic\Fields\Field;
use Statamic\Fields\Fields;

it('walks a blueprint with exact publish paths and separate Bard prose and set units', function (bool $groupedSets) {
    $definition = fn (string $handle, string $type, array $extra = []) => [
        'handle' => $handle, 'field' => array_merge(['type' => $type, 'display' => ucfirst($handle)], $extra),
    ];
    $set = ['fields' => [$definition('text', 'text'), $definition('deep', 'group', [
        'fields' => [$definition('body', 'markdown')],
    ])]];
    $sets = $groupedSets ? ['main' => ['display' => 'Main', 'sets' => ['copy' => $set]]] : ['copy' => $set];
    $fields = [
        $definition('title', 'text'), $definition('description', 'textarea'), $definition('markdown', 'markdown'),
        $definition('body', 'bard', ['sets' => $sets]), $definition('json_body', 'bard', ['sets' => $sets]),
        $definition('blocks', 'replicator', ['sets' => $sets]),
        $definition('rows', 'grid', ['fields' => [$definition('body', 'textarea')]]),
        $definition('details', 'group', ['fields' => [$definition('text', 'text')]]),
        $definition('excluded', 'text', ['check_me' => false]), $definition('empty', 'text'),
        $definition('number', 'integer'),
    ];
    $blueprint = Blueprint::make()->setContents(['tabs' => ['main' => ['sections' => [['fields' => $fields]]]]]);
    $nodes = [
        ['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'Hello ', 'marks' => [['type' => 'bold']]],
            ['type' => 'text', 'text' => 'world'], ['type' => 'hardBreak'], ['type' => 'text', 'text' => 'next line'],
        ]],
        ['type' => 'set', 'attrs' => ['values' => ['type' => 'copy', 'text' => 'Set copy']]],
        ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'List item']]],
        ]]]],
        ['type' => 'paragraph', 'content' => []],
    ];
    $units = (new ContentWalker)->walk($blueprint->fields(), [
        'title' => 'Title', 'description' => 'Description', 'markdown' => '# Heading',
        'body' => $nodes, 'json_body' => json_encode($nodes, JSON_THROW_ON_ERROR),
        'blocks' => [['type' => 'copy', 'text' => 'Block', 'deep' => ['body' => 'Nested']]],
        'rows' => [['body' => 'First row'], ['body' => 'Second row']], 'details' => ['text' => 'Group'],
        'excluded' => 'Skip me', 'empty' => '', 'number' => 123,
    ], fn (Field $field) => ($field->config()['check_me'] ?? true) !== false);

    expect(array_map(fn ($unit) => [$unit->path, $unit->type, $unit->text, $unit->display], $units))->toBe([
        ['title', 'text', 'Title', 'Title'],
        ['description', 'textarea', 'Description', 'Description'],
        ['markdown', 'markdown', '# Heading', 'Markdown'],
        ['body', 'bard', "Hello world\nnext line\n\nList item", 'Body'],
        ['body.1.attrs.values.text', 'text', 'Set copy', 'Text'],
        ['json_body', 'bard', "Hello world\nnext line\n\nList item", 'Json_body'],
        ['json_body.1.attrs.values.text', 'text', 'Set copy', 'Text'],
        ['blocks.0.text', 'text', 'Block', 'Text'],
        ['blocks.0.deep.body', 'markdown', 'Nested', 'Body'],
        ['rows.0.body', 'textarea', 'First row', 'Body'],
        ['rows.1.body', 'textarea', 'Second row', 'Body'],
        ['details.text', 'text', 'Group', 'Text'],
    ]);
})->with([false, true]);

it('filters nested fields and can exclude a whole container', function () {
    $fields = new Fields([['handle' => 'group', 'field' => ['type' => 'group', 'fields' => [
        ['handle' => 'text', 'field' => ['type' => 'text', 'check_me' => false]],
    ]]]]);
    $walker = new ContentWalker;
    $values = ['group' => ['text' => 'Skip']];
    expect($walker->walk($fields, $values, fn (Field $field) => ($field->config()['check_me'] ?? true) !== false))->toBe([])
        ->and($walker->walk($fields, $values, fn (Field $field) => $field->type() !== 'group'))->toBe([]);
});

it('skips absent, null, empty strings and empty Bard or containers', function () {
    $fields = new Fields([
        ['handle' => 'missing', 'field' => ['type' => 'text']],
        ['handle' => 'null', 'field' => ['type' => 'textarea']],
        ['handle' => 'empty', 'field' => ['type' => 'markdown']],
        ['handle' => 'body', 'field' => ['type' => 'bard']],
        ['handle' => 'rows', 'field' => ['type' => 'grid', 'fields' => []]],
    ]);
    expect((new ContentWalker)->walk($fields, ['null' => null, 'empty' => '', 'body' => [], 'rows' => []]))->toBe([]);
});

it('rejects non-string text values instead of coercing them', function (mixed $value) {
    (new ContentWalker)->walk(new Fields([['handle' => 'text', 'field' => ['type' => 'text']]]), ['text' => $value]);
})->with([123, true, [[]], [['text' => 'Not a string']]])->throws(UnexpectedValueException::class);

it('rejects invalid Bard JSON with its field path and preserves the parsing exception', function () {
    try {
        (new ContentWalker)->walk(new Fields([['handle' => 'body', 'field' => ['type' => 'bard']]]), ['body' => '{invalid']);
        test()->fail('Invalid Bard JSON must fail.');
    } catch (UnexpectedValueException $exception) {
        expect($exception->getMessage())->toBe('Bard field [body] must contain valid JSON.');
        expect($exception->getPrevious())->toBeInstanceOf(JsonException::class);
    }
});

it('rejects malformed Bard text', function () {
    (new ContentWalker)->walk(new Fields([['handle' => 'body', 'field' => ['type' => 'bard']]]), [
        'body' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 123]]]],
    ]);
})->throws(UnexpectedValueException::class);

it('rejects malformed Bard set values with the affected publish path', function (mixed $values) {
    (new ContentWalker)->walk(new Fields([['handle' => 'body', 'field' => ['type' => 'bard']]]), [
        'body' => [['type' => 'set', 'attrs' => ['values' => $values]]],
    ]);
})->with([null, false, [[]], [['quote' => 'Missing type']], [['type' => 123]]])
    ->throws(UnexpectedValueException::class, 'Bard set [body.0] must contain values with a set type.');

it('skips replicator sets the editor toggled off but keeps later indexes', function () {
    $fields = new Fields([['handle' => 'blocks', 'field' => ['type' => 'replicator', 'sets' => [
        'copy' => ['fields' => [['handle' => 'text', 'field' => ['type' => 'text']]]],
    ]]]]);
    $units = (new ContentWalker)->walk($fields, ['blocks' => [
        ['type' => 'copy', 'enabled' => false, 'text' => 'Hidden'],
        ['type' => 'copy', 'enabled' => true, 'text' => 'Shown'],
    ]]);
    expect(array_map(fn ($unit) => [$unit->path, $unit->text], $units))->toBe([['blocks.1.text', 'Shown']]);
});

it('walks Bard sets using top-level node indexes through nested replicators and filters disabled sets', function () {
    $fields = new Fields([['handle' => 'builder', 'field' => ['type' => 'bard', 'sets' => [
        'content' => ['sets' => ['quote' => ['fields' => [
            ['handle' => 'quote', 'field' => ['type' => 'textarea']],
            ['handle' => 'private', 'field' => ['type' => 'text', 'check_me' => false]],
            ['handle' => 'cards', 'field' => ['type' => 'replicator', 'sets' => ['card' => ['fields' => [
                ['handle' => 'copy', 'field' => ['type' => 'markdown']],
            ]]]]],
        ]]]],
    ]]]]);
    $nodes = [
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Prose before']]],
        ['type' => 'set', 'attrs' => ['values' => ['type' => 'quote', 'enabled' => false, 'quote' => 'Hidden']]],
        ['type' => 'set', 'attrs' => ['values' => [
            'type' => 'quote', 'quote' => 'Visible quote', 'private' => 'Excluded',
            'cards' => [['type' => 'card', 'copy' => 'Nested copy']],
        ]]],
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Prose after']]],
    ];
    foreach ([$nodes, json_encode($nodes, JSON_THROW_ON_ERROR)] as $value) {
        $units = (new ContentWalker)->walk($fields, ['builder' => $value], fn (Field $field) => ($field->config()['check_me'] ?? true) !== false);
        expect(array_map(fn ($unit) => [$unit->path, $unit->type, $unit->text], $units))->toBe([
            ['builder', 'bard', "Prose before\n\nProse after"],
            ['builder.2.attrs.values.quote', 'textarea', 'Visible quote'],
            ['builder.2.attrs.values.cards.0.copy', 'markdown', 'Nested copy'],
        ]);
    }
});

it('rejects replicator rows without a string type with the exact publish path', function (array $row) {
    $fields = new Fields([['handle' => 'blocks', 'field' => ['type' => 'replicator']]]);
    (new ContentWalker)->walk($fields, ['blocks' => [$row]]);
})->with([[['copy' => 'No type']], [['type' => null]], [['type' => 42]], [['type' => []]]])
    ->throws(UnexpectedValueException::class, 'Replicator set [blocks.0] must contain values with a set type.');

it('skips orphan Bard and replicator sets with one diagnostic per walk without hiding known text', function () {
    Illuminate\Support\Facades\Log::spy();
    $sets = ['copy' => ['fields' => [['handle' => 'text', 'field' => ['type' => 'text']]]], 'empty' => ['fields' => []]];
    $fields = new Fields([
        ['handle' => 'blocks', 'field' => ['type' => 'replicator', 'sets' => $sets]],
        ['handle' => 'body', 'field' => ['type' => 'bard', 'sets' => $sets]],
    ]);
    $walker = new ContentWalker;
    $units = $walker->walk($fields, [
        'blocks' => [['type' => 'removed', 'text' => 'Invisible'], ['type' => 'copy', 'text' => 'Visible'], ['type' => 'empty']],
        'body' => [
            ['type' => 'set', 'attrs' => ['values' => ['type' => 'removed', 'text' => 'Invisible']]],
            ['type' => 'set', 'attrs' => ['values' => ['type' => 'copy', 'text' => 'Visible Bard']]],
        ],
    ]);
    expect(array_map(fn ($unit) => [$unit->path, $unit->text], $units))->toBe([
        ['blocks.1.text', 'Visible'], ['body.1.attrs.values.text', 'Visible Bard'],
    ]);
    Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->once()->with(
        'greenwashing-checker: orphan sets skipped', ['sets' => [
            ['path' => 'blocks.0', 'type' => 'removed'], ['path' => 'body.0', 'type' => 'removed'],
        ]]
    );
    $walker->walk($fields, ['blocks' => [['type' => 'empty'], ['type' => 'removed', 'enabled' => false]]]);
    Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->once();
});
