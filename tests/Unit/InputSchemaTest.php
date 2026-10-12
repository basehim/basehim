<?php

declare(strict_types=1);

use App\Core\Ajax\AjaxException;
use App\Core\Ajax\InputSchema;

/** Validate and return the cleaned input, or the 422 field errors. */
function schema_check(array $schema, mixed $input, bool $fromQuery = false): array
{
    try {
        return ['ok' => InputSchema::compile($schema)->validate($input, $fromQuery)];
    } catch (AjaxException $e) {
        return ['errors' => $e->fields, 'status' => $e->status, 'code' => $e->errorCode];
    }
}

test('schema: valid input comes back with only declared fields that were sent', function () {
    $r = schema_check(['title' => 'required|string|max:10', 'note' => 'string'], ['title' => 'Hello']);
    assert_same(['ok' => ['title' => 'Hello']], $r);
});

test('schema: undeclared fields are refused (mass assignment)', function () {
    $r = schema_check(['title' => 'string'], ['title' => 'x', 'author_id' => 1, 'role' => 'admin']);
    assert_same(422, $r['status']);
    assert_same('VALIDATION_FAILED', $r['code']);
    assert_true(isset($r['errors']['author_id'], $r['errors']['role']), 'both extra fields reported');
});

test('schema: undeclared fields are refused inside nested objects too', function () {
    $r = schema_check(['seo' => ['type' => 'object', 'fields' => ['title' => 'string']]], ['seo' => ['title' => 'a', 'evil' => 1]]);
    assert_true(isset($r['errors']['seo.evil']));
});

test('schema: a crafted unknown key is not echoed back verbatim', function () {
    $r = schema_check(['a' => 'string'], ['<img src=x onerror=alert(1)>' => 1]);
    assert_true(isset($r['errors']['(invalid name)']));
    assert_not_contains('<img', json_encode($r));
});

test('schema: allow_unknown tolerates extra fields but never hands them to the handler', function () {
    $out = InputSchema::compile(['a' => 'string'], true)->validate(['a' => 'x', 'b' => 2]);
    assert_same(['a' => 'x'], $out);
});

test('schema: types are strict for JSON input', function () {
    $schema = ['i' => 'int', 'n' => 'number', 'b' => 'bool', 's' => 'string'];
    $r = schema_check($schema, ['i' => '5', 'n' => '1.5', 'b' => 'true', 's' => 5]);
    assert_same(['i', 'n', 'b', 's'], array_keys($r['errors']));
    assert_same(['ok' => ['i' => 5, 'n' => 1.5, 'b' => false, 's' => 'x']], schema_check($schema, ['i' => 5, 'n' => 1.5, 'b' => false, 's' => 'x']));
});

test('schema: 5.0 is not an int', function () {
    assert_true(isset(schema_check(['i' => 'int'], ['i' => 5.0])['errors']['i']));
});

test('schema: query-string input is coerced for int, number and bool only', function () {
    $r = schema_check(['i' => 'int', 'b' => 'bool', 'n' => 'number'], ['i' => '42', 'b' => 'true', 'n' => '2.5'], true);
    assert_same(['ok' => ['i' => 42, 'b' => true, 'n' => 2.5]], $r);
    assert_true(isset(schema_check(['i' => 'int'], ['i' => '4x'], true)['errors']['i']));
});

test('schema: required refuses missing, null and empty string', function () {
    foreach ([[], ['t' => null], ['t' => '']] as $input) {
        $r = schema_check(['t' => 'required|string'], $input);
        assert_true(isset($r['errors']['t']), 'refused: ' . json_encode($input));
    }
});

test('schema: nullable allows null on optional fields', function () {
    assert_same(['ok' => ['t' => null]], schema_check(['t' => 'string|nullable'], ['t' => null]));
    assert_true(isset(schema_check(['t' => 'string'], ['t' => null])['errors']['t']));
});

test('schema: min and max bound characters, not bytes', function () {
    assert_same(['ok' => ['t' => 'ééé']], schema_check(['t' => 'string|max:3'], ['t' => 'ééé']));
    assert_true(isset(schema_check(['t' => 'string|max:3'], ['t' => 'éééé'])['errors']['t']));
    assert_true(isset(schema_check(['t' => 'string|min:2'], ['t' => 'é'])['errors']['t']));
});

test('schema: numeric bounds and in-lists', function () {
    assert_true(isset(schema_check(['n' => 'int|min:1|max:10'], ['n' => 0])['errors']['n']));
    assert_true(isset(schema_check(['n' => 'int|min:1|max:10'], ['n' => 11])['errors']['n']));
    assert_same(['ok' => ['n' => 3]], schema_check(['n' => 'int|in:1,2,3'], ['n' => 3]));
    assert_true(isset(schema_check(['s' => 'string|in:draft,published'], ['s' => 'trash'])['errors']['s']));
});

