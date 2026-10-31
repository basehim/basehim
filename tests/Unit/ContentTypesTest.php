<?php

declare(strict_types=1);

/*
 * 1.2.94 — content types, taxonomies and custom fields without a database:
 * URL prefixes, supports, taxonomy attachment and field registration.
 */

use App\Core\Config;
use App\Core\HookRegistry;
use App\Services\Fields\FieldRegistry;
use App\Services\PostTypeRegistry;
use App\Services\TaxonomyRegistry;

function ct_config(): Config
{
    return new Config(BASEHIM_ROOT . '/config');
}

function ct_types(): PostTypeRegistry
{
    return new PostTypeRegistry(ct_config(), new HookRegistry());
}

test('content types: URL prefixes — default plural, custom, reserved and taken', function () {
    $r = ct_types();
    assert_true($r->register('event', ['label' => 'Events']));
    assert_same('events', $r->get('event')['rewrite']);
    $r->register('news', ['label' => 'News']);
    assert_same('news', $r->get('news')['rewrite'], 'a slug ending in s is not doubled');
    $r->register('talk', ['rewrite' => '/Talks/']);
    assert_same('talks', $r->get('talk')['rewrite']);
    $r->register('thing', ['rewrite' => 'admin']);
    assert_same('', $r->get('thing')['rewrite'], 'a core path is refused');
    $r->register('other', ['rewrite' => 'events']);
    assert_same('', $r->get('other')['rewrite'], 'a prefix another type uses is refused');
    assert_same(2, count($r->rejected()));
    $r->register('hidden', ['public' => false]);
    assert_same('', $r->get('hidden')['rewrite'], 'no URLs for a non-public type');
    $r->register('own', ['rewrite' => false]);
    assert_same('', $r->get('own')['rewrite']);
    assert_true($r->hasUrls('event') && $r->hasUrls('post') && $r->hasUrls('page'));
    assert_false($r->hasUrls('hidden') || $r->hasUrls('own') || $r->hasUrls('missing'));
    assert_same('event', $r->byPrefix('events')['slug']);
    assert_same(null, $r->byPrefix('nothing'));
    assert_false($r->register('post'), 'core types stay core');
});

test('content types: supports — default keeps every panel; an explicit list is honoured; built-ins come from config', function () {
    $r = ct_types();
    $r->register('a');
    $r->register('b', ['supports' => ['title', 'content', 'excerpt']]);
    foreach (['excerpt', 'thumbnail', 'comments'] as $f) assert_true($r->supports('a', $f), "a supports {$f}");
    assert_true($r->supports('b', 'excerpt'));
    assert_false($r->supports('b', 'thumbnail'));
    assert_true($r->supports('post', 'comments'), 'built-ins read config/cms.php content_types');
    assert_true(isset($r->all()['post'], $r->all()['page']), 'all() lists the built-ins (it read a missing config key before 1.2.94)');
});

test('content types: taxonomies — attach core ones, register app ones, refuse clashes', function () {
    $types = ct_types();
    $types->register('event', ['taxonomies' => ['category']]);
    $tax = new TaxonomyRegistry(ct_config(), $types);
    assert_true($tax->appliesTo('category', 'event'), "a type's own list attaches category");
    assert_true($tax->appliesTo('category', 'post'));
    assert_false($tax->appliesTo('tag', 'event'));
    assert_true($tax->register('event-type', ['label' => 'Event types', 'post_types' => ['event'], 'hierarchical' => true, 'app' => 'x']));
    assert_same(['category', 'event-type'], array_column($tax->forType('event'), 'slug'));
    assert_same('event-type', $tax->get('event-type')['rewrite']);
    assert_same('/event-type/workshops', $tax->termUrl('event-type', 'workshops'));
    assert_same('/category/news', $tax->termUrl('category', 'news'));
    assert_false($tax->register('category', ['app' => 'x']), "core's are not redefined");
    assert_false($tax->register('event-type', ['app' => 'y']), 'another app may not take it');
    assert_false($tax->register('Bad Key', ['app' => 'x']));
    $tax->register('venues', ['rewrite' => 'events', 'app' => 'x']);
    assert_same('', $tax->get('venues')['rewrite'], "a type's prefix is not reused");
    assert_same(2, $tax->forgetOwner('x'), 'event-type and venues');
    assert_same(null, $tax->get('event-type'), 'forgotten with its app');
});

test('content types: field groups — keys, ownership, types, replacement and owner removal', function () {
    $f = new FieldRegistry();
    assert_true($f->register('a', 'event', [['key' => 'venue', 'type' => 'text'], ['key' => 'seats', 'type' => 'number', 'min' => 1]], ['title' => 'Details']));
    assert_false($f->register('b', 'event', [['key' => 'venue', 'type' => 'text']]), 'another app may not declare a key it does not own');
    assert_false($f->register('a', 'event', [['key' => '_private', 'type' => 'text']]), 'underscore keys are core');
    assert_false($f->register('a', 'event', [['key' => 'x', 'type' => 'nonsense']]));
    assert_false($f->register('a', 'event', [['key' => 'x'], ['key' => 'x']]));
    assert_false($f->register('a', 'template', [['key' => 'x']]));
    assert_true($f->register('a', 'event', [['key' => 'venue', 'type' => 'textarea']], ['title' => 'Details']), 'same group replaced');
    assert_same(['venue'], array_column($f->fields('event'), 'key'));
    assert_true($f->register('b', 'event', [['key' => 'price', 'type' => 'number']], ['title' => 'Tickets']));
    assert_same(['venue', 'price'], array_column($f->fields('event'), 'key'));
    assert_same(1, $f->forgetOwner('b'));
    assert_same(['venue'], array_column($f->fields('event'), 'key'));
    assert_true($f->register('a', 'post', [['key' => 'subtitle', 'type' => 'text']]), 'core types take fields too');
});

test('content types: the app base class offers registerTaxonomy and registerFields', function () {
    $m = new ReflectionClass(\App\Core\App::class);
    assert_true($m->getMethod('registerTaxonomy')->isProtected());
    assert_true($m->getMethod('registerFields')->isProtected());
});
