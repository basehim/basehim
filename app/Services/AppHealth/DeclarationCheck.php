<?php

declare(strict_types=1);

namespace App\Services\AppHealth;

/**
 * DeclarationCheck — finds, without running any of it, the app code that PHP
 * would refuse to load (1.2.71).
 *
 * Some mistakes are not exceptions but engine fatals that stop the whole
 * request before any try/catch can run: a method that narrows the visibility
 * of one in App\Core\App, a signature its parent does not allow, a class name
 * core already uses, a parent that does not exist. This reads every PHP file
 * of an app with the tokenizer (PhpToken::tokenize with TOKEN_PARSE, which
 * also reports syntax errors as a ParseError) and compares each class with
 * its parent — core classes through reflection, the app's own through their
 * tokens.
 *
 * It flags only what PHP itself would reject. Where a rule needs types it
 * cannot resolve from the tokens (class types, intersections), it stays
 * silent and leaves the case to the isolated load test (Preflight).
 *
 * Nothing here includes, evaluates or autoloads app code. Core classes are
 * autoloaded for reflection; core is trusted code.
 */
final class DeclarationCheck
{
    /** Directories whose files are third-party or tooling, not loaded by the app at runtime as a rule. */
    public const SIDE_DIRS = ['vendor', 'node_modules', 'tests', 'test', '.git'];

    private const BUILTIN = [
        'int', 'float', 'string', 'bool', 'array', 'callable', 'iterable', 'object',
        'mixed', 'void', 'null', 'never', 'false', 'true',
    ];

    private const VIS_RANK = ['private' => 1, 'protected' => 2, 'public' => 3];

    /** @var array<string, array> FQN (lower-case) => class info */
    private array $classes = [];

    /** @var array<int, array{code:string,message:string,file:string,line:int,severity:string}> */
    private array $problems = [];

    /** @var array<string, array{file:string,line:int}> top-level functions, FQN lower-case */
    private array $functions = [];

    private string $root;

    public function __construct(private string $appDir)
    {
        $this->appDir = rtrim(str_replace('\\', '/', $appDir), '/');
        $this->root = defined('BASEHIM_ROOT') ? rtrim(str_replace('\\', '/', (string) BASEHIM_ROOT), '/') : '';
    }

    /**
     * @param string[] $files absolute paths of the app's PHP files
     * @return array{problems: array, classes: array<string, array{name:string,file:string,kind:string}>}
     */
    public function run(array $files): array
    {
        foreach ($files as $file) {
            $this->parseFile($file);
        }
        foreach ($this->classes as $info) {
            $this->checkClass($info);
        }
        $this->checkFunctions();

        $declared = [];
        foreach ($this->classes as $lc => $c) {
            $declared[$lc] = ['name' => $c['name'], 'file' => $c['file'], 'kind' => $c['kind']];
        }
        return ['problems' => $this->problems, 'classes' => $declared];
    }

    // ------------------------------------------------------------------
    // Parsing
    // ------------------------------------------------------------------

