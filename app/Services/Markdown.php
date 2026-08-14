<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Markdown — a compact, dependency-free CommonMark-flavoured renderer.
 *
 * Basehim ships without Composer, so this is written from scratch rather than
 * pulling a library. It covers the subset that actually appears in posts and
 * documentation:
 *
 *   headings, paragraphs, hard/soft line breaks, fenced + indented code,
 *   inline code, bold, italic, strikethrough, links, images, autolinks,
 *   ordered/unordered lists (incl. nesting), task lists, blockquotes,
 *   horizontal rules, tables, and raw HTML passthrough.
 *
 * Design notes
 *  - Fenced code is extracted *first* and replaced with placeholders, so inline
 *    rules can never mangle code samples (the classic markdown-renderer bug).
 *  - Text is escaped by default; raw HTML blocks are passed through because
 *    markdown expects that and only users with edit_posts can author content.
 */
final class Markdown
{
    /** @var string[] */
    private array $codeStore = [];

    public static function toHtml(string $md): string
    {
        return (new self())->render($md);
    }

    public function render(string $md): string
    {
        $md = str_replace(["\r\n", "\r"], "\n", $md);
        $md = $this->stashFencedCode($md);
        $html = $this->blocks($md);
        return $this->restoreCode($html);
    }

    // ==================================================================
    // Fenced code is protected before anything else touches the text.
    // ==================================================================

