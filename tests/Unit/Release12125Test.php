<?php

declare(strict_types=1);

/*
 * 1.2.125: core loads on PHP 8.1 again. WidgetRegistry declared
 * `fn(...): never => throw ...`; an arrow function returns its expression, and
 * PHP 8.1 refuses `never` on anything that returns (8.2 allows it), so every
 * request that loaded the file died with a fatal error.
 */

test('php 8.1: no arrow function in core declares a never return type', function () {
    $root = dirname(__DIR__, 2);
    $found = [];
    $checked = 0;
    foreach (['app', 'admin', 'routes', 'config'] as $dir) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->getExtension() !== 'php') continue;
            $checked++;
            $tokens = token_get_all((string) file_get_contents($f->getPathname()));
            $n = count($tokens);
            for ($i = 0; $i < $n; $i++) {
                if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FN) continue;
                // Walk to the arrow (skipping the parameter list), then look
                // back for ": never" between the parameters and "=>".
                $depth = 0;
                $sawNever = false;
                for ($j = $i + 1; $j < $n; $j++) {
                    $t = $tokens[$j];
                    if ($t === '(') $depth++;
                    elseif ($t === ')') $depth--;
                    elseif (is_array($t) && $t[0] === T_DOUBLE_ARROW && $depth === 0) break;
                    elseif ($depth === 0 && is_array($t) && $t[0] === T_STRING && strtolower($t[1]) === 'never') $sawNever = true;
                }
                if ($sawNever) $found[] = substr($f->getPathname(), strlen($root) + 1) . ':' . $tokens[$i][2];
            }
        }
    }
    assert_true($checked > 100, 'scanned core');
    assert_same([], $found, 'arrow functions typed never (fatal on PHP 8.1)');
});

test('ci: the test client sends paths as written, and the html filter test checks safety, not one parser', function () {
    $root = dirname(__DIR__, 2);
    assert_contains('CURLOPT_PATH_AS_IS     => true,', (string) file_get_contents($root . '/tests/support/harness.php'));
    $t = (string) file_get_contents($root . '/tests/Unit/HtmlSanitizerTest.php');
    assert_contains("'no event handler survives: '", $t);
});
