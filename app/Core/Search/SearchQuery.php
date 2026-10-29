<?php

declare(strict_types=1);

namespace App\Core\Search;

/**
 * What someone typed into a search box, parsed once (BH Search, 1.2.92).
 *
 * Syntax, the same everywhere (admin tables, the media library, site search,
 * apps):
 *
 *     digitalocean pricing     every word must match, in any order
 *     "per-second billing"     an exact phrase
 *     -draft                   leave out anything with this word
 *
 * Words are split at spaces and at the separators file names and slugs use
 * (- _ . / and the like), so "snipaste 2026" finds "Snipaste_2026-10-27" and
 * "hetzner-pricing" finds "Hetzner pricing table". Matching ignores case. A
 * word matches the start of a longer word ("price" finds "prices"); engines
 * that can also match inside words (LIKE) do.
 *
 * Text made only of punctuation ("%", "--") has no words and matches nothing,
 * rather than everything.
 */
final class SearchQuery
{
    /** Longest query kept, in characters. */
    public const MAX_LENGTH = 200;

    /** Words used at most; the rest are ignored. */
    public const MAX_TERMS = 12;

    /** @var list<string> lower-cased words that must match */
    private array $terms = [];

    /** @var list<list<string>> phrases, each a list of lower-cased words */
    private array $phrases = [];

    /** @var list<string> lower-cased words that must not match */
    private array $excluded = [];

    private function __construct(private string $raw) {}

    public static function parse(string|self $input): self
    {
        if ($input instanceof self) return $input;
        $raw = trim(mb_substr(self::clean($input), 0, self::MAX_LENGTH, 'UTF-8'));
        $q = new self($raw);
        if ($raw === '') return $q;

        $budget = self::MAX_TERMS;
        // Quoted phrases first, then the rest word by word.
        $rest = preg_replace_callback('/(^|\s)(-?)"([^"]*)"/u', function (array $m) use ($q, &$budget): string {
            $words = self::split($m[3]);
            if ($words === [] || $budget <= 0) return ' ';
            if ($m[2] === '-') {
                foreach ($words as $w) if ($budget-- > 0 && !in_array($w, $q->excluded, true)) $q->excluded[] = $w;
            } elseif (count($words) === 1) {
                if ($budget-- > 0 && !in_array($words[0], $q->terms, true)) $q->terms[] = $words[0];
            } else {
                $words = array_slice($words, 0, max(1, $budget));
                $budget -= count($words);
                $q->phrases[] = $words;
            }
            return ' ';
        }, $raw) ?? $raw;
        $rest = str_replace('"', ' ', $rest);

        foreach (preg_split('/\s+/u', $rest, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $chunk) {
            $negative = $chunk[0] === '-' && strlen($chunk) > 1;
            foreach (self::split($negative ? substr($chunk, 1) : $chunk) as $w) {
                if ($budget <= 0) break 2;
                $list = $negative ? 'excluded' : 'terms';
                if (in_array($w, $q->{$list}, true)) continue;
                $q->{$list}[] = $w;
                $budget--;
            }
        }
        return $q;
    }

    /**
     * Lower-cased words of a text, split at anything that isn't a letter, a
     * digit or a combining mark. Used for queries and, by engines, for text.
     *
     * @return list<string>
     */
    public static function split(string $text): array
    {
        $parts = preg_split('/[^\p{L}\p{N}\p{M}]+/u', self::clean($text), -1, PREG_SPLIT_NO_EMPTY);
        if ($parts === false) return [];
        $out = [];
        foreach ($parts as $p) {
            $w = mb_strtolower($p, 'UTF-8');
            if (!in_array($w, $out, true)) $out[] = $w;
        }
        return $out;
    }

    /** Valid UTF-8 without control characters. */
    private static function clean(string $s): string
    {
        if (!mb_check_encoding($s, 'UTF-8')) $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
        return preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s) ?? '';
    }

    /** The text as typed (trimmed and length-capped). */
    public function raw(): string { return $this->raw; }