test('schema: url accepts http(s) only — not javascript: or data:', function () {
    foreach (['javascript:alert(1)', 'data:text/html,x', 'ftp://example.com/', 'not a url'] as $bad) {
        assert_true(isset(schema_check(['u' => 'string|url'], ['u' => $bad])['errors']['u']), $bad);
    }
    assert_same(['ok' => ['u' => 'https://example.com/a?b=1']], schema_check(['u' => 'string|url'], ['u' => 'https://example.com/a?b=1']));
});

test('schema: email and regex', function () {
    assert_true(isset(schema_check(['e' => 'string|email'], ['e' => 'nope'])['errors']['e']));
    $slug = ['s' => ['type' => 'string', 'regex' => '/^[a-z0-9-]+$/']];
    assert_same(['ok' => ['s' => 'a-b-1']], schema_check($slug, ['s' => 'a-b-1']));
    assert_true(isset(schema_check($slug, ['s' => '../etc'])['errors']['s']));
});

test('schema: arrays check every item and their count', function () {
    $s = ['tags' => ['type' => 'array', 'max' => 2, 'items' => 'string|max:3']];
    assert_same(['ok' => ['tags' => ['a', 'b']]], schema_check($s, ['tags' => ['a', 'b']]));
    assert_true(isset(schema_check($s, ['tags' => ['a', 'b', 'c']])['errors']['tags']));
    assert_true(isset(schema_check($s, ['tags' => ['a', 'toolong']])['errors']['tags.1']));
    assert_true(isset(schema_check($s, ['tags' => ['x' => 'a']])['errors']['tags']), 'an object is not a list');
});

test('schema: the top level must be an object', function () {
    assert_same(422, schema_check(['a' => 'string'], ['x', 'y'])['status']);
    assert_same(422, schema_check(['a' => 'string'], 'str')['status']);
});

test('schema: an empty schema accepts no fields at all', function () {
    assert_same(['ok' => []], schema_check([], []));
    assert_true(isset(schema_check([], ['anything' => 1])['errors']['anything']));
});

test('schema compile: typos and malformed rules fail at registration, not silently', function () {
    $cases = [
        ['t' => 'requred|string'],             // typo
        ['t' => 'required'],                    // no type
        ['t' => 'string|int'],                  // two types
        ['t' => 'string|max:abc'],              // bad bound
        ['t' => 'string|min:5|max:2'],          // min > max
        ['t' => ['type' => 'array']],           // array without items
        ['t' => ['type' => 'string', 'regex' => '/[/']], // invalid pattern
        ['t' => ['type' => 'string', 'bogus' => 1]],     // unknown key
        ['bad-name' => 'string'],               // invalid field name
        ['t' => 'int|email'],                   // email on an int
    ];
    foreach ($cases as $schema) {
        assert_throws(\InvalidArgumentException::class, fn() => InputSchema::compile($schema));
    }
});

test('schema compile: nesting deeper than 8 levels is refused', function () {
    $spec = 'string';
    for ($i = 0; $i < 9; $i++) $spec = ['type' => 'object', 'fields' => ['x' => $spec]];
    assert_throws(\InvalidArgumentException::class, fn() => InputSchema::compile(['root' => $spec]), 'nested too deeply');
});

test('schema compile: an in-list on a number field must hold numbers (no 500 at request time)', function () {
    foreach (['number|in:low,high', 'int|in:1,two', 'int|in:1.5,2'] as $rule) {
        $threw = false;
        try { InputSchema::compile(['n' => $rule]); } catch (\InvalidArgumentException) { $threw = true; }
        assert_true($threw, "refused at registration: {$rule}");
    }
    assert_same(['ok' => ['n' => 1.5]], schema_check(['n' => 'number|in:0.5,1.5'], ['n' => 1.5]));
    assert_same(422, schema_check(['n' => 'number|in:0.5,1.5'], ['n' => 2])['status']);
    assert_same(['ok' => ['n' => 2]], schema_check(['n' => 'int|in:1,2'], ['n' => '2'], true));
});

test('schema: strings must be valid UTF-8 (a query string can carry any bytes)', function () {
    $r = schema_check(['q' => 'string'], ['q' => "\xff\xfe"], true);
    assert_same(422, $r['status']);
    assert_true(isset($r['errors']['q']));
    assert_true(json_encode($r) !== false, 'the error itself can be sent as JSON');
    assert_same(['ok' => ['q' => 'été']], schema_check(['q' => 'string'], ['q' => 'été'], true));
});