    private function stashFencedCode(string $md): string
    {
        return (string) preg_replace_callback(
            '/^(?:```|~~~)[ \t]*([\w+-]*)[ \t]*\n(.*?)\n?^(?:```|~~~)[ \t]*$/ms',
            function (array $m): string {
                $lang = trim($m[1]);
                $code = htmlspecialchars($m[2], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $cls  = $lang !== '' ? ' class="language-' . htmlspecialchars($lang, ENT_QUOTES) . '"' : '';
                $this->codeStore[] = '<pre><code' . $cls . '>' . $code . '</code></pre>';
                return "\x02CODE" . (count($this->codeStore) - 1) . "\x02";
            },
            $md
        ) ?? $md;
    }

    private function restoreCode(string $html): string
    {
        foreach ($this->codeStore as $i => $block) {
            $html = str_replace("\x02CODE{$i}\x02", $block, $html);
            // Placeholders that ended up wrapped in <p> should stand alone.
            $html = str_replace('<p>' . $block . '</p>', $block, $html);
        }
        return $html;
    }

    // ==================================================================
    // Block level
    // ==================================================================

    private function blocks(string $md): string
    {
        $lines = explode("\n", $md);
        $out = [];
        $para = [];

        $flush = function () use (&$para, &$out): void {
            if (!$para) return;
            $text = trim(implode("\n", $para));
            if ($text !== '') $out[] = '<p>' . $this->inline($text) . '</p>';
            $para = [];
        };

        for ($i = 0; $i < count($lines); $i++) {
            $line = $lines[$i];
            $t = trim($line);

            // Blank line
            if ($t === '') { $flush(); continue; }

            // Protected code placeholder
            if (preg_match('/^\x02CODE\d+\x02$/', $t)) { $flush(); $out[] = $t; continue; }

            // Horizontal rule
            if (preg_match('/^(?:-{3,}|\*{3,}|_{3,})$/', $t)) { $flush(); $out[] = '<hr>'; continue; }

            // ATX heading
            if (preg_match('/^(#{1,6})\s+(.*?)\s*#*\s*$/', $t, $m)) {
                $flush();
                $lvl = strlen($m[1]);
                $out[] = "<h{$lvl}>" . $this->inline($m[2]) . "</h{$lvl}>";
                continue;
            }

            // Setext heading (=== / ---) — only when it follows text
            if ($para && preg_match('/^(=+|-+)$/', $t)) {
                $title = trim(implode(' ', $para));
                $para = [];
                $lvl = $t[0] === '=' ? 1 : 2;
                $out[] = "<h{$lvl}>" . $this->inline($title) . "</h{$lvl}>";
                continue;
            }

            // Blockquote
            if (preg_match('/^>\s?/', $t)) {
                $flush();
                $buf = [];
                while ($i < count($lines) && preg_match('/^>\s?(.*)$/', trim($lines[$i]), $mm)) {
                    $buf[] = $mm[1];
                    $i++;
                }
                $i--;
                $out[] = '<blockquote>' . $this->blocks(implode("\n", $buf)) . '</blockquote>';
                continue;
            }

            // Table (header row + delimiter row)
            if (str_contains($t, '|') && isset($lines[$i + 1])
                && preg_match('/^\s*\|?[\s:-]*-[\s:|-]*\|?\s*$/', $lines[$i + 1])
                && str_contains($lines[$i + 1], '-')) {
                $flush();
                $i = $this->table($lines, $i, $out);
                continue;
            }

            // Lists
            if (preg_match('/^([*+-]|\d{1,9}[.)])\s+/', $t)) {
                $flush();
                $i = $this->list($lines, $i, $out);
                continue;
            }

            // Raw HTML block — pass straight through
            if (preg_match('/^<(\/?)([a-zA-Z][\w-]*)/', $t)) {
                $flush();
                $out[] = $line;
                continue;
            }

            $para[] = $line;
        }
        $flush();
        return implode("\n", $out);
    }

    /** Consume a list starting at $i; returns the index of its last line. */
    private function list(array $lines, int $i, array &$out): int
    {
        $first = trim($lines[$i]);
        $ordered = (bool) preg_match('/^\d{1,9}[.)]\s+/', $first);
        $tag = $ordered ? 'ol' : 'ul';
        $items = [];
        $cur = null;
        $baseIndent = null;

        for (; $i < count($lines); $i++) {
            $line = $lines[$i];
            $t = trim($line);
            if ($t === '') {
                // A blank line ends the list unless the next line continues it.
                $next = $lines[$i + 1] ?? '';
                if (!preg_match('/^\s*([*+-]|\d{1,9}[.)])\s+/', $next) && trim($next) !== '' && !preg_match('/^\s{2,}/', $next)) break;
                if (trim($next) === '') break;
                continue;
            }
            $indent = strlen($line) - strlen(ltrim($line));

            if (preg_match('/^([*+-]|\d{1,9}[.)])\s+(.*)$/', $t, $m)) {
                $thisOrdered = (bool) preg_match('/^\d/', $m[1]);
                if ($baseIndent === null) $baseIndent = $indent;

                // A deeper marker belongs to the current item (nested list).
                if ($indent > $baseIndent && $cur !== null) {
                    $cur['children'][] = $line;
                    continue;
                }
                // A different list type at the same level ends this list.
                if ($thisOrdered !== $ordered && $indent <= $baseIndent) break;

                if ($cur !== null) $items[] = $cur;
                $cur = ['text' => $m[2], 'children' => []];
                continue;
            }
            // Lazy continuation / indented child content
            if ($cur !== null) { $cur['children'][] = $line; continue; }
            break;
        }
        if ($cur !== null) $items[] = $cur;

        $html = "<{$tag}>";
        foreach ($items as $it) {
            $text = $it['text'];
            // GitHub-style task list
            $task = '';
            if (preg_match('/^\[([ xX])\]\s+(.*)$/', $text, $tm)) {
                $checked = strtolower($tm[1]) === 'x' ? ' checked' : '';
                $task = '<input type="checkbox" disabled' . $checked . '> ';
                $text = $tm[2];
            }
            $inner = $task . $this->inline($text);
            if ($it['children']) {
                $child = implode("\n", array_map(fn($l) => preg_replace('/^\s{1,4}/', '', $l) ?? $l, $it['children']));
                $rendered = trim($this->blocks($child));
                if ($rendered !== '') $inner .= "\n" . $rendered;
            }
            $html .= '<li>' . $inner . '</li>';
        }
        $html .= "</{$tag}>";
        $out[] = $html;
        return $i - 1;
    }

    /** Consume a table; returns the index of its last line. */
    private function table(array $lines, int $i, array &$out): int
    {
        $split = function (string $row): array {
            $row = trim($row);
            $row = preg_replace('/^\||\|$/', '', $row) ?? $row;
            return array_map('trim', explode('|', $row));
        };
        $head = $split($lines[$i]);
        $align = [];
        foreach ($split($lines[$i + 1]) as $c) {
            $l = str_starts_with($c, ':'); $r = str_ends_with($c, ':');
            $align[] = $l && $r ? 'center' : ($r ? 'right' : ($l ? 'left' : ''));
        }
        $html = '<table><thead><tr>';
        foreach ($head as $n => $c) {
            $st = ($align[$n] ?? '') !== '' ? ' style="text-align:' . $align[$n] . '"' : '';
            $html .= '<th' . $st . '>' . $this->inline($c) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        $i += 2;
        for (; $i < count($lines); $i++) {
            $t = trim($lines[$i]);
            if ($t === '' || !str_contains($t, '|')) break;
            $html .= '<tr>';
            foreach ($split($lines[$i]) as $n => $c) {
                $st = ($align[$n] ?? '') !== '' ? ' style="text-align:' . $align[$n] . '"' : '';
                $html .= '<td' . $st . '>' . $this->inline($c) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';
        $out[] = $html;
        return $i - 1;
    }

    // ==================================================================
    // Inline level
    // ==================================================================

    private function inline(string $t): string
    {
        // Protect inline code before escaping so backticked HTML shows literally.
        $spans = [];
        $t = (string) preg_replace_callback('/(`+)(.+?)\1/s', function (array $m) use (&$spans): string {
            $spans[] = '<code>' . htmlspecialchars(trim($m[2]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>';
            return "\x01C" . (count($spans) - 1) . "\x01";
        }, $t);

        // Keep author-written inline HTML (markdown allows it), escape the rest.
        $t = $this->escapeButKeepHtml($t);

        // Images before links (they share the bracket syntax).
        $t = (string) preg_replace_callback('/!\[([^\]]*)\]\(([^)\s]+)(?:\s+(?:&quot;|")(.*?)(?:&quot;|"))?\)/', function (array $m): string {
            $alt = htmlspecialchars($m[1], ENT_QUOTES);
            $src = $this->safeUrl($m[2]);
            $ttl = !empty($m[3]) ? ' title="' . $m[3] . '"' : '';
            return '<img src="' . $src . '" alt="' . $alt . '"' . $ttl . ' loading="lazy">';
        }, $t) ?? $t;

        $t = (string) preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)(?:\s+(?:&quot;|")(.*?)(?:&quot;|"))?\)/', function (array $m): string {
            $url = $this->safeUrl($m[2]);
            $ttl = !empty($m[3]) ? ' title="' . $m[3] . '"' : '';
            $ext = preg_match('#^https?://#i', $m[2]) ? ' rel="noopener"' : '';
            return '<a href="' . $url . '"' . $ttl . $ext . '>' . $m[1] . '</a>';
        }, $t) ?? $t;

        // Autolinks
        $t = (string) preg_replace_callback('/&lt;(https?:\/\/[^\s&]+)&gt;/', function (array $m): string {
            $u = $this->safeUrl(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
            return '<a href="' . $u . '" rel="noopener">' . $u . '</a>';
        }, $t) ?? $t;

        // Emphasis — longest markers first.
        $t = (string) preg_replace('/\*\*\*(.+?)\*\*\*/s', '<strong><em>$1</em></strong>', $t);
        $t = (string) preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $t);
        $t = (string) preg_replace('/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/s', '<em>$1</em>', $t);
        $t = (string) preg_replace('/__(.+?)__/s', '<strong>$1</strong>', $t);
        $t = (string) preg_replace('/(?<![\w_])_(?!\s)(.+?)(?<!\s)_(?![\w_])/s', '<em>$1</em>', $t);
        $t = (string) preg_replace('/~~(.+?)~~/s', '<del>$1</del>', $t);

        // Hard break: two trailing spaces, then soft-wrap newlines.
        $t = (string) preg_replace('/ {2,}\n/', "<br>\n", $t);

        foreach ($spans as $i => $c) $t = str_replace("\x01C{$i}\x01", $c, $t);
        return $t;
    }

    /**
     * Escape text but leave author-written HTML tags intact — markdown permits
     * inline HTML, and post authors are trusted (edit_posts capability).
     */
    private function escapeButKeepHtml(string $t): string
    {
        $parts = preg_split('/(<\/?[a-zA-Z][\w-]*(?:\s[^<>]*)?\/?>)/', $t, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) return htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $out = '';
        foreach ($parts as $n => $p) {
            $out .= ($n % 2 === 1) ? $p : htmlspecialchars($p, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        return $out;
    }

    /** Block javascript:/data: URLs in links and images. */
    private function safeUrl(string $u): string
    {
        $trim = strtolower(trim(html_entity_decode($u, ENT_QUOTES, 'UTF-8')));
        if (preg_match('/^\s*(javascript|data|vbscript):/i', $trim)) return '#';
        return htmlspecialchars($u, ENT_QUOTES);
    }
}
