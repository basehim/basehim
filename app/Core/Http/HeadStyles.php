<?php

declare(strict_types=1);

namespace App\Core\Http;

/**
 * Stylesheets a view or partial needs, printed in the page's <head> (1.3.0).
 *
 * A <link> printed by a view lands in <body>, after everything above it, so
 * the browser painted that part unstyled and then restyled it (the block
 * editor showed the admin's sidebar first, a data table its plain HTML).
 *
 * Request-wide and static on purpose: core's partials are included by apps in
 * every way there is (core's View::include(), a plain `require` inside an
 * app's own view, where $this is the app), and the stylesheet must reach the
 * page in all of them. 1.3.0 first asked `$this->style()`, which exists only
 * on core's View: CloudHim's Sites table answered 500, and apps that rendered
 * the table through View lost its stylesheet when their layout was rendered.
 *
 *     <?= \App\Core\Http\HeadStyles::add($base . '/admin/assets/css/x.css?v=1') ?>
 *
 * add() prints nothing when a layout that prints <head> is still to come
 * (core's views call extend(); App::adminView() says so before it renders the
 * app's body) and the link itself otherwise: in a fragment, or once <head> has
 * already gone out. Each stylesheet is printed once.
 */
final class HeadStyles
{
    /** @var array<string, array<string, string|true>> href => attributes, waiting for <head> */
    private static array $waiting = [];
    /** @var array<string, true> hrefs already in the page */
    private static array $printed = [];
    private static bool $headComing = false;
    private static bool $headDone = false;

    /** A layout that prints <head> (with printHead()) will render after this. */
    public static function expectHead(): void
    {
        if (!self::$headDone) self::$headComing = true;
    }

    /**
     * Ask for a stylesheet. Returns '' when it will be in <head>, or the
     * <link> to print right here when no <head> is still to come.
     *
     * @param array<string, string|true> $attrs extra attributes (true = bare)
     */
    public static function add(string $href, array $attrs = []): string
    {
        if ($href === '' || isset(self::$printed[$href])) return '';
        if (self::$headComing && !self::$headDone) {
            self::$waiting[$href] = $attrs;
            return '';
        }
        return self::tag($href, $attrs);
    }

    /** The waiting <link> tags, for a layout's <head>. */
    public static function printHead(): string
    {
        self::$headDone = true;
        self::$headComing = false;
        $out = '';
        foreach (self::$waiting as $href => $attrs) $out .= self::tag($href, $attrs);
        self::$waiting = [];
        return $out;
    }

    /**
     * Links still waiting when a render ends without its layout printing a
     * <head> (an app's own layout): prepended to the page, so nothing is lost.
     */
    public static function unprinted(): string
    {
        if (self::$waiting === []) return '';
        $out = '';
        foreach (self::$waiting as $href => $attrs) $out .= self::tag($href, $attrs);
        self::$waiting = [];
        self::$headComing = false;
        return $out;
    }

    /** For tests, and long-running processes that render more than one page. */
    public static function reset(): void
    {
        self::$waiting = [];
        self::$printed = [];
        self::$headComing = false;
        self::$headDone = false;
    }

    /** @param array<string, string|true> $attrs */
    private static function tag(string $href, array $attrs): string
    {
        if (isset(self::$printed[$href])) return '';
        self::$printed[$href] = true;
        $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $out = '<link rel="stylesheet" href="' . $e($href) . '"';
        foreach ($attrs as $k => $v) {
            if (!preg_match('/^[a-z][a-z0-9-]*$/', (string) $k)) continue;
            $out .= $v === true ? ' ' . $k : ' ' . $k . '="' . $e((string) $v) . '"';
        }
        return $out . ">\n";
    }
}
