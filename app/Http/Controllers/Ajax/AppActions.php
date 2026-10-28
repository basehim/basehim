<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ajax;

use App\Core\Ajax\AjaxContext;
use App\Core\Ajax\AjaxException;
use App\Core\Application;
use App\Services\ActivityLogService;
use App\Services\AppService;
use App\Services\PermissionBroker;

/**
 * The Apps screen's gateway actions (1.2.85), /admin/apps:
 *
 *   apps.consent       GET   what an installed app asks for, for the
 *                            permission window
 *   apps.consent_save  POST  record the permissions; optionally activate
 *   apps.bulk          POST  activate, deactivate, check again or delete
 *                            one or more apps (the table's actions)
 *
 * They stand in for the screen's forms (POST /admin/apps/{slug}/activate …),
 * which remain for browsers without JavaScript.
 */
final class AppActions
{
    public function consent(AjaxContext $ctx): array
    {
        $slug = (string) $ctx->input('slug');
        $apps = $this->apps();
        $row = $apps->find($slug);
        if (!$row) throw AjaxException::notFound("That app isn't installed.");
        $broker = $this->broker();
        return [
            'slug'          => $slug,
            'name'          => (string) ($row['name'] ?? $slug),
            'active'        => ($row['status'] ?? '') === 'active',
            'consented'     => $broker->hasConsented($slug),
            'needs_consent' => $apps->needsConsent($slug),
            'permissions'   => $broker->describeAll($broker->declared($slug)),
            'unknown'       => $broker->unknownDeclared($slug),
            'granted'       => $broker->grantedFor($slug),
        ] + $apps->consentContext($slug);
    }

    public function consentSave(AjaxContext $ctx): array
    {
        $slug = (string) $ctx->input('slug');
        $apps = $this->apps();
        if (!$apps->find($slug)) throw AjaxException::notFound("That app isn't installed.");
        $broker = $this->broker();
        $broker->grant($slug, array_map('strval', (array) $ctx->input('permissions', [])));
        $ctx->log('Permissions saved', ['slug' => $slug]);

        $active = ($apps->find($slug)['status'] ?? '') === 'active';
        if ($ctx->input('activate') && !$active) {
            $this->activateOrThrow($apps, $slug);
            $active = true;
        }
        return ['active' => $active, 'withheld_count' => count($broker->withheld($slug))];
    }

    /**
     * One action over several apps. Each app is handled on its own and
     * reported in `results`; one failing doesn't stop the rest.
     */
    public function bulk(AjaxContext $ctx): array
    {
        $action = (string) $ctx->input('action');
        $apps = $this->apps();
        $bySlug = [];
        foreach ($apps->installed() as $row) $bySlug[(int) $row['id']] = (string) $row['slug'];

        $ids = array_values(array_unique(array_map('intval', (array) $ctx->input('ids', []))));
        $selected = array_values(array_filter(array_map(fn($id) => $bySlug[$id] ?? null, $ids)));
        if ($action === 'deactivate' || $action === 'delete') {
            // Dependents first, then what they depend on (1.2.90, BH-059).
            $needed = [];
            foreach ($selected as $s) $needed[$s] = count(array_intersect(array_keys($apps->activeDependents($s)), $selected));
            usort($ids, fn($a, $b) => ($needed[$bySlug[$a] ?? ''] ?? 0) <=> ($needed[$bySlug[$b] ?? ''] ?? 0));
        }
        $results = [];
        foreach ($ids as $id) {
            $slug = $bySlug[$id] ?? null;
            if ($slug === null) { $results[] = ['id' => $id, 'ok' => false, 'message' => 'Not installed.']; continue; }
            try {
                $msg = match ($action) {
                    'activate'   => $this->activateOne($apps, $slug),
                    'deactivate' => $this->deactivateOne($apps, $slug, $selected),
                    'retry'      => $this->retry($apps, $slug),
                    'delete'     => $this->deleteOne($apps, $slug, $ctx, $selected),
                };
                $results[] = ['id' => $id, 'slug' => $slug, 'ok' => true, 'message' => $msg];
            } catch (NeedsConsent) {
                $results[] = ['id' => $id, 'slug' => $slug, 'ok' => false, 'needs_consent' => true,
                              'message' => 'Needs your approval first: open its Permissions.'];
            } catch (\Throwable $e) {
                $results[] = ['id' => $id, 'slug' => $slug, 'ok' => false, 'message' => $e->getMessage()];
            }
        }
        $ctx->log('Bulk ' . $action, ['count' => count($results)]);
        $done = count(array_filter($results, fn($r) => $r['ok']));
        return ['action' => $action, 'done' => $done, 'failed' => count($results) - $done, 'results' => $results];
    }

    private function activateOne(AppService $apps, string $slug): string
    {
        if ($apps->filesMissing($slug)) throw new \RuntimeException('Its files are missing.');
        if (($apps->find($slug)['status'] ?? '') === 'active') return 'Already active.';
        if ($apps->needsConsent($slug)) throw new NeedsConsent();
        $this->activateOrThrow($apps, $slug);
        return 'Activated.';
    }

    private function activateOrThrow(AppService $apps, string $slug): void
    {
        try {
            $ok = $apps->activate($slug);
        } catch (\Throwable $e) {
            throw new AjaxException('ACTIVATION_FAILED', 'Activation failed: ' . $e->getMessage(), 422);
        }
        if (!$ok) {
            $problems = $apps->lastRequirementProblems();
            throw new AjaxException('ACTIVATION_FAILED', $problems
                ? 'It can\'t be turned on yet: ' . implode(' ', $problems)
                : 'It could not be turned on.', 422);
        }
    }

    private function retry(AppService $apps, string $slug): string
    {
        $result = $apps->validate($slug);
        if (is_array($result) && empty($result['ok']) && !empty($result['errors'])) {
            throw new \RuntimeException((string) ($result['errors'][0]['message'] ?? 'It still doesn\'t pass the checks.'));
        }
        return 'Its code passes the checks now.';
    }

    /** @param string[] $selected every app in this bulk action */
    private function deactivateOne(AppService $apps, string $slug, array $selected): string
    {
        $apps->assertNotNeeded($slug, 'switch off', $selected);
        if (!$apps->deactivate($slug)) throw new \RuntimeException('It could not be deactivated.');
        return 'Deactivated.';
    }

    private function deleteOne(AppService $apps, string $slug, AjaxContext $ctx, array $selected = []): string
    {
        $apps->assertNotNeeded($slug, 'delete', $selected);
        $missing = $apps->filesMissing($slug);
        $ok = $missing ? $apps->uninstall($slug) : $apps->delete($slug);
        if (!$ok) throw new \RuntimeException('It could not be deleted.');
        ActivityLogService::record($ctx->userId(), 'app.deleted', 'app', null, "Deleted app '{$slug}'");
        return $missing ? 'Removed from the list.' : 'Deleted.';
    }

    private function apps(): AppService { return Application::getInstance()->make(AppService::class); }
    private function broker(): PermissionBroker { return Application::getInstance()->make(PermissionBroker::class); }
}

/** Activation is waiting for the operator's approval of the app's permissions. */
final class NeedsConsent extends \RuntimeException {}
