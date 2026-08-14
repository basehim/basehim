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