    private function parseFile(string $file): void
    {
        $code = @file_get_contents($file);
        if ($code === false) {
            $this->add('unreadable', 'The file could not be read.', $file, 0, 'warning');
            return;
        }
        try {
            $tokens = \PhpToken::tokenize($code, TOKEN_PARSE);
        } catch (\ParseError $e) {
            $this->add('syntax', 'Syntax error: ' . $e->getMessage(), $file, $e->getLine(),
                $this->isSideFile($file) ? 'warning' : 'error');
            return;
        } catch (\Throwable $e) {
            $this->add('syntax', 'The file could not be parsed: ' . $e->getMessage(), $file, 0, 'warning');
            return;
        }

        // Drop whitespace and comments: the walk below only needs code.
        $t = [];
        foreach ($tokens as $tok) {
            if ($tok->isIgnorable()) continue;
            $t[] = $tok;
        }

        $ns = '';
        $uses = [];
        $depth = 0;
        $classStack = []; // [depthOfBody, &info]
        $n = count($t);

        for ($i = 0; $i < $n; $i++) {
            $tok = $t[$i];

            if ($tok->is(T_NAMESPACE) && $depth === 0 && !$this->prevIs($t, $i, T_NS_SEPARATOR)) {
                $name = '';
                $j = $i + 1;
                while ($j < $n && ($t[$j]->is([T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR]))) {
                    $name .= $t[$j]->text;
                    $j++;
                }
                // "namespace\Foo" is a relative name, not a declaration.
                if ($j < $n && $t[$j]->text === '(') continue;
                $ns = trim($name, '\\');
                $uses = [];
                $i = $j - 1;
                continue;
            }

            if ($tok->is(T_USE) && $depth === 0) {
                $i = $this->parseUse($t, $i, $uses);
                continue;
            }

            if ($tok->text === '{' || $tok->is(T_CURLY_OPEN) || $tok->is(T_DOLLAR_OPEN_CURLY_BRACES)) {
                $depth++;
                continue;
            }
            if ($tok->text === '}') {
                $depth--;
                if ($classStack && end($classStack)['depth'] === $depth + 1) {
                    array_pop($classStack);
                }
                continue;
            }

            if ($tok->is([T_CLASS, T_INTERFACE, T_TRAIT]) || ($tok->is(T_ENUM) && isset($t[$i + 1]) && $t[$i + 1]->is(T_STRING))) {
                // ::class, and anonymous "new class".
                if ($this->prevIs($t, $i, T_DOUBLE_COLON)) continue;
                if ($tok->is(T_CLASS) && $this->prevIs($t, $i, T_NEW)) continue;
                if ($tok->is(T_CLASS) && $this->prevIs($t, $i, T_READONLY) && $this->prevIs($t, $i - 1, T_NEW)) continue;
                if (!isset($t[$i + 1]) || !$t[$i + 1]->is(T_STRING)) continue;

                $kind = strtolower($tok->text);
                $short = $t[$i + 1]->text;
                $fqn = ($ns !== '' ? $ns . '\\' : '') . $short;
                $mods = $this->classModifiers($t, $i);

                $info = [
                    'name' => $fqn, 'kind' => $kind, 'file' => $file, 'line' => $tok->line,
                    'abstract' => in_array('abstract', $mods, true), 'final' => in_array('final', $mods, true),
                    'extends' => [], 'implements' => [], 'methods' => [], 'uses_traits' => false,
                    'nested' => $depth > 0,
                ];

                // extends / implements up to the opening brace.
                $j = $i + 2;
                $mode = null;
                while ($j < $n && $t[$j]->text !== '{') {
                    if ($t[$j]->is(T_EXTENDS)) { $mode = 'extends'; }
                    elseif ($t[$j]->is(T_IMPLEMENTS)) { $mode = 'implements'; }
                    elseif ($mode && $t[$j]->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE])) {
                        $info[$mode][] = $this->resolve($t[$j]->text, $ns, $uses);
                    }
                    $j++;
                }

                $lc = strtolower($fqn);
                if (isset($this->classes[$lc]) && $this->classes[$lc]['file'] !== $file) {
                    $this->add('duplicate-class',
                        ucfirst($kind) . " {$fqn} is declared twice in this app (also in " . $this->rel($this->classes[$lc]['file']) . '). PHP stops with "Cannot declare ' . $kind . '" when both are loaded.',
                        $file, $tok->line, $this->isSideFile($file) ? 'warning' : 'error');
                } elseif (!isset($this->classes[$lc])) {
                    $this->classes[$lc] = $info;
                }
                // Body starts at the brace: the class body's depth is depth+1.
                $classStack[] = ['depth' => $depth + 1, 'lc' => $lc];
                $depth++;
                $i = $j;
                continue;
            }

