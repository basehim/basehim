<?php

declare(strict_types=1);

namespace App\Core\Api;

use App\Core\Application;

/**
 * AppApi — the core services facade handed to every app.
 *
 * Reached from an app as `$this->api()`. The point is that an app should never
 * need to know which service class does what, nor reach into the container, nor
 * HTTP-call its own site to do something the core can do in-process:
 *
 *     $id   = $this->api()->posts()->create(['title' => 'Hi', 'status' => 'published']);
 *     $post = $this->api()->posts()->find($id);
 *     $this->api()->posts()->update($id, ['title' => 'Hello']);
 *     $this->api()->cache()->remember('key', 300, fn() => expensive());
 *     $this->api()->schedule()->hourly('sync', [$this, 'runSync']);
 *
 * Every resource exposes the same CRUD shape — find/all/paginate/create/
 * update/delete — so once you have used one you know all of them.
 *
 * Errors: methods return null or false rather than throwing, matching the
 * behaviour of the core services underneath. An app that wants exceptions can
 * check the return and raise its own.
 *
 * The owning app's slug travels with the facade so writes can be attributed in
 * logs, and so the future permission broker has something to gate on without
 * the API shape changing again.
 */
class AppApi
{
    private array $resources = [];

    public function __construct(
        private Application $app,
        private string $slug = 'core'
    ) {
    }

    /** The slug of the app this facade belongs to. */
    public function slug(): string
    {
        return $this->slug;
    }

    /** Posts (type = "post"). */
    public function posts(): PostsApi
    {
        return $this->resources['posts'] ??= new PostsApi($this->app, $this->slug, 'post');
    }

    /** Pages (type = "page") — same API as posts(). */
    public function pages(): PostsApi
    {
        return $this->resources['pages'] ??= new PostsApi($this->app, $this->slug, 'page');
    }

    /** Any custom post type registered by an app. */
    public function content(string $type): PostsApi
    {
        return $this->resources['content:' . $type] ??= new PostsApi($this->app, $this->slug, $type);
    }

    public function media(): MediaApi
    {
        return $this->resources['media'] ??= new MediaApi($this->app, $this->slug);
    }

    public function users(): UsersApi
    {
        return $this->resources['users'] ??= new UsersApi($this->app, $this->slug);
    }

    public function comments(): CommentsApi
    {
        return $this->resources['comments'] ??= new CommentsApi($this->app, $this->slug);
    }

    public function terms(): TermsApi
    {
        return $this->resources['terms'] ??= new TermsApi($this->app, $this->slug);
    }

    public function menus(): MenusApi
    {
        return $this->resources['menus'] ??= new MenusApi($this->app, $this->slug);
    }

    /** Site settings. App-owned settings stay on $this->getSetting(). */
    public function settings(): SettingsApi
    {
        return $this->resources['settings'] ??= new SettingsApi($this->app, $this->slug);
    }

    /** App-scoped cache — keys are namespaced, so no collisions. */
    public function cache(): CacheApi
    {
        return $this->resources['cache'] ??= new CacheApi($this->app, $this->slug);
    }

    public function mail(): MailApi
    {
        return $this->resources['mail'] ??= new MailApi($this->app, $this->slug);
    }

    /** Recurring background work. */
    public function schedule(): ScheduleApi
    {
        return $this->resources['schedule'] ??= new ScheduleApi($this->app, $this->slug);
    }

    /** Outbound HTTP with sane defaults and timeouts. */
    public function http(): HttpApi
    {
        return $this->resources['http'] ??= new HttpApi($this->app, $this->slug);
    }
}
