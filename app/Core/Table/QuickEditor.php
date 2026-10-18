<?php

declare(strict_types=1);

namespace App\Core\Table;

/**
 * Quick Edit for a data table's rows (1.2.61).
 *
 * A table opts in by naming a class implementing this interface as its
 * `quick_edit` in TableRegistry::register(). The data table then offers a
 * Quick Edit row action, and the two generic AJAX actions
 * (content.quick_edit, content.quick_edit_save) hand the request here after
 * checking the table's own capability and admin area.
 *
 * The editor owns everything about its records: which fields exist for this
 * record and this user, their current values and options, validation, and
 * saving through the feature's existing service. The data table only renders
 * the fields it is given and re-renders the saved row with its normal columns.
 *
 * Field definitions returned by form() are plain arrays:
 *
 *   name      string   the key save() receives back
 *   type      string   text | slug | select | checklist | tags | number | toggle | date | time
 *   label     string
 *   value     mixed    the current value
 *   options   list     [{value, label}] for select and checklist
 *   required  bool
 *   max, min  int      length (text) or range (number)
 *   help      string   one line under the field
 *   wide      bool     takes the full row on wide screens
 *   show_when array    {field, in: [...]} — shown only while another field has one of these values
 *   confirm   array    {from: [...], to: [...], message} — ask before saving such a change
 *
 * They describe the form; they grant nothing. save() must re-check every
 * field it receives against what this user may change on this record.
 */
interface QuickEditor
{
    /**
     * The form for one record.
     *
     * @return array{id:int, title:string, version:string, fields:array<int,array>}
     * @throws \App\Core\Ajax\AjaxException when the record is missing or not this user's to edit
     */
    public function form(int $id, array $user): array;

    /**
     * Apply the changed values. Only fields present in $values change.
     *
     * @param array{version?:string, confirm?:bool} $options
     * @return array{row:array, version:string, notices:string[]}  `row` in the table provider's row shape
     * @throws \App\Core\Ajax\AjaxException with per-field messages on invalid input
     */
    public function save(int $id, array $values, array $user, array $options = []): array;
}
