<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Icon — renders Heroicons v2 (outline) as inline SVG.
 *
 * Usage in views:   <?= icon('trash') ?>
 *                   <?= icon('trash', 'w-4 h-4 text-red-500') ?>
 *
 * Accepts either a Heroicon name ('trash', 'chevron-down') or a legacy
 * Font Awesome name ('fa-trash', 'fa-solid fa-trash'). The legacy forms are
 * translated automatically, so apps and themes that still register menu
 * icons as 'fa-cloud' keep working without changes.
 *
 * Icons are stroke-based and inherit colour via `stroke="currentColor"`, so
 * Tailwind text colour utilities (text-blue-600, etc.) work as expected.
 * Size comes from width/height classes (w-5 h-5), NOT font-size.
 */
final class Icon
{
    /** Fallback when a name cannot be resolved. */
    public const FALLBACK = 'question-mark-circle';

    /** @var array<string,string>|null Lazily loaded icon markup. */
    private static ?array $icons = null;

    /**
     * Legacy Font Awesome name (without the fa- prefix) => Heroicon name.
     * Kept so existing app/theme icon strings degrade gracefully.
     */
    private const LEGACY = [
        'address-book' => 'identification',
        'address-card' => 'identification',
        'adjust' => 'adjustments-horizontal',
        'align-left' => 'bars-3-bottom-left',
        'angles-left' => 'chevron-double-left',
        'angles-right' => 'chevron-double-right',
        'arrow-down' => 'arrow-down',
        'arrow-left' => 'arrow-left',
        'arrow-pointer' => 'cursor-arrow-rays',
        'arrow-right' => 'arrow-right',
        'arrow-right-from-bracket' => 'arrow-left-start-on-rectangle',
        'arrow-right-to-bracket' => 'arrow-right-end-on-rectangle',
        'arrow-trend-down' => 'arrow-trending-down',
        'arrow-trend-up' => 'arrow-trending-up',
        'arrow-up' => 'arrow-up',
        'arrow-up-from-bracket' => 'arrow-up-tray',
        'arrow-up-right-from-square' => 'arrow-top-right-on-square',
        'arrows-left-right' => 'arrows-right-left',
        'arrows-rotate' => 'arrow-path',
        'arrows-up-down' => 'arrows-up-down',
        'at' => 'at-symbol',
        'award' => 'trophy',
        'backward' => 'backward',
        'bag-shopping' => 'shopping-bag',
        'ban' => 'no-symbol',
        'bars' => 'bars-3',
        'beaker' => 'beaker',
        'bell' => 'bell',
        'bezier-curve' => 'variable',
        'bold' => 'bold',
        'bolt' => 'bolt',
        'book' => 'book-open',
        'book-open' => 'book-open',
        'book-open-reader' => 'book-open',
        'bookmark' => 'bookmark',
        'border-all' => 'table-cells',
        'box' => 'archive-box',
        'box-archive' => 'archive-box',
        'boxes-stacked' => 'rectangle-stack',
        'briefcase' => 'briefcase',
        'broom' => 'sparkles',
        'brush' => 'paint-brush',
        'bug' => 'bug-ant',
        'building' => 'building-office',
        'bullhorn' => 'megaphone',
        'cake' => 'cake',
        'calculator' => 'calculator',
        'calendar' => 'calendar',
        'camera' => 'camera',
        'cart-shopping' => 'shopping-cart',
        'certificate' => 'check-badge',
        'chart-area' => 'presentation-chart-line',
        'chart-bar' => 'chart-bar',
        'chart-column' => 'chart-bar',
        'chart-line' => 'presentation-chart-line',
        'chart-pie' => 'chart-pie',
        'chart-simple' => 'chart-bar',
        'check' => 'check',
        'check-double' => 'check-badge',
        'chevron-down' => 'chevron-down',
        'chevron-left' => 'chevron-left',
        'chevron-right' => 'chevron-right',
        'chevron-up' => 'chevron-up',
        'circle' => 'stop-circle',
        'circle-check' => 'check-circle',
        'circle-dashed' => 'stop-circle',
        'circle-dot' => 'stop-circle',
        'circle-exclamation' => 'exclamation-circle',
        'circle-half-stroke' => 'moon',
        'circle-info' => 'information-circle',
        'circle-nodes' => 'share',
        'circle-notch' => 'arrow-path',
        'circle-pause' => 'pause-circle',
        'circle-play' => 'play-circle',
        'circle-question' => 'question-mark-circle',
        'circle-stop' => 'stop-circle',
        'circle-user' => 'user-circle',
        'circle-xmark' => 'x-circle',
        'clock' => 'clock',
        'clock-rotate-left' => 'clock',
        'clone' => 'square-2-stack',
        'close' => 'x-mark',
        'cloud' => 'cloud',
        'cloud-arrow-down' => 'cloud-arrow-down',
        'cloud-arrow-up' => 'cloud-arrow-up',
        'code' => 'code-bracket',
        'cog' => 'cog-6-tooth',
        'cogs' => 'cog-6-tooth',
        'comment' => 'chat-bubble-left',
        'comment-dots' => 'chat-bubble-left-ellipsis',
        'comment-slash' => 'no-symbol',
        'comments' => 'chat-bubble-left-right',
        'compass' => 'map',
        'compress' => 'arrows-pointing-in',
        'copy' => 'document-duplicate',
        'credit-card' => 'credit-card',
        'crosshairs' => 'viewfinder-circle',
        'crown' => 'trophy',
        'cube' => 'cube',
        'cubes' => 'square-3-stack-3d',
        'database' => 'circle-stack',
        'desktop' => 'computer-desktop',
        'diagram-project' => 'share',
        'display' => 'computer-desktop',
        'dollar-sign' => 'currency-dollar',
        'download' => 'arrow-down-tray',
        'droplet' => 'beaker',
        'earth-americas' => 'globe-americas',
        'edit' => 'pencil-square',
        'ellipsis' => 'ellipsis-horizontal',
        'ellipsis-vertical' => 'ellipsis-vertical',
        'envelope' => 'envelope',
        'envelope-open' => 'envelope-open',
        'equals' => 'equals',
        'eraser' => 'backspace',
        'exclamation-triangle' => 'exclamation-triangle',
        'expand' => 'arrows-pointing-out',
        'eye' => 'eye',
        'eye-slash' => 'eye-slash',
        'face-frown' => 'face-frown',
        'face-smile' => 'face-smile',
        'file' => 'document',
        'file-arrow-down' => 'document-arrow-down',
        'file-circle-plus' => 'document-plus',
        'file-code' => 'code-bracket-square',
        'file-excel' => 'table-cells',
        'file-import' => 'arrow-down-on-square',
        'file-lines' => 'document-text',
        'file-pdf' => 'document-text',
        'file-word' => 'document-text',
        'file-zipper' => 'archive-box',
        'fill-drip' => 'paint-brush',
        'film' => 'film',
        'filter' => 'funnel',
        'fingerprint' => 'finger-print',
        'fire' => 'fire',
        'flag' => 'flag',
        'floppy-disk' => 'document-check',
        'folder' => 'folder',
        'folder-open' => 'folder-open',
        'folder-plus' => 'folder-plus',
        'folder-tree' => 'folder',
        'font' => 'language',
        'forward' => 'forward',
        'gauge-high' => 'chart-bar',
        'gavel' => 'scale',
        'gear' => 'cog-6-tooth',
        'gears' => 'cog-6-tooth',
        'gem' => 'sparkles',
        'gift' => 'gift',
        'globe' => 'globe-alt',
        'globe-americas' => 'globe-americas',
        'graduation-cap' => 'academic-cap',
        'grip' => 'bars-2',
        'grip-vertical' => 'bars-2',
        'hammer' => 'wrench-screwdriver',
        'hand-pointer' => 'cursor-arrow-rays',
        'hand-sparkles' => 'sparkles',
        'hand-spock' => 'hand-raised',
        'hands-helping' => 'hand-raised',
        'handshake' => 'hand-raised',
        'hard-drive' => 'circle-stack',
        'hashtag' => 'hashtag',
        'heading' => 'h1',
        'heart' => 'heart',
        'heart-pulse' => 'heart',
        'home' => 'home',
        'house' => 'home',
        'id-card' => 'identification',
        'image' => 'photo',
        'inbox' => 'inbox',
        'inbox-full' => 'inbox-stack',
        'indent' => 'bars-3-bottom-right',
        'info' => 'information-circle',
        'italic' => 'italic',
        'key' => 'key',
        'language' => 'language',
        'layer-group' => 'square-3-stack-3d',
        'leaf' => 'sparkles',
        'life-ring' => 'lifebuoy',
        'lightbulb' => 'light-bulb',
        'link' => 'link',
        'link-slash' => 'link-slash',
        'list' => 'list-bullet',
        'list-ol' => 'numbered-list',
        'list-ul' => 'list-bullet',
        'location-dot' => 'map-pin',
        'lock' => 'lock-closed',
        'lock-open' => 'lock-open',
        'magic' => 'sparkles',
        'magnifying-glass' => 'magnifying-glass',
        'magnifying-glass-chart' => 'document-magnifying-glass',
        'magnifying-glass-minus' => 'magnifying-glass-minus',
        'map-pin' => 'map-pin',
        'maximize' => 'arrows-pointing-out',
        'medal' => 'trophy',
        'megaphone' => 'megaphone',
        'memory' => 'cpu-chip',
        'microchip' => 'cpu-chip',
        'microphone' => 'microphone',
        'minimize' => 'arrows-pointing-in',
        'minus' => 'minus',
        'mobile' => 'device-phone-mobile',
        'money-bill' => 'banknotes',
        'moon' => 'moon',
        'music' => 'musical-note',
        'network-wired' => 'share',
        'newspaper' => 'newspaper',
        'newspaper-o' => 'newspaper',
        'paint-roller' => 'paint-brush',
        'palette' => 'swatch',
        'palette-swatch' => 'swatch',
        'paper-plane' => 'paper-airplane',
        'paperclip' => 'paper-clip',
        'paragraph' => 'bars-3-bottom-left',
        'pause' => 'pause',
        'pen' => 'pencil',
        'pen-to-square' => 'pencil-square',
        'pencil' => 'pencil',
        'percent' => 'percent-badge',
        'phone' => 'phone',
        'photo-film' => 'photo',
        'php' => 'code-bracket',
        'plane' => 'paper-airplane',
        'play' => 'play',
        'plug' => 'puzzle-piece',
        'plug-circle-xmark' => 'x-circle',
        'plus' => 'plus',
        'power-off' => 'power',
        'print' => 'printer',
        'puzzle-piece' => 'squares-2x2',
        'qrcode' => 'qr-code',
        'question' => 'question-mark-circle',
        'quote-left' => 'chat-bubble-bottom-center-text',
        'radar' => 'viewfinder-circle',
        'refresh' => 'arrow-path',
        'reply' => 'arrow-uturn-left',
        'right-left' => 'arrows-right-left',
        'robot' => 'cpu-chip',
        'rocket' => 'rocket-launch',
        'rocket-launch' => 'rocket-launch',
        'rotate' => 'arrow-path',
        'rotate-left' => 'arrow-uturn-left',
        'rss' => 'rss',
        'satellite' => 'signal',
        'satellite-dish' => 'signal',
        'scale-balanced' => 'scale',
        'scissors' => 'scissors',
        'screwdriver-wrench' => 'wrench-screwdriver',
        'search' => 'magnifying-glass',
        'seedling' => 'sparkles',
        'server' => 'server',
        'shapes' => 'squares-plus',
        'share-nodes' => 'share',
        'shield' => 'shield-check',
        'shield-halved' => 'shield-check',
        'signal' => 'signal',
        'sitemap' => 'share',
        'sliders' => 'adjustments-horizontal',
        'spinner' => 'arrow-path',
        'square-check' => 'check-circle',
        'square-poll-vertical' => 'chart-bar',
        'square-share-nodes' => 'share',
        'square-xmark' => 'x-circle',
        'star' => 'star',
        'star-half' => 'star',
        'stop' => 'stop',
        'store' => 'building-storefront',
        'strikethrough' => 'strikethrough',
        'sun' => 'sun',
        'sync' => 'arrow-path',
        'table-cells' => 'table-cells',
        'table-cells-large' => 'table-cells',
        'table-columns' => 'view-columns',
        'tablet' => 'device-tablet',
        'tag' => 'tag',
        'tags' => 'tag',
        'terminal' => 'command-line',
        'text-height' => 'bars-2',
        'thumbs-down' => 'hand-thumb-down',
        'thumbs-up' => 'hand-thumb-up',
        'thumbtack' => 'bookmark',
        'ticket' => 'ticket',
        'timeline' => 'chart-bar',
        'times' => 'x-mark',
        'toggle-off' => 'x-circle',
        'toggle-on' => 'check-circle',
        'toolbox' => 'wrench-screwdriver',
        'tower-broadcast' => 'signal',
        'trash' => 'trash',
        'trash-can' => 'trash',
        'trash-can-arrow-up' => 'arrow-uturn-left',
        'tree' => 'sparkles',
        'triangle-exclamation' => 'exclamation-triangle',
        'trophy' => 'trophy',
        'truck' => 'truck',
        'tv' => 'tv',
        'underline' => 'underline',
        'unlock' => 'lock-open',
        'upload' => 'arrow-up-tray',
        'user' => 'user',
        'user-gear' => 'cog-6-tooth',
        'user-group' => 'user-group',
        'user-lock' => 'lock-closed',
        'user-minus' => 'user-minus',
        'user-pen' => 'pencil-square',
        'user-plus' => 'user-plus',
        'user-shield' => 'shield-check',
        'users' => 'users',
        'users-slash' => 'user-minus',
        'video' => 'video-camera',
        'volume-high' => 'speaker-wave',
        'volume-xmark' => 'speaker-x-mark',
        'wand-magic-sparkles' => 'sparkles',
        'wand-sparkles' => 'sparkles',
        'warning' => 'exclamation-triangle',
        'wifi' => 'wifi',
        'window-maximize' => 'window',
        'wordpress' => 'globe-alt',
        'wrench' => 'wrench-screwdriver',
        'xmark' => 'x-mark',
        'youtube' => 'play-circle',
    ];