            // Members: only directly inside a class body.
            $inBody = $classStack && end($classStack)['depth'] === $depth;
            if ($inBody && $tok->is(T_USE)) {
                $lcCls = end($classStack)['lc'];
                if (isset($this->classes[$lcCls])) $this->classes[$lcCls]['uses_traits'] = true;
                continue;
            }
            if ($tok->is(T_FUNCTION)) {
                if ($inBody) {
                    $lcCls = end($classStack)['lc'];
                    [$method, $end] = $this->parseMethod($t, $i, $ns, $uses);
                    if ($method !== null && isset($this->classes[$lcCls]) && $this->classes[$lcCls]['file'] === $file) {
                        $this->classes[$lcCls]['methods'][strtolower($method['name'])] = $method;
                    }
                    // Abstract/interface methods end with ';'; bodies are walked normally.
                    $i = $end;
                    continue;
                }
                if ($depth === 0 && isset($t[$i + 1]) && $t[$i + 1]->is(T_STRING) && !$this->prevIs($t, $i, T_USE)) {
                    $fname = ($ns !== '' ? $ns . '\\' : '') . $t[$i + 1]->text;
                    $lcf = strtolower($fname);
                    if (isset($this->functions[$lcf]) && $this->functions[$lcf]['file'] !== $file) {
                        $this->add('duplicate-function',
                            "Function {$fname}() is declared twice in this app (also in " . $this->rel($this->functions[$lcf]['file']) . ').',
                            $file, $tok->line, $this->isSideFile($file) ? 'warning' : 'error');
                    }
                    $this->functions[$lcf] = ['name' => $fname, 'file' => $file, 'line' => $tok->line];
                }
            }
        }
    }

    /** @return int index of the last token consumed */
    private function parseUse(array $t, int $i, array &$uses): int
    {
        $n = count($t);
        $j = $i + 1;
        // "use function"/"use const" import non-classes.
        if ($j < $n && ($t[$j]->is(T_FUNCTION) || $t[$j]->is(T_CONST))) {
            while ($j < $n && $t[$j]->text !== ';') $j++;
            return $j;
        }
        $prefix = '';
        $name = '';
        $alias = null;
        $group = false;
        for (; $j < $n; $j++) {
            $x = $t[$j];
            if ($x->text === ';') break;
            if ($x->text === '{') { $group = true; $prefix = trim($name, '\\') . '\\'; $name = ''; continue; }
            if ($x->text === ',' || $x->text === '}') {
                $this->addUse($uses, $prefix . trim($name, '\\'), $alias);
                $name = ''; $alias = null;
                continue;
            }
            if ($x->is(T_AS)) {
                $alias = $t[$j + 1]->text ?? null;
                $j++;
                continue;
            }
            if ($x->is([T_FUNCTION, T_CONST])) continue;
            $name .= $x->text;
        }
        if ($name !== '') $this->addUse($uses, $prefix . trim($name, '\\'), $alias);
        return $j;
    }

    private function addUse(array &$uses, string $fqn, ?string $alias): void
    {
        $fqn = trim($fqn, '\\');
        if ($fqn === '') return;
        $alias ??= substr($fqn, (int) strrpos('\\' . $fqn, '\\'));
        $uses[strtolower($alias)] = $fqn;
    }

    /** @return string[] */
    private function classModifiers(array $t, int $i): array
    {
        $mods = [];
        for ($j = $i - 1; $j >= 0; $j--) {
            if ($t[$j]->is(T_ABSTRACT)) $mods[] = 'abstract';
            elseif ($t[$j]->is(T_FINAL)) $mods[] = 'final';
            elseif ($t[$j]->is(T_READONLY)) $mods[] = 'readonly';
            elseif ($t[$j]->is(T_ATTRIBUTE) || $t[$j]->text === ']') break;
            else break;
        }
        return $mods;
    }

    /**
     * Parse "modifiers function name(params): type" starting at the T_FUNCTION.
     *
     * @return array{0: ?array, 1: int} the method, and the index of its last header token
     */
    private function parseMethod(array $t, int $i, string $ns, array $uses): array
    {
        $n = count($t);
        $mods = [];
        for ($j = $i - 1; $j >= 0; $j--) {
            $x = $t[$j];
            if ($x->is([T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_ABSTRACT, T_FINAL])) {
                $mods[] = strtolower($x->text);
                continue;
            }
            break;
        }
        $j = $i + 1;
        if ($j < $n && $t[$j]->text === '&') $j++;
        if ($j >= $n || !$t[$j]->is(T_STRING) && !$t[$j]->is(T_ENUM) && !$t[$j]->is(T_READONLY) && !preg_match('/^[A-Za-z_]\w*$/', $t[$j]->text)) {
            return [null, $i];
        }
        $name = $t[$j]->text;
        $line = $t[$i]->line;
        $j++;
        if ($j >= $n || $t[$j]->text !== '(') return [null, $j];

        // Parameters, split on top-level commas.
        $params = [];
        $cur = [];
        $paren = 0;
        $brack = 0;
        for ($j = $j + 1; $j < $n; $j++) {
            $x = $t[$j];
            if ($x->text === '(' ) $paren++;
            if ($x->text === ')') {
                if ($paren === 0) break;
                $paren--;
            }
            if ($x->text === '[' || $x->is(T_ATTRIBUTE)) $brack++;
            if ($x->text === ']') $brack--;
            if ($x->text === ',' && $paren === 0 && $brack === 0) {
                if ($cur) $params[] = $this->param($cur, $ns, $uses);
                $cur = [];
                continue;
            }
            $cur[] = $x;
        }
        if ($cur) $params[] = $this->param($cur, $ns, $uses);
        $params = array_values(array_filter($params));

        // Return type: after ")" and ":" up to "{" or ";".
        $return = null;
        $k = $j + 1;
        if ($k < $n && $t[$k]->text === ':') {
            $txt = '';
            for ($k = $k + 1; $k < $n && $t[$k]->text !== '{' && $t[$k]->text !== ';'; $k++) {
                $txt .= $this->typeToken($t[$k], $ns, $uses);
            }
            $return = $txt !== '' ? $txt : null;
        }
        while ($k < $n && $t[$k]->text !== '{' && $t[$k]->text !== ';') $k++;
        // Leave the "{" for the main walk so the depth count stays right.
        $last = ($k < $n && $t[$k]->text === '{') ? $k - 1 : $k;

        $vis = 'public';
        foreach (['private', 'protected', 'public'] as $v) if (in_array($v, $mods, true)) $vis = $v;

        return [[
            'name' => $name, 'line' => $line, 'visibility' => $vis,
            'static' => in_array('static', $mods, true), 'abstract' => in_array('abstract', $mods, true),
            'final' => in_array('final', $mods, true),
            'params' => $params, 'return' => $return, 'source' => 'tokens',
        ], $last];
    }

    /** @param \PhpToken[] $toks */
    private function param(array $toks, string $ns, array $uses): ?array
    {
        // Skip attributes "#[...]".
        $clean = [];
        $attr = 0;
        foreach ($toks as $x) {
            if ($x->is(T_ATTRIBUTE)) { $attr++; continue; }
            if ($attr > 0) { if ($x->text === '[') $attr++; if ($x->text === ']') $attr--; continue; }
            $clean[] = $x;
        }
        $type = '';
        $variadic = false;
        $default = false;
        $seenVar = false;
        foreach ($clean as $x) {
            if ($seenVar) { if ($x->text === '=') $default = true; continue; }
            if ($x->is(T_VARIABLE)) { $seenVar = true; continue; }
            if ($x->is(T_ELLIPSIS)) { $variadic = true; continue; }
            if ($x->text === '&') continue;
            if ($x->is([T_PUBLIC, T_PROTECTED, T_PRIVATE, T_READONLY])) continue;
            $type .= $this->typeToken($x, $ns, $uses);
        }
        if (!$seenVar) return null;
        return ['type' => $type !== '' ? $type : null, 'optional' => $default || $variadic, 'variadic' => $variadic];
    }

    private function typeToken(\PhpToken $x, string $ns, array $uses): string
    {
        if ($x->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE, T_ARRAY, T_CALLABLE, T_STATIC])) {
            $lc = strtolower($x->text);
            if (in_array($lc, self::BUILTIN, true) || in_array($lc, ['self', 'static', 'parent'], true) || $x->is([T_ARRAY, T_CALLABLE, T_STATIC])) {
                return $lc;
            }
            return '\\' . $this->resolve($x->text, $ns, $uses);
        }
        return trim($x->text);
    }

    private function resolve(string $name, string $ns, array $uses): string
    {
        if (str_starts_with($name, '\\')) return ltrim($name, '\\');
        if (str_starts_with(strtolower($name), 'namespace\\')) return ($ns !== '' ? $ns . '\\' : '') . substr($name, 10);
        $parts = explode('\\', $name);
        $first = strtolower($parts[0]);
        if (isset($uses[$first])) {
            $parts[0] = $uses[$first];
            return implode('\\', $parts);
        }
        return ($ns !== '' ? $ns . '\\' : '') . $name;
    }

    private function prevIs(array $t, int $i, int $id): bool
    {
        return $i > 0 && isset($t[$i - 1]) && $t[$i - 1]->is($id);
    }

    // ------------------------------------------------------------------
    // Checks
    // ------------------------------------------------------------------

    private function checkClass(array $c): void
    {
        $file = $c['file'];
        $sev = $this->isSideFile($file) ? 'warning' : 'error';
        $lc = strtolower($c['name']);

        // A name core (or another loaded app) already uses.
        $clash = $this->foreignDeclaration($c['name']);
        if ($clash !== null) {
            $this->add('name-in-use',
                ucfirst($c['kind']) . " {$c['name']} has the same name as {$clash}. PHP stops with \"Cannot declare {$c['kind']} {$c['name']}, because the name is already in use\".",
                $file, $c['line'], $sev);
            return;
        }
        if ($c['kind'] !== 'class') return;

        // Parent.
        $parent = $c['extends'][0] ?? null;
        $chainMethods = [];
        if ($parent !== null) {
            $plc = strtolower($parent);
            if (isset($this->classes[$plc])) {
                $p = $this->classes[$plc];
                if ($p['kind'] !== 'class') {
                    $this->add('bad-parent', "Class {$c['name']} extends {$p['kind']} {$p['name']}, which only a class can.", $file, $c['line'], $sev);
                    return;
                }
                if ($p['final']) {
                    $this->add('final-parent', "Class {$c['name']} extends {$p['name']}, which is final.", $file, $c['line'], $sev);
                }
            } elseif ($this->isCoreName($parent)) {
                if (!$this->coreClassExists($parent)) {
                    $this->add('missing-parent', "Class {$c['name']} extends {$parent}, which does not exist in this version of Basehim.", $file, $c['line'], $sev);
                    return;
                }
                $ref = new \ReflectionClass($parent);
                if ($ref->isInterface() || $ref->isTrait()) {
                    $this->add('bad-parent', "Class {$c['name']} extends {$parent}, which is not a class.", $file, $c['line'], $sev);
                    return;
                }
                if ($ref->isFinal()) {
                    $this->add('final-parent', "Class {$c['name']} extends {$parent}, which is final.", $file, $c['line'], $sev);
                }
            } else {
                // Third-party or PHP class: not resolvable without loading it.
                if (!$this->builtinClassExists($parent)) return;
            }
            $chainMethods = $this->inheritedMethods($parent, [$lc => true]);
        }

        // Each declared method against the inherited one.
        foreach ($c['methods'] as $mlc => $m) {
            if (!isset($chainMethods[$mlc])) continue;
            $pm = $chainMethods[$mlc];
            $this->compare($c, $m, $pm);
        }

        // Interfaces from core (and the app's own).
        $required = [];
        foreach ($c['implements'] as $iface) {
            $ilc = strtolower($iface);
            if (isset($this->classes[$ilc])) {
                if ($this->classes[$ilc]['kind'] !== 'interface') {
                    $this->add('bad-interface', "Class {$c['name']} implements {$iface}, which is not an interface.", $file, $c['line'], $sev);
                    continue;
                }
                foreach ($this->interfaceMethods($iface, []) as $k => $im) $required[$k] = $im;
            } elseif ($this->isCoreName($iface)) {
                if (!interface_exists($iface)) {
                    $this->add('missing-interface', "Class {$c['name']} implements {$iface}, which does not exist in this version of Basehim.", $file, $c['line'], $sev);
                    continue;
                }
                foreach ($this->interfaceMethods($iface, []) as $k => $im) $required[$k] = $im;
            }
        }
        foreach ($required as $mlc => $im) {
            if (isset($c['methods'][$mlc])) {
                $this->compare($c, $c['methods'][$mlc], $im);
            }
        }

        // A concrete class must implement every abstract method it inherits.
        // Traits can supply methods we cannot see, so stay silent if it uses any.
        if (!$c['abstract'] && !$c['uses_traits'] && !$this->chainUsesTraits($c)) {
            $abstract = [];
            foreach ($chainMethods as $mlc => $pm) {
                if (!empty($pm['abstract']) && !isset($c['methods'][$mlc])) $abstract[$mlc] = $pm;
            }
            foreach ($required as $mlc => $im) {
                if (!isset($c['methods'][$mlc]) && !(isset($chainMethods[$mlc]) && empty($chainMethods[$mlc]['abstract']))) {
                    $abstract[$mlc] = $im;
                }
            }
            foreach ($c['methods'] as $mlc => $m) {
                if ($m['abstract']) $abstract[$mlc] = $m;
            }
            if ($abstract) {
                $names = array_map(static fn($m) => (isset($m['class']) ? $m['class'] . '::' : '') . $m['name'] . '()', array_values($abstract));
                $this->add('abstract-missing',
                    "Class {$c['name']} must implement " . implode(', ', array_slice($names, 0, 5)) . (count($names) > 5 ? ' …' : '')
                    . ' or be declared abstract. PHP stops with "contains abstract method".',
                    $file, $c['line'], $sev);
            }
        }
    }

    private function chainUsesTraits(array $c): bool
    {
        $seen = [];
        while (($p = $c['extends'][0] ?? null) !== null) {
            $plc = strtolower($p);
            if (isset($seen[$plc]) || !isset($this->classes[$plc])) return false;
            $seen[$plc] = true;
            $c = $this->classes[$plc];
            if ($c['uses_traits']) return true;
        }
        return false;
    }

    /**
     * Methods a class inherits from $parent, nearest declaration first,
     * keyed by lower-case name. Private methods are left out: they are not
     * inherited and constrain nothing.
     *
     * @return array<string, array>
     */
    private function inheritedMethods(string $parent, array $seen): array
    {
        $plc = strtolower($parent);
        if (isset($seen[$plc])) return [];
        $seen[$plc] = true;

        if (isset($this->classes[$plc])) {
            $p = $this->classes[$plc];
            $own = [];
            foreach ($p['methods'] as $k => $m) {
                if ($m['visibility'] === 'private') continue;
                $own[$k] = $m + ['class' => $p['name']];
            }
            $up = isset($p['extends'][0]) ? $this->inheritedMethods($p['extends'][0], $seen) : [];
            // Interfaces of the app's parent count as well.
            foreach ($p['implements'] as $iface) {
                foreach ($this->interfaceMethods($iface, []) as $k => $im) {
                    if (!isset($up[$k])) $up[$k] = $im;
                }
            }
            return $own + $up;
        }

        if (!$this->isCoreName($parent) && !$this->builtinClassExists($parent)) return [];
        if (!class_exists($parent)) return [];
        $out = [];
        foreach ((new \ReflectionClass($parent))->getMethods() as $rm) {
            if ($rm->isPrivate()) continue;
            $out[strtolower($rm->getName())] = $this->fromReflection($rm);
        }
        return $out;
    }

    /** @return array<string, array> */
    private function interfaceMethods(string $iface, array $seen): array
    {
        $ilc = strtolower($iface);
        if (isset($seen[$ilc])) return [];
        $seen[$ilc] = true;
        if (isset($this->classes[$ilc])) {
            $i = $this->classes[$ilc];
            $out = [];
            foreach ($i['methods'] as $k => $m) $out[$k] = $m + ['class' => $i['name'], 'abstract' => true];
            foreach ($i['extends'] as $parent) {
                foreach ($this->interfaceMethods($parent, $seen) as $k => $m) if (!isset($out[$k])) $out[$k] = $m;
            }
            return $out;
        }
        if (($this->isCoreName($iface) || $this->builtinClassExists($iface)) && interface_exists($iface)) {
            $out = [];
            foreach ((new \ReflectionClass($iface))->getMethods() as $rm) {
                $out[strtolower($rm->getName())] = $this->fromReflection($rm) + ['abstract' => true];
            }
            return $out;
        }
        return [];
    }

    private function fromReflection(\ReflectionMethod $rm): array
    {
        $params = [];
        foreach ($rm->getParameters() as $p) {
            $params[] = [
                'type' => $p->hasType() ? $this->reflType($p->getType()) : null,
                'optional' => $p->isOptional(), 'variadic' => $p->isVariadic(),
            ];
        }
        $ret = null;
        $tentative = false;
        if ($rm->hasReturnType()) {
            $ret = $this->reflType($rm->getReturnType());
        } elseif ($rm->hasTentativeReturnType()) {
            $ret = $this->reflType($rm->getTentativeReturnType());
            $tentative = true;
        }
        return [
            'name' => $rm->getName(), 'class' => $rm->getDeclaringClass()->getName(),
            'visibility' => $rm->isPublic() ? 'public' : ($rm->isProtected() ? 'protected' : 'private'),
            'static' => $rm->isStatic(), 'abstract' => $rm->isAbstract(), 'final' => $rm->isFinal(),
            'params' => $params, 'return' => $ret, 'tentative' => $tentative,
            'internal' => $rm->isInternal(), 'source' => 'reflection',
            'ctor' => $rm->isConstructor(),
        ];
    }

    private function reflType(?\ReflectionType $t): ?string
    {
        if ($t === null) return null;
        if ($t instanceof \ReflectionNamedType) {
            $name = $t->getName();
            $lc = strtolower($name);
            $s = (in_array($lc, self::BUILTIN, true) || in_array($lc, ['self', 'static', 'parent'], true)) ? $lc : '\\' . ltrim($name, '\\');
            return ($t->allowsNull() && !in_array($lc, ['mixed', 'null'], true)) ? '?' . $s : $s;
        }
        if ($t instanceof \ReflectionUnionType) {
            return implode('|', array_map(fn($x) => (string) $this->reflType($x), $t->getTypes()));
        }
        if ($t instanceof \ReflectionIntersectionType) {
            return implode('&', array_map(fn($x) => (string) $this->reflType($x), $t->getTypes()));
        }
        return (string) $t;
    }

    /**
     * Compare a child method with the one it overrides, flagging only what
     * PHP rejects with a fatal error.
     */
    private function compare(array $c, array $m, array $p): void
    {
        $file = $c['file'];
        $sev = $this->isSideFile($file) ? 'warning' : 'error';
        $where = ($p['class'] ?? 'its parent');
        $sig = "{$c['name']}::{$m['name']}()";
        $psig = "{$where}::{$p['name']}()";

        if (!empty($p['final'])) {
            $this->add('final-method', "{$sig} overrides final method {$psig}.", $file, $m['line'], $sev);
            return;
        }
        if ($m['static'] !== $p['static']) {
            $this->add('static-mismatch',
                "{$sig} is " . ($m['static'] ? 'static' : 'not static') . " but {$psig} is " . ($p['static'] ? 'static' : 'not') . '. PHP stops with "Cannot make … static".',
                $file, $m['line'], $sev);
            return;
        }
        if (self::VIS_RANK[$m['visibility']] < self::VIS_RANK[$p['visibility']]) {
            $this->add('visibility',
                "Access level to {$sig} must be {$p['visibility']} (as in {$where}) or weaker — it is {$m['visibility']}. "
                . "This app was written before {$psig} existed in core, or uses the same name for something else: rename the method in the app.",
                $file, $m['line'], $sev);
            return;
        }
        // Constructors are exempt from signature rules unless the parent's is abstract (or from an interface).
        if (!empty($p['ctor']) && empty($p['abstract'])) return;
        if (strtolower($m['name']) === '__construct' && empty($p['abstract'])) return;

        $problem = $this->signatureProblem($m, $p);
        if ($problem !== null) {
            $this->add('signature',
                "Declaration of {$sig} must be compatible with {$psig}" . $this->describe($p) . ": {$problem}. "
                . "Rename the app's method, or match the core signature.",
                $file, $m['line'], $sev);
        }
    }

    private function signatureProblem(array $m, array $p): ?string
    {
        $cp = $m['params'];
        $pp = $p['params'];
        $childVariadic = (bool) array_filter($cp, static fn($x) => $x['variadic']);

        if (!$childVariadic && count($cp) < count($pp)) {
            return 'it takes ' . count($cp) . ' parameter' . (count($cp) === 1 ? '' : 's') . ', the parent passes up to ' . count($pp);
        }
        $req = count(array_filter($cp, static fn($x) => !$x['optional']));
        $preq = count(array_filter($pp, static fn($x) => !$x['optional']));
        if ($req > $preq) {
            return "it requires {$req} argument" . ($req === 1 ? '' : 's') . ", the parent requires {$preq}";
        }
        foreach ($pp as $i => $ppar) {
            $cpar = $cp[$i] ?? ($childVariadic ? end($cp) : null);
            if ($cpar === null) break;
            $why = $this->paramTypeProblem($cpar['type'], $ppar['type']);
            if ($why !== null) return 'parameter ' . ($i + 1) . ' ' . $why;
        }

        // Return types (covariant). A tentative type on an internal method is only a deprecation.
        $pr = $p['return'];
        if ($pr !== null && empty($p['tentative'])) {
            $cr = $m['return'];
            if ($cr === null) return "it has no return type, the parent returns {$pr}";
            $why = $this->returnTypeProblem($cr, $pr);
            if ($why !== null) return $why;
        }
        return null;
    }

    /** null when the child's parameter type accepts everything the parent's does (or cannot be decided). */
    private function paramTypeProblem(?string $child, ?string $parent): ?string
    {
        if ($child === null) return null;               // untyped accepts all
        $c = $this->typeSet($child);
        if ($c === null) return null;                    // class-like or intersection: undecidable here
        if (isset($c['mixed'])) return null;
        if ($parent === null) return "is typed {$child}, the parent's is untyped";
        $p = $this->typeSet($parent);
        if ($p === null) return null;
        if (isset($c['iterable'])) $c['array'] = true;
        if (isset($c['bool'])) { $c['true'] = true; $c['false'] = true; }
        if (isset($c['float'])) $c['int'] = true;        // int is accepted where float is declared
        foreach (array_keys($p) as $type) {
            if ($type === 'mixed') return "is typed {$child}, the parent accepts mixed";
            if (!isset($c[$type])) return "is typed {$child}, the parent accepts {$parent}";
        }
        return null;
    }

    private function returnTypeProblem(string $child, string $parent): ?string
    {
        $c = $this->typeSet($child);
        $p = $this->typeSet($parent);
        if (isset($p['void'])) {
            if ($c !== null && count($c) === 1 && (isset($c['void']) || isset($c['never']))) return null;
            return "it returns {$child}, the parent returns void";
        }
        if ($c === null || $p === null) return null;
        if (isset($c['never'])) return null;
        if (isset($c['void'])) return "it returns void, the parent returns {$parent}";
        if (isset($p['mixed'])) return null;
        if (isset($p['iterable'])) $p['array'] = true;
        if (isset($p['bool'])) { $p['true'] = true; $p['false'] = true; }
        foreach (array_keys($c) as $type) {
            if ($type === 'mixed') return "it returns mixed, the parent returns {$parent}";
            if (empty($p[$type])) return "it returns {$child}, the parent returns {$parent}";
        }
        return null;
    }

    /**
     * A union of builtin types as a set, or null when any part is a class,
     * self/static/parent or an intersection (decided by the load test instead).
     *
     * @return array<string,bool>|null
     */
    private function typeSet(string $type): ?array
    {
        if (str_contains($type, '&') || str_contains($type, '(')) return null;
        $set = [];
        foreach (explode('|', $type) as $part) {
            $part = trim($part);
            if ($part === '') continue;
            if ($part[0] === '?') { $set['null'] = true; $part = substr($part, 1); }
            $lc = strtolower($part);
            if (!in_array($lc, self::BUILTIN, true)) return null;
            $set[$lc] = true;
        }
        return $set ?: null;
    }

    private function describe(array $p): string
    {
        $params = [];
        foreach ($p['params'] as $x) {
            $params[] = trim(($x['type'] ?? '') . ' ' . ($x['variadic'] ? '...' : '') . '$…' . ($x['optional'] && !$x['variadic'] ? ' = …' : ''));
        }
        return ' (' . ($p['visibility'] ?? 'public') . ' function ' . $p['name'] . '(' . implode(', ', $params) . ')' . ($p['return'] !== null ? ': ' . $p['return'] : '') . ')';
    }

    private function checkFunctions(): void
    {
        foreach ($this->functions as $lcf => $f) {
            if (!function_exists($f['name'])) continue;
            $rf = new \ReflectionFunction($f['name']);
            $defined = $rf->isInternal() ? 'PHP itself' : $this->rel((string) $rf->getFileName());
            if (!$rf->isInternal() && $this->inside((string) $rf->getFileName(), $this->appDir)) continue;
            $this->add('function-in-use',
                "Function {$f['name']}() is already declared by {$defined}. PHP stops with \"Cannot redeclare\".",
                $f['file'], $f['line'], $this->isSideFile($f['file']) ? 'warning' : 'error');
        }
    }

    /** Where a name is already declared outside this app, or null. */
    private function foreignDeclaration(string $fqn): ?string
    {
        // A core class that is not loaded yet still collides when it is.
        if ($this->isCoreName($fqn)) {
            $rel = '/app/' . str_replace('\\', '/', substr($fqn, 4)) . '.php';
            if ($this->root !== '' && is_file($this->root . $rel)) return 'a Basehim core class (' . ltrim($rel, '/') . ')';
        }
        foreach (['class_exists', 'interface_exists', 'trait_exists', 'enum_exists'] as $fn) {
            if (!function_exists($fn) || !$fn($fqn, false)) continue;
            $ref = new \ReflectionClass($fqn);
            if ($ref->isInternal()) return 'a class built into PHP';
            $f = (string) $ref->getFileName();
            if ($this->inside($f, $this->appDir)) return null;
            if ($this->root !== '' && $this->inside($f, $this->root . '/content/apps')) {
                $slug = explode('/', substr(str_replace('\\', '/', $f), strlen($this->root . '/content/apps/')))[0] ?? '';
                return "a class of the app '{$slug}' (" . $this->rel($f) . ')';
            }
            return 'a class in ' . $this->rel($f);
        }
        return null;
    }

    private function isCoreName(string $fqn): bool
    {
        return str_starts_with(ltrim($fqn, '\\'), 'App\\');
    }

    private function coreClassExists(string $fqn): bool
    {
        try { return class_exists($fqn) || interface_exists($fqn) || trait_exists($fqn); } catch (\Throwable) { return false; }
    }

    private function builtinClassExists(string $fqn): bool
    {
        foreach (['class_exists', 'interface_exists'] as $fn) {
            if ($fn($fqn, false) && (new \ReflectionClass($fqn))->isInternal()) return true;
        }
        return false;
    }

    private function isSideFile(string $file): bool
    {
        $rel = '/' . ltrim(substr(str_replace('\\', '/', $file), strlen($this->appDir)), '/');
        foreach (self::SIDE_DIRS as $d) {
            if (str_contains(strtolower($rel), '/' . $d . '/')) return true;
        }
        return false;
    }

    private function inside(string $file, string $dir): bool
    {
        $file = str_replace('\\', '/', $file);
        $dir = rtrim(str_replace('\\', '/', $dir), '/') . '/';
        return $file !== '' && str_starts_with($file, $dir);
    }

    private function rel(string $file): string
    {
        $file = str_replace('\\', '/', $file);
        if ($this->root !== '' && str_starts_with($file, $this->root . '/')) return substr($file, strlen($this->root) + 1);
        if (str_starts_with($file, $this->appDir . '/')) return basename($this->appDir) . '/' . substr($file, strlen($this->appDir) + 1);
        return basename($file);
    }

    private function add(string $code, string $message, string $file, int $line, string $severity): void
    {
        $this->problems[] = [
            'code' => $code, 'message' => $message, 'file' => $this->rel($file),
            'line' => $line, 'severity' => $severity,
        ];
    }
}
