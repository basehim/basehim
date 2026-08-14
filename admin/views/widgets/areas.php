<?php $this->extend('layouts.app'); ?>
<?php $this->section('content'); ?>
<?php $base = defined('BASEHIM_BASE') ? rtrim((string) BASEHIM_BASE, '/') : ''; ?>

<div class="mb-5">
    <h2 class="text-xl font-semibold text-slate-900">Widget Areas</h2>
    <p class="text-sm text-slate-500">Place and order widgets inside the areas your active theme provides. Themes render these with <code class="px-1 py-0.5 bg-slate-100 rounded">widget_area('key')</code>.</p>
</div>

<!-- Sub-nav -->
<div class="flex items-center gap-1 mb-5 text-sm">
    <a href="<?= $base ?>/admin/widgets" class="px-3 py-1.5 rounded-lg text-slate-500 hover:bg-slate-100">Registered widgets</a>
    <a href="<?= $base ?>/admin/widgets/areas" class="px-3 py-1.5 rounded-lg bg-slate-900 text-white font-medium">Widget areas</a>
</div>

<?php if (empty($areas)): ?>
<div class="bg-white rounded-xl border border-slate-200 p-10 text-center">
    <?= icon('view-columns', 'w-10 h-10 text-slate-300 mb-3 block mx-auto') ?>
    <h3 class="text-slate-700 font-medium mb-1">Your theme declares no widget areas</h3>
    <p class="text-sm text-slate-500 max-w-lg mx-auto">Add a <code class="px-1 py-0.5 bg-slate-100 rounded">"widget_areas"</code> map to the theme's <code class="px-1 py-0.5 bg-slate-100 rounded">theme.json</code>, or register one from a app with <code class="px-1 py-0.5 bg-slate-100 rounded">WidgetAreaRegistry::register()</code>.</p>
</div>
<?php else: ?>

<?php if (empty($available)): ?>
<div class="mb-5 bg-amber-50 border border-amber-200 text-amber-800 rounded-lg px-4 py-3 text-sm">
    No frontend widgets are registered yet, so there's nothing to place. Activate a app or theme that provides widgets.
</div>
<?php endif; ?>