    /**
     * Render an icon as inline SVG.
     *
     * @param string $name  Heroicon name, or a legacy fa-* name/class string.
     * @param string $class CSS classes for the <svg> (size, colour, margins).
     * @param array  $attrs Extra attributes, e.g. ['aria-label' => 'Delete'].
     */
    public static function svg(string $name, string $class = 'w-5 h-5', array $attrs = []): string
    {
        $resolved = self::resolve($name);
        $inner = self::icons()[$resolved] ?? self::icons()[self::FALLBACK] ?? '';

        $attrString = '';
        foreach ($attrs as $k => $v) {
            $attrString .= ' ' . htmlspecialchars((string) $k, ENT_QUOTES)
                . '="' . htmlspecialchars((string) $v, ENT_QUOTES) . '"';
        }
        // aria-hidden unless the caller supplied a label/role.
        if (!isset($attrs['aria-label']) && !isset($attrs['role'])) {
            $attrString .= ' aria-hidden="true"';
        }

        // Tailwind's Preflight resets `svg { display: block }`, which would push
        // every inline icon onto its own line. Default to inline-block unless the
        // caller already specified a display utility (block, flex, hidden, …) so
        // their choice always wins.
        $class = trim($class);
        if (!self::hasDisplayClass($class)) {
            $class = $class === '' ? 'inline-block' : 'inline-block ' . $class;
        }

        $classAttr = $class !== '' ? ' class="' . htmlspecialchars($class, ENT_QUOTES) . '"' : '';

        return '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"'
            . ' stroke-width="1.5" stroke="currentColor"' . $classAttr . $attrString . '>'
            . $inner . '</svg>';
    }

