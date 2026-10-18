/*
 * Post editor: Categories and Tags (1.2.47; shared components since 1.2.62).
 *
 * Both are drawn by admin/assets/js/post-fields.js — the same components
 * Quick Edit uses in the Posts list — from the settings the server put on
 * their mount points (PostEditorService::categoriesField() / tagsField()).
 *
 *  - Categories: checkboxes named term_ids[], posted with the form.
 *  - Tags: on a saved post, adding or removing an existing tag is stored at
 *    once (posts.tag_add / posts.tag_remove); otherwise chips are kept in the
 *    form (hidden term_ids[] / new_tags[]) and saved with it. New names become
 *    tags when the post is published (PostEditorService::settleNewTags()).
 */
(function () {
    'use strict';
    var F = window.BasehimPostFields;
    if (!F) return;
    function cfgOf(el) { try { return JSON.parse(el.getAttribute('data-config') || '{}'); } catch (e) { return {}; } }
    function markDirty() { try { window.BasehimEditorDirty = true; } catch (e) {} }

    var catMount = document.querySelector('[data-bh-categories-mount]');
    if (catMount) {
        var cats = F.categories(catMount, Object.assign(cfgOf(catMount), { skin: 'editor' }));
        cats.onChange(markDirty);
    }

    var tagMount = document.querySelector('[data-bh-tags-mount]');
    if (!tagMount) return;
    var cfg = cfgOf(tagMount);
    cfg.immediate = true;          // the editor stores tag changes on a saved post at once
    cfg.idPrefix = 'bh-tags';
    cfg.skin = 'editor';
    var tags = F.tags(tagMount, cfg);
    tags.onChange(function () { if (!cfg.post_id) markDirty(); });

    // Save draft (AJAX) created or saved the post: from now on changes are
    // stored at once, and the chips show the server's view.
    document.addEventListener('bh:post-saved', function (e) {
        var d = e.detail || {};
        if (!d.id) return;
        cfg.post_id = d.id;
        tags.setPostId(d.id);
        tags.set(d.tags || [], d.pending_tags || []);
    });
})();
