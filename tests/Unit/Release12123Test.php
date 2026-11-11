<?php

declare(strict_types=1);

/*
 * 1.2.123 — Categories and Tags on the Data Table; the New dialog.
 */

function r12123(string $rel): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $rel);
}

test('terms: one table per taxonomy, paged in SQL with whitelisted sorts', function () {
    $t = r12123('routes/tables.php');
    assert_contains("foreach (\$app->make(\\App\\Services\\TaxonomyRegistry::class)->all() as \$__slug => \$__tx)", $t);
    assert_contains("'provider'     => \\App\\Services\\Table\\TermTableProvider::class,", $t);
    assert_contains("'quick_edit'   => \\App\\Services\\Table\\TermQuickEdit::class,", $t);
    assert_contains("'capability'   => 'manage_taxonomies',", $t);
    assert_contains("'filter' => ['field' => 'usage', 'in' => ['used', 'unused']", $t);
    assert_contains("\$url = \$base . '/admin/posts?tag=' . (int) \$t['id'];", $t);
    $r = r12123('app/Repositories/TaxonomyRepository.php');
    assert_contains('public const TERM_SORTS = [', $r);
    assert_contains("\$order = self::TERM_SORTS[(string) (\$filters['sort'] ?? '')] ?? self::TERM_SORTS['name_az'];", $r);
    assert_contains("LIMIT {\$perPage} OFFSET {\$offset}", $r);
    assert_same('terms.event_type', \App\Services\Table\TermTableProvider::keyFor('event-type'));
});

test('terms: the screen no longer loads every term', function () {
    $c = r12123('app/Http/Controllers/Admin/TaxonomyController.php');
    assert_false(str_contains($c, '$tax->termsByTaxonomySlug($taxonomy)'), 'the index does not fetch all terms');
    assert_contains("->paginateTerms((int) \$taxData['id'], ['sort' => 'name_az'], 1, 1000)", $c);
    $v = r12123('admin/views/taxonomies/index.php');
    assert_contains("\$this->include('partials.datatable', [", $v);
    assert_false(str_contains($v, 'toggleTermEdit'), 'no per-row hidden editor');
    assert_contains('data-term-tab="many"', $v);
    assert_contains('/admin/assets/js/term-new.js', $v);
});

test('terms: quick edit keeps the slug on a rename and refuses cycles', function () {
    $q = r12123('app/Services/Table/TermQuickEdit.php');
    assert_contains("if (isset(\$data['name']) && !isset(\$data['slug'])) \$data['slug'] = (string) \$term['slug'];", $q);
    assert_contains('in_array($parent, $this->descendants(', $q);
    assert_contains("'type' => 'textarea'", $q);
    assert_contains("} else if (fd.type === 'textarea') {", r12123('admin/assets/js/datatable.js'));
    assert_contains("root.addEventListener('bh:dt-quickedit'", r12123('admin/assets/js/datatable.js'));
});

test('terms: adding several splits, de-duplicates and skips existing names', function () {
    assert_same(['web design', 'Travel', 'food'], \App\Http\Controllers\Ajax\TermManageActions::splitNames("web design\n Travel ,travel,,\r\n\nfood, WEB   design"));
    $a = r12123('routes/ajax.php');
    assert_contains("\$ajax->register('terms.create', [", $a);
    assert_contains("\$ajax->register('terms.create_many', [", $a);
    $js = r12123('admin/assets/js/term-new.js');
    assert_contains("ajax.request('terms.create_many', manyData(true), { idempotencyKey: true })", $js);
    assert_false((bool) preg_match('/innerHTML/', $js), 'text only');
    assert_contains('.bh-termnew .bh-dialog__form[hidden] { display: none; }', r12123('admin/assets/css/src/tailwind.css'));
});