    /**
     * Does a class string already set a display mode? Handles responsive and
     * state variants (sm:flex, lg:hidden, group-hover:block, …).
     */
    private static function hasDisplayClass(string $class): bool
    {
        static $display = [
            'block', 'inline-block', 'inline', 'flex', 'inline-flex', 'table',
            'inline-table', 'table-cell', 'table-row', 'flow-root', 'grid',
            'inline-grid', 'contents', 'list-item', 'hidden',
        ];
        foreach (preg_split('/\s+/', $class) ?: [] as $token) {
            if ($token === '') continue;
            // Strip any variant prefixes: "lg:hover:hidden" -> "hidden"
            $base = strrchr($token, ':');
            $base = $base === false ? $token : substr($base, 1);
            if (in_array($base, $display, true)) return true;
        }
        return false;
    }

    /** True if an icon name (after resolution) exists in the set. */
    public static function has(string $name): bool
    {
        return isset(self::icons()[self::resolve($name)]);
    }

    /** All available Heroicon names. */
    public static function names(): array
    {
        return array_keys(self::icons());
    }

    /**
     * Normalise a name to a Heroicon name.
     * Handles 'trash', 'fa-trash', and full class strings like
     * 'fa-solid fa-trash fa-fw'.
     */
    public static function resolve(string $name): string
    {
        $n = strtolower(trim($name));
        if ($n === '') return self::FALLBACK;

        // Direct hit on a Heroicon name.
        if (isset(self::icons()[$n])) return $n;

        // Legacy: pull the meaningful fa-* token out of a class string.
        if (str_contains($n, 'fa-')) {
            $styles = ['fa-solid', 'fa-regular', 'fa-brands', 'fa-light', 'fa-thin',
                       'fa-duotone', 'fa-fw', 'fa-spin', 'fa-pulse', 'fa-lg', 'fa-2x', 'fa-3x'];
            foreach (preg_split('/\s+/', $n) ?: [] as $token) {
                if (!str_starts_with($token, 'fa-') || in_array($token, $styles, true)) continue;
                $bare = substr($token, 3);
                if (isset(self::LEGACY[$bare])) return self::LEGACY[$bare];
                if (isset(self::icons()[$bare])) return $bare;
            }
            return self::FALLBACK;
        }

        // Bare legacy name without the prefix (e.g. 'floppy-disk').
        if (isset(self::LEGACY[$n])) return self::LEGACY[$n];

        return self::FALLBACK;
    }

    /** @return array<string,string> */
    private static function icons(): array
    {
        if (self::$icons === null) {
            $file = __DIR__ . '/icons.php';
            $data = is_file($file) ? require $file : [];
            self::$icons = is_array($data) ? $data : [];
        }
        return self::$icons;
    }
}