    /** @return list<string> */
    public function terms(): array { return $this->terms; }

    /** @return list<list<string>> */
    public function phrases(): array { return $this->phrases; }

    /** @return list<string> */
    public function excluded(): array { return $this->excluded; }

    /** Every positive word: terms and the words of phrases. @return list<string> */
    public function words(): array
    {
        $all = $this->terms;
        foreach ($this->phrases as $p) foreach ($p as $w) if (!in_array($w, $all, true)) $all[] = $w;
        return $all;
    }

    /** Nothing was typed. */
    public function isBlank(): bool { return $this->raw === ''; }

    /** Something was typed but nothing in it can match (only punctuation, or only exclusions). */
    public function matchesNothing(): bool
    {
        return $this->raw !== '' && $this->terms === [] && $this->phrases === [];
    }

    /**
     * Whether a text matches, for sources searched in PHP (in-memory lists
     * such as installed apps). Words match anywhere inside words.
     */
    public function matches(string $text): bool
    {
        if ($this->isBlank()) return true;
        if ($this->matchesNothing()) return false;
        $hay = ' ' . implode(' ', self::split($text)) . ' ';
        $flat = str_replace(' ', '', $hay);
        foreach ($this->terms as $t) {
            if (!str_contains($hay, $t) && !str_contains($flat, $t)) return false;
        }
        foreach ($this->phrases as $p) {
            if (!str_contains($hay, ' ' . implode(' ', $p))) return false;
        }
        foreach ($this->excluded as $t) {
            if (preg_match('/ ' . preg_quote($t, '/') . '/u', $hay)) return false;
        }
        return true;
    }

    /**
     * Light English stemming for site search: "pricing" also finds "price"
     * and "priced". Only for ASCII words, only when the stem keeps at least
     * four letters, so short words and other languages are left as typed.
     */
    public static function stem(string $word): string
    {
        if (!preg_match('/^[a-z]{6,}$/', $word)) return $word;
        foreach (['ations', 'ation', 'ings', 'ing', 'ies', 'ied', 'ers', 'er', 'ed', 'es', 'ly', 's'] as $suffix) {
            if (str_ends_with($word, $suffix) && strlen($word) - strlen($suffix) >= 4) {
                return substr($word, 0, -strlen($suffix));
            }
        }
        return $word;
    }

    /**
     * A snippet of $text around the first match, as safe HTML with matches in
     * <mark>. Without a match, the start of the text.
     */
    public function snippet(string $text, int $length = 180): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if ($text === '') return '';
        $words = $this->words();
        $pos = null;
        foreach ($words as $w) {
            $p = mb_stripos($text, $w, 0, 'UTF-8');
            if ($p !== false && ($pos === null || $p < $pos)) $pos = $p;
        }
        $start = 0;
        if ($pos !== null && $pos > (int) ($length / 3)) {
            $start = $pos - (int) ($length / 3);
            $space = mb_strpos($text, ' ', $start, 'UTF-8');
            if ($space !== false && $space < $pos) $start = $space + 1;
        }
        $cut = mb_substr($text, $start, $length, 'UTF-8');
        $more = mb_strlen($text, 'UTF-8') > $start + $length;
        return ($start > 0 ? '… ' : '') . $this->highlight($cut) . ($more ? ' …' : '');
    }

    /** $text escaped for HTML, with every matched word wrapped in <mark>. */
    public function highlight(string $text): string
    {
        $esc = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $words = array_filter($this->words(), fn($w) => $w !== '');
        if ($words === []) return $esc($text);
        usort($words, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        $re = '/(' . implode('|', array_map(fn($w) => preg_quote($w, '/'), $words)) . ')/iu';
        $parts = preg_split($re, $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) return $esc($text);
        $out = '';
        foreach ($parts as $i => $part) {
            $out .= $i % 2 === 1 ? '<mark>' . $esc($part) . '</mark>' : $esc($part);
        }
        return $out;
    }
}