<div class="space-y-5">
    <?php foreach ($areas as $area): ?>
    <?php $items = $assignments[$area['key']] ?? []; $count = is_array($items) ? count($items) : 0; ?>
    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100 flex items-start justify-between gap-4">
            <div>
                <h3 class="text-sm font-semibold text-slate-900"><?= htmlspecialchars($area['name']) ?>
                    <span class="ml-1 text-[11px] font-mono text-slate-400"><?= htmlspecialchars($area['key']) ?></span>
                </h3>
                <?php if (!empty($area['description'])): ?>
                    <p class="text-xs text-slate-500 mt-0.5"><?= htmlspecialchars($area['description']) ?></p>
                <?php endif; ?>
            </div>
            <span class="text-[11px] text-slate-400 shrink-0"><?= $count ?> widget<?= $count === 1 ? '' : 's' ?></span>
        </div>

        <div class="p-5 space-y-3">
            <?php if ($count === 0): ?>
                <p class="text-sm text-slate-400 italic">No widgets here yet.</p>
            <?php else: ?>
                <?php foreach (array_values($items) as $idx => $inst): ?>
                <?php
                    $def = $registry->get((string) ($inst['widget'] ?? ''));
                    $iid = (string) ($inst['id'] ?? '');
                    $settings = is_array($inst['settings'] ?? null) ? $inst['settings'] : [];
                    $fields = $def['fields'] ?? [];
                ?>
                <div class="border border-slate-200 rounded-lg">
                    <div class="flex items-center gap-2 px-3 py-2 bg-slate-50 border-b border-slate-100">
                        <?= icon($def['icon'] ?? 'puzzle-piece', 'w-4 h-4 text-slate-400') ?>
                        <span class="text-sm font-medium text-slate-800 flex-1 truncate">
                            <?= htmlspecialchars($def['title'] ?? ($inst['widget'] ?? 'Unknown widget')) ?>
                            <?php if (!$def): ?><span class="text-[11px] text-red-500">(missing: <?= htmlspecialchars((string) ($inst['widget'] ?? '')) ?>)</span><?php endif; ?>
                        </span>
                        <!-- move up -->
                        <form method="POST" action="<?= $base ?>/admin/widgets/areas/<?= urlencode($area['key']) ?>/<?= urlencode($iid) ?>/move" class="inline">
                            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                            <input type="hidden" name="dir" value="up">
                            <button class="p-1.5 rounded text-slate-400 hover:text-slate-700 hover:bg-slate-100 disabled:opacity-30" title="Move up" <?= $idx === 0 ? 'disabled' : '' ?>><?= icon('chevron-up', 'w-4 h-4') ?></button>
                        </form>
                        <!-- move down -->
                        <form method="POST" action="<?= $base ?>/admin/widgets/areas/<?= urlencode($area['key']) ?>/<?= urlencode($iid) ?>/move" class="inline">
                            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                            <input type="hidden" name="dir" value="down">
                            <button class="p-1.5 rounded text-slate-400 hover:text-slate-700 hover:bg-slate-100 disabled:opacity-30" title="Move down" <?= $idx === $count - 1 ? 'disabled' : '' ?>><?= icon('chevron-down', 'w-4 h-4') ?></button>
                        </form>
                        <!-- remove -->
                        <form method="POST" action="<?= $base ?>/admin/widgets/areas/<?= urlencode($area['key']) ?>/<?= urlencode($iid) ?>/remove" class="inline" onsubmit="return confirm('Remove this widget from the area?')">
                            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                            <button class="p-1.5 rounded text-slate-400 hover:text-red-600 hover:bg-red-50" title="Remove"><?= icon('trash', 'w-4 h-4') ?></button>
                        </form>
                    </div>
                    <?php if ($def && !empty($fields)): ?>
                    <form method="POST" action="<?= $base ?>/admin/widgets/areas/<?= urlencode($area['key']) ?>/<?= urlencode($iid) ?>" class="p-3 space-y-2.5 text-sm">
                        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                        <?php foreach ($fields as $f): ?>
                        <?php
                            $fk = (string) ($f['key'] ?? '');
                            if ($fk === '') continue;
                            $flabel = (string) ($f['label'] ?? $fk);
                            $ftype = (string) ($f['type'] ?? 'text');
                            $val = $settings[$fk] ?? ($f['default'] ?? '');
                            $inputName = 'settings[' . $fk . ']';
                        ?>
                        <div>
                            <label class="block text-xs text-slate-500 mb-1"><?= htmlspecialchars($flabel) ?></label>
                            <?php if ($ftype === 'textarea'): ?>
                                <textarea name="<?= htmlspecialchars($inputName) ?>" rows="3" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none"><?= htmlspecialchars((string) $val) ?></textarea>
                            <?php elseif ($ftype === 'checkbox'): ?>
                                <label class="inline-flex items-center gap-2">
                                    <input type="hidden" name="<?= htmlspecialchars($inputName) ?>" value="0">
                                    <input type="checkbox" name="<?= htmlspecialchars($inputName) ?>" value="1" <?= !empty($val) ? 'checked' : '' ?> class="rounded border-slate-300">
                                    <span class="text-xs text-slate-500">Enabled</span>
                                </label>
                            <?php elseif ($ftype === 'select' && !empty($f['options']) && is_array($f['options'])): ?>
                                <select name="<?= htmlspecialchars($inputName) ?>" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
                                    <?php foreach ($f['options'] as $ov => $ol): ?>
                                        <option value="<?= htmlspecialchars((string) $ov) ?>" <?= (string) $val === (string) $ov ? 'selected' : '' ?>><?= htmlspecialchars((string) $ol) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else: ?>
                                <input type="<?= $ftype === 'number' ? 'number' : 'text' ?>" name="<?= htmlspecialchars($inputName) ?>" value="<?= htmlspecialchars((string) $val) ?>" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                        <button type="submit" class="px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-xs font-medium">Save</button>
                    </form>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <!-- Add widget -->
            <?php if (!empty($available)): ?>
            <form method="POST" action="<?= $base ?>/admin/widgets/areas/<?= urlencode($area['key']) ?>/add" class="flex items-center gap-2 pt-1">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                <select name="widget" class="flex-1 px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-200 focus:border-blue-500 outline-none">
                    <?php foreach ($available as $w): ?>
                        <option value="<?= htmlspecialchars($w['key']) ?>"><?= htmlspecialchars($w['title']) ?><?= $w['source'] ? ' — ' . htmlspecialchars($w['source']) : '' ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg text-sm font-medium flex items-center gap-1.5"><?= icon('plus', 'w-4 h-4') ?>Add</button>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php $this->endSection(); ?>
