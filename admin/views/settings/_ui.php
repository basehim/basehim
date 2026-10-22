<?php
/**
 * Settings screens: shared building blocks (1.2.82).
 *
 * Every Settings page is made of the same parts, so they look and behave the
 * same on a desktop and like a phone's own Settings app on a phone (styles:
 * "Settings" in admin/assets/css/src/tailwind.css; docs/ADMIN-UI.md).
 *
 *   bh_set_card_open($title, $sub, $o) … bh_set_card_close()
 *       A section: the core card with its header, its body a list of rows.
 *   bh_set_row($label, $controlHtml, $helpHtml, $o)
 *       A setting: its name and help on the left, the control on the right.
 *   bh_set_toggle($name, $on, $label, $helpHtml, $o)
 *       An on/off setting as a switch row.
 *   bh_switch($name, $on, $o)
 *       The switch alone.
 *   bh_set_save($label, $note)
 *       The Save bar at the foot of a form.
 *
 * Help text is HTML written by core (it may hold <code> or a link); labels
 * are escaped. Loaded with require_once from each settings view.
 */

declare(strict_types=1);

if (!function_exists('bh_set_e')) {
    function bh_set_e(mixed $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }

    /** A stable id for a field name ("jsonld_website" → "set-jsonld-website"). */
    function bh_set_id(string $name): string
    {
        return 'set-' . trim((string) preg_replace('/[^a-z0-9]+/i', '-', strtolower($name)), '-');
    }

    /**
     * The switch: a checkbox with role="switch" over its track.
     *
     * $o: id, value ('1'), hidden0 (true: also post 0 when off, as the page
     * always did), disabled, describedby, label (aria-label when the switch
     * has no visible label), attrs (extra attributes, raw).
     */
    function bh_switch(string $name, bool $on, array $o = []): string
    {
        $id = (string) ($o['id'] ?? bh_set_id($name));
        $h = '<span class="bh-switch">';
        if ($o['hidden0'] ?? true) $h .= '<input type="hidden" name="' . bh_set_e($name) . '" value="0">';
        $h .= '<input type="checkbox" role="switch" class="bh-switch__input" id="' . bh_set_e($id) . '" name="' . bh_set_e($name) . '"'
            . ' value="' . bh_set_e($o['value'] ?? '1') . '"' . ($on ? ' checked' : '') . (!empty($o['disabled']) ? ' disabled' : '')
            . (!empty($o['describedby']) ? ' aria-describedby="' . bh_set_e($o['describedby']) . '"' : '')
            . (!empty($o['label']) ? ' aria-label="' . bh_set_e($o['label']) . '"' : '')
            . (!empty($o['attrs']) ? ' ' . $o['attrs'] : '') . '>';
        return $h . '<span class="bh-switch__track" aria-hidden="true"></span></span>';
    }

    /** An on/off setting: name and help, the switch at the right. $o as bh_switch, plus sub, rowattrs. */
    function bh_set_toggle(string $name, bool $on, string $label, string $helpHtml = '', array $o = []): string
    {
        $id = (string) ($o['id'] ?? bh_set_id($name));
        $helpId = $id . '-help';
        if ($helpHtml !== '') $o['describedby'] = $helpId;
        $o['id'] = $id;
        return '<div class="bh-set-row bh-set-row--switch' . (!empty($o['sub']) ? ' bh-set-row--sub' : '') . '"'
            . (!empty($o['rowattrs']) ? ' ' . $o['rowattrs'] : '') . '>'
            . '<div class="bh-set-row__text"><label class="bh-set-row__label" for="' . bh_set_e($id) . '">' . bh_set_e($label) . '</label>'
            . ($helpHtml !== '' ? '<p class="bh-set-row__help" id="' . bh_set_e($helpId) . '">' . $helpHtml . '</p>' : '')
            . '</div>' . bh_switch($name, $on, $o) . '</div>';
    }

    /**
     * A setting with any control. $o: for (the control's id, so the name is
     * its label), wide (the control under the name, full width), sub,
     * rowattrs (raw attributes for the row), labelHtml (a label that is
     * already HTML).
     */
    function bh_set_row(string $label, string $controlHtml, string $helpHtml = '', array $o = []): string
    {
        $for = (string) ($o['for'] ?? '');
        $name = isset($o['labelHtml']) ? (string) $o['labelHtml'] : bh_set_e($label);
        $tag = $for !== '' ? '<label class="bh-set-row__label" for="' . bh_set_e($for) . '">' . $name . '</label>'
                           : '<span class="bh-set-row__label">' . $name . '</span>';
        $cls = 'bh-set-row' . (!empty($o['wide']) ? ' bh-set-row--wide' : '') . (!empty($o['sub']) ? ' bh-set-row--sub' : '');
        return '<div class="' . $cls . '"' . (!empty($o['rowattrs']) ? ' ' . $o['rowattrs'] : '') . '>'
            . '<div class="bh-set-row__text">' . $tag
            . ($helpHtml !== '' ? '<p class="bh-set-row__help">' . $helpHtml . '</p>' : '') . '</div>'
            . '<div class="bh-set-row__control">' . $controlHtml . '</div></div>';
    }

    /** A section card. $o: id, intro (HTML under the header), action (HTML at the header's right), tag ('section'). */
    function bh_set_card_open(string $title, string $sub = '', array $o = []): string
    {
        $h = '<section class="bh-card bh-set-card"' . (!empty($o['id']) ? ' id="' . bh_set_e($o['id']) . '"' : '')
            . (!empty($o['attrs']) ? ' ' . $o['attrs'] : '') . '>'
            . '<header class="bh-card__head"><div class="min-w-0"><h2 class="bh-card__title">' . bh_set_e($title) . '</h2>'
            . ($sub !== '' ? '<p class="bh-card__sub">' . $sub . '</p>' : '') . '</div>'
            . (!empty($o['action']) ? '<div class="bh-set-card__head-action">' . $o['action'] . '</div>' : '')
            . '</header><div class="bh-set-card__body">';
        if (!empty($o['intro'])) $h .= '<div class="bh-set-card__intro">' . $o['intro'] . '</div>';
        return $h;
    }

    function bh_set_card_close(): string
    {
        return '</div></section>';
    }

    /** The Save bar. */
    function bh_set_save(string $label = 'Save changes', string $note = ''): string
    {
        return '<div class="bh-set-save">'
            . ($note !== '' ? '<p class="bh-set-save__note">' . $note . '</p>' : '')
            . '<button type="submit" class="bh-btn bh-btn--primary">' . bh_set_e($label) . '</button></div>';
    }

    /** <select> options from [value => label], with $current selected. */
    function bh_set_options(array $options, mixed $current): string
    {
        $h = '';
        foreach ($options as $v => $l) {
            $h .= '<option value="' . bh_set_e($v) . '"' . ((string) $v === (string) $current ? ' selected' : '') . '>' . bh_set_e($l) . '</option>';
        }
        return $h;
    }
}
