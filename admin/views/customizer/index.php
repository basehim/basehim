<?php
/**
 * The Customizer.
 *
 * @var array  $sections   section key => ['label','description','options']
 * @var array  $values     "section.option" => current value
 * @var string $themeName
 * @var string $previewUrl
 * @var string $csrf
 */
$base = defined('BASEHIM_BASE') ? BASEHIM_BASE : '';
?>
<?php $this->extend('layouts.fullscreen'); ?>
<?php $this->section('content'); ?>
<div class="bh-cz" id="bh-cz">

    <!-- ── controls ────────────────────────────────────────────────────── -->
    <aside class="bh-cz__panel">
        <header class="bh-cz__head">
            <a href="<?= $base ?>/admin" class="bh-cz__back" aria-label="Leave the Customizer">
                <?= icon('arrow-left', 'w-5 h-5') ?>
            </a>
            <div class="bh-cz__headtext">
                <div class="bh-cz__title">Customize</div>
                <div class="bh-cz__theme"><?= htmlspecialchars($themeName) ?></div>
            </div>
            <button type="button" id="bh-cz-save" class="bh-cz__save" disabled>Save</button>
        </header>

        <div class="bh-cz__status" id="bh-cz-status" aria-live="polite"></div>

        <div class="bh-cz__sections">
            <?php foreach ($sections as $sKey => $section): ?>
                <section class="bh-cz__section" data-section="<?= htmlspecialchars($sKey) ?>">
                    <button type="button" class="bh-cz__sectionhead" aria-expanded="false">
                        <span><?= htmlspecialchars($section['label']) ?></span>
                        <?= icon('chevron-down', 'w-4 h-4 bh-cz__chev') ?>
                    </button>
                    <div class="bh-cz__sectionbody" hidden>
                        <?php if (!empty($section['description'])): ?>
                            <p class="bh-cz__sectiondesc"><?= htmlspecialchars($section['description']) ?></p>
                        <?php endif; ?>

                        <?php foreach ($section['options'] as $oKey => $opt):
                            $path = $sKey . '.' . $oKey;
                            $val  = $values[$path] ?? ($opt['default'] ?? '');
                            $id   = 'cz-' . preg_replace('/[^a-z0-9]/i', '-', $path);
                        ?>
                        <div class="bh-cz__field" data-path="<?= htmlspecialchars($path) ?>"
                             data-type="<?= htmlspecialchars($opt['type']) ?>"
                             data-preview="<?= htmlspecialchars($opt['preview'] ?? 'reload') ?>"
                             <?php if (!empty($opt['css_var'])): ?>data-var="<?= htmlspecialchars($opt['css_var']) ?>"<?php endif; ?>
                             <?php if (!empty($opt['unit'])): ?>data-unit="<?= htmlspecialchars($opt['unit']) ?>"<?php endif; ?>>

                            <label class="bh-cz__label" for="<?= $id ?>"><?= htmlspecialchars($opt['label']) ?></label>

                            <?php switch ($opt['type']):
                                case 'color': ?>
                                    <div class="bh-cz__color">
                                        <input type="color" id="<?= $id ?>" value="<?= htmlspecialchars($val ?: '#000000') ?>" data-input>
                                        <input type="text" class="bh-cz__hex" value="<?= htmlspecialchars((string) $val) ?>"
                                               placeholder="#000000" spellcheck="false" data-hex>
                                    </div>
                                <?php break;

                                case 'textarea': ?>
                                    <textarea id="<?= $id ?>" rows="<?= (int) ($opt['rows'] ?? 5) ?>" data-input
                                        class="bh-cz__input<?= !empty($opt['mono']) ? ' bh-cz__input--mono' : '' ?>"
                                        spellcheck="false"><?= htmlspecialchars((string) $val) ?></textarea>
                                <?php break;

                                case 'select': ?>
                                    <select id="<?= $id ?>" class="bh-cz__input" data-input>
                                        <?php foreach (($opt['choices'] ?? []) as $cv => $cl): ?>
                                            <option value="<?= htmlspecialchars((string) $cv) ?>"
                                                <?= (string) $val === (string) $cv ? 'selected' : '' ?>>
                                                <?= htmlspecialchars((string) $cl) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php break;

                                case 'toggle': ?>
                                    <label class="bh-cz__toggle">
                                        <input type="checkbox" id="<?= $id ?>" <?= $val ? 'checked' : '' ?> data-input>
                                        <span>Enabled</span>
                                    </label>
                                <?php break;

                                case 'range': ?>
                                    <div class="bh-cz__range">
                                        <input type="range" id="<?= $id ?>" data-input
                                               min="<?= (int) ($opt['min'] ?? 0) ?>"
                                               max="<?= (int) ($opt['max'] ?? 100) ?>"
                                               step="<?= htmlspecialchars((string) ($opt['step'] ?? 1)) ?>"
                                               value="<?= htmlspecialchars((string) $val) ?>">
                                        <output data-output><?= htmlspecialchars((string) $val) ?><?= htmlspecialchars((string) ($opt['unit'] ?? '')) ?></output>
                                    </div>
                                <?php break;

                                case 'number': ?>
                                    <input type="number" id="<?= $id ?>" class="bh-cz__input" data-input
                                           value="<?= htmlspecialchars((string) $val) ?>"
                                           <?php if (isset($opt['min'])): ?>min="<?= (int) $opt['min'] ?>"<?php endif; ?>
                                           <?php if (isset($opt['max'])): ?>max="<?= (int) $opt['max'] ?>"<?php endif; ?>>
                                <?php break;

                                case 'image': ?>
                                    <?php /* The shared media field: core binds it, and the
                                            element fires bh:media when the value changes. No
                                            picker code lives in this screen. */ ?>
                                    <div class="bh-cz__image" data-bh-media>
                                        <div class="bh-cz__thumb<?= $val ? '' : ' is-empty' ?>" data-bh-media-preview>
                                            <?php if ($val): ?><img src="<?= htmlspecialchars((string) $val) ?>" alt=""><?php endif; ?>
                                        </div>
                                        <input type="hidden" id="<?= $id ?>" value="<?= htmlspecialchars((string) $val) ?>"
                                               data-input data-bh-media-value>
                                        <div class="bh-cz__imagebtns">
                                            <button type="button" class="bh-cz__btn" data-bh-media-pick>Choose</button>
                                            <button type="button" class="bh-cz__btn bh-cz__btn--quiet" data-bh-media-clear
                                                <?= $val ? '' : 'hidden' ?>>Remove</button>
                                        </div>
                                    </div>
                                <?php break;

                                default: ?>
                                    <input type="text" id="<?= $id ?>" class="bh-cz__input" data-input
                                           value="<?= htmlspecialchars((string) $val) ?>"
                                           <?php if (!empty($opt['placeholder'])): ?>placeholder="<?= htmlspecialchars($opt['placeholder']) ?>"<?php endif; ?>>
                            <?php endswitch; ?>

                            <?php if (!empty($opt['help'])): ?>
                                <p class="bh-cz__help"><?= htmlspecialchars($opt['help']) ?></p>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>
    </aside>

    <!-- ── preview ─────────────────────────────────────────────────────── -->
    <main class="bh-cz__preview">
        <div class="bh-cz__toolbar">
            <div class="bh-cz__devices" role="group" aria-label="Preview width">
                <button type="button" class="is-on" data-device="full" title="Full width"><?= icon('computer-desktop', 'w-4 h-4') ?></button>
                <button type="button" data-device="tablet" title="Tablet"><?= icon('device-tablet', 'w-4 h-4') ?></button>
                <button type="button" data-device="mobile" title="Phone"><?= icon('device-phone-mobile', 'w-4 h-4') ?></button>
            </div>
            <button type="button" class="bh-cz__btn bh-cz__btn--quiet" id="bh-cz-refresh" title="Reload the preview">
                <?= icon('arrow-path', 'w-4 h-4') ?>
            </button>
        </div>
        <div class="bh-cz__frame" data-device="full">
            <iframe id="bh-cz-frame" src="<?= htmlspecialchars($previewUrl) ?>"
                    title="Preview of the site"></iframe>
        </div>
    </main>
</div>

<link rel="stylesheet" href="<?= $base ?>/admin/assets/css/customizer.css?v=<?= urlencode(BASEHIM_VERSION) ?>">

<?php $this->endSection(); ?>

<?php $this->section('scripts'); ?>
<script>window.BH_CZ = { csrf: <?= json_encode($csrf) ?>, base: <?= json_encode($base) ?> };</script>
<script src="<?= $base ?>/admin/assets/js/customizer.js?v=<?= urlencode(BASEHIM_VERSION) ?>"></script>
<?php $this->endSection(); ?>
