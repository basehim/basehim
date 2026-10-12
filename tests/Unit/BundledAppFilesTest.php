<?php

declare(strict_types=1);

/*
 * 1.2.51: the WP Migrator bundled in the repository was missing four classes
 * (UrlMapper, ContentFixer, MediaFilter, Downloader) that the shipped app
 * uses, so a fresh install failed as soon as an import reached the media or
 * content step. Every class a bundled app names in its own namespace must
 * have a file in its src/.
 */

/** @return list<string> fully qualified names in $ns that $code refers to */
function bundled_app_references(string $code, string $ns): array
{
    $tokens = token_get_all($code);
    $fileNs = '';
    $imports = [];
    $out = [];
    $count = count($tokens);
    $next = static function (int $i) use ($tokens, $count): ?array {
        for ($j = $i + 1; $j < $count; $j++) {
            if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) continue;
            return ['i' => $j, 't' => $tokens[$j]];
        }
        return null;
    };
    for ($i = 0; $i < $count; $i++) {
        $t = $tokens[$i];
        if (!is_array($t)) continue;
        if ($t[0] === T_NAMESPACE && ($n = $next($i)) && is_array($n['t'])) {
            $fileNs = ltrim($n['t'][1], '\\');
            $i = $n['i'];                       // the namespace's own name is not a reference
        } elseif ($t[0] === T_USE && ($n = $next($i)) && is_array($n['t']) && in_array($n['t'][0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            $name = ltrim($n['t'][1], '\\');
            $imports[substr((string) strrchr('\\' . $name, '\\'), 1)] = true;
            if (str_starts_with($name, $ns . '\\')) $out[] = $name;
            $i = $n['i'];
        } elseif (in_array($t[0], [T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED], true)) {
            $name = ltrim($t[1], '\\');
            if (str_starts_with($name, $ns . '\\')) $out[] = $name;
        } elseif ($t[0] === T_NEW && ($n = $next($i)) && is_array($n['t']) && $n['t'][0] === T_STRING) {
            $short = $n['t'][1];
            if (!in_array(strtolower($short), ['self', 'static', 'parent'], true) && !isset($imports[$short]) && $fileNs !== '' && str_starts_with($fileNs, $ns) && !class_exists($short)) {
                $out[] = $fileNs . '\\' . $short;
            }
        }
    }
    return array_values(array_unique($out));
}

test('bundled apps: every class an app names in its own namespace has a file', function () {
    $checked = 0;
    foreach (glob(BASEHIM_ROOT . '/content/apps/*/app.json') ?: [] as $manifestFile) {
        $dir = dirname($manifestFile);
        $manifest = json_decode((string) file_get_contents($manifestFile), true) ?: [];
        $ns = trim((string) ($manifest['namespace'] ?? ''), '\\');
        $src = $dir . '/' . trim((string) ($manifest['src'] ?? 'src'), '/');
        if ($ns === '' || !is_dir($src) || str_contains($dir, 'ajax-fixture')) continue;
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $f) {
            if ($f->getExtension() !== 'php') continue;
            foreach (bundled_app_references((string) file_get_contents($f->getPathname()), $ns) as $class) {
                $rel = str_replace('\\', '/', substr($class, strlen($ns) + 1));
                assert_true(is_file($src . '/' . $rel . '.php'),
                    "{$class} is used in " . substr($f->getPathname(), strlen(BASEHIM_ROOT) + 1) . ' but has no file');
                $checked++;
            }
        }
    }
    assert_true($checked > 0, 'nothing was checked');
});
