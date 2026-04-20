<?php

use App\Ai\Tools\Chat\ManageNotes;
use App\Models\Note;
use Laravel\Ai\Tools\Request;

test('saves a note', function () {
    $tool = new ManageNotes;
    $result = (string) $tool->handle(new Request([
        'action' => 'save',
        'key' => 'test_key',
        'value' => 'test value',
    ]));

    expect($result)->toContain("Saved note 'test_key'");
    $this->assertDatabaseHas('notes', ['key' => 'test_key', 'value' => 'test value']);
});

test('searches notes', function () {
    Note::factory()->create(['key' => 'color', 'value' => 'blue']);
    Note::factory()->create(['key' => 'size', 'value' => 'large']);

    $tool = new ManageNotes;
    $result = (string) $tool->handle(new Request([
        'action' => 'search',
        'query' => 'color',
    ]));

    expect($result)->toContain('color')->toContain('blue');
});

test('deletes a note', function () {
    Note::factory()->create(['key' => 'to_delete', 'value' => 'temp']);

    $tool = new ManageNotes;
    $result = (string) $tool->handle(new Request([
        'action' => 'delete',
        'key' => 'to_delete',
    ]));

    expect($result)->toContain("Deleted note 'to_delete'");
    $this->assertDatabaseMissing('notes', ['key' => 'to_delete']);
});

test('returns error for invalid action', function () {
    $tool = new ManageNotes;
    $result = (string) $tool->handle(new Request(['action' => 'invalid']));

    expect($result)->toContain('Invalid action');
});

test('returns error when saving without key', function () {
    $tool = new ManageNotes;
    $result = (string) $tool->handle(new Request([
        'action' => 'save',
        'key' => '',
        'value' => 'test',
    ]));

    expect($result)->toContain('key is required');
});

test('returns error when saving without value', function () {
    $tool = new ManageNotes;
    $result = (string) $tool->handle(new Request([
        'action' => 'save',
        'key' => 'test',
        'value' => '',
    ]));

    expect($result)->toContain('value is required');
});

test('search treats LIKE wildcards in the query as literals', function () {
    Note::factory()->create(['key' => 'literal_percent', 'value' => '50% off']);
    Note::factory()->create(['key' => 'unrelated', 'value' => 'abc']);

    $tool = new ManageNotes;

    $matchingPercent = (string) $tool->handle(new Request([
        'action' => 'search',
        'query' => '50%',
    ]));

    expect($matchingPercent)->toContain('literal_percent')->not->toContain('unrelated');

    $matchingUnderscore = (string) $tool->handle(new Request([
        'action' => 'search',
        'query' => 'literal_percent',
    ]));

    expect($matchingUnderscore)->toContain('literal_percent');

    $noMatch = (string) $tool->handle(new Request([
        'action' => 'search',
        'query' => '_percent',
    ]));

    // The underscore should be literal, so '_percent' must not match 'literal_percent'
    // starting with 'l' — only an exact '_percent' substring would match.
    expect($noMatch)->not->toContain('unrelated');
});

test('lists all notes when searching with empty query', function () {
    Note::factory()->create(['key' => 'note1', 'value' => 'first']);
    Note::factory()->create(['key' => 'note2', 'value' => 'second']);

    $tool = new ManageNotes;
    $result = (string) $tool->handle(new Request([
        'action' => 'search',
        'query' => '',
    ]));

    expect($result)->toContain('note1')->toContain('note2');
});
