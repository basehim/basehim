<?php

declare(strict_types=1);

namespace App\Core\Api;

use App\Services\SettingService;

/**
 * SettingsApi — read and write SITE settings (general, reading, writing, …).
 *
 * For an app's own settings use $this->getSetting() / $this->setSetting(),
 * which are scoped to "app:{slug}" and cleaned up automatically on uninstall.
 * This class is for the shared site configuration, and writes here affect the
 * whole site — hence the logging on every set.
 */
class SettingsApi extends Resource
{
    /** Groups an app must not write to through this API. */
    private const PROTECTED_GROUPS = ['roles', 'updates'];

    private function service(): SettingService
    {
        return $this->make(SettingService::class);
    }

    public function get(string $group, string $key, mixed $default = null): mixed
    {
        return $this->attempt(fn() => $this->service()->get($group, $key, $default), $default, 'get');
    }

    /** Every setting in a group as key => value. */
    public function group(string $group): array
    {
        return (array) $this->attempt(fn() => $this->service()->getGroup($group), [], 'group');
    }

    /**
     * Write a site setting.
     *
     * Refuses the roles and updates groups: role capabilities and the update
     * service's site key are security-critical, and an app that needs to change
     * them is doing something the operator should have to do deliberately.
     */
    public function set(string $group, string $key, mixed $value, bool $autoload = true): bool
    {
        if (in_array($group, self::PROTECTED_GROUPS, true)) {
            $this->log("Refused write to protected setting group '{$group}'", [], 'warning');
            return false;
        }
        if (str_starts_with($group, 'app:')) {
            $this->log("Refused cross-app setting write to '{$group}' — use setSetting()", [], 'warning');
            return false;
        }

        $ok = $this->attempt(function () use ($group, $key, $value, $autoload) {
            $this->service()->set($group, $key, $value, $autoload);
            return true;
        }, false, 'set') === true;

        if ($ok) $this->log("Set site setting {$group}.{$key}");
        return $ok;
    }

    /**
     * Change a site setting from its current value, safely when requests
     * overlap (1.2.75). Same groups as set(). See SettingService::update().
     * Returns the value stored, or null when refused or failed.
     */
    public function update(string $group, string $key, callable $fn, mixed $default = null): mixed
    {
        if (in_array($group, self::PROTECTED_GROUPS, true) || str_starts_with($group, 'app:')) {
            $this->log("Refused update of setting group '{$group}'", [], 'warning');
            return null;
        }
        $out = $this->attempt(fn() => ['v' => $this->service()->update($group, $key, $fn, $default)], null, 'update');
        if (is_array($out)) {
            $this->log("Updated site setting {$group}.{$key}");
            return $out['v'];
        }
        return null;
    }

    /**
     * Change one of this app's own settings (the ones getSetting() and
     * setSetting() read and write) from its current value, safely when
     * requests overlap (1.2.75, CR-09):
     *
     *     $this->api()->settings()->updateOwn('notes', function ($notes) use ($note) {
     *         $notes = (array) $notes;
     *         $notes[] = $note;
     *         return array_slice($notes, -50);
     *     }, []);
     *
     * getSetting() then setSetting() lets two requests that run together each
     * save their own copy, and one change is lost. $fn may run more than once
     * (after a deadlock), so it must only compute the new value.
     */
    public function updateOwn(string $key, callable $fn, mixed $default = null): mixed
    {
        $out = $this->attempt(fn() => ['v' => $this->service()->update('app:' . $this->slug, $key, $fn, $default)], null, 'updateOwn');
        return is_array($out) ? $out['v'] : null;
    }

    /** Write several keys in one group. */
    public function setMany(string $group, array $values, bool $autoload = true): bool
    {
        foreach (array_keys($values) as $key) {
            if (!$this->set($group, (string) $key, $values[$key], $autoload)) return false;
        }
        return true;
    }

    /** Settings marked public — safe to expose to the front end. */
    public function publicSettings(): array
    {
        return (array) $this->attempt(fn() => $this->service()->publicSettings(), [], 'publicSettings');
    }
}
