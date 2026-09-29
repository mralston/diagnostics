<?php

namespace Mralston\Diagnostics\Tests;

use Mralston\Diagnostics\DiagnosticsServiceProvider;
use Mralston\Diagnostics\Facades\Diagnostics;
use Mralston\Diagnostics\Tests\Fixtures\Widget;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Diagnostics::suite('widgets')
            ->label('Widget Doctor')
            ->subject(Widget::class)
            ->checksIn(__DIR__.'/Fixtures/Checks', 'Mralston\\Diagnostics\\Tests\\Fixtures\\Checks')
            ->categories(['Basics'])
            ->authorize(fn ($user, Widget $widget) => $user !== null && ! $widget->secret)
            ->register();
    }

    protected function getPackageProviders($app): array
    {
        return [DiagnosticsServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('broadcasting.default', 'null');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/migrations');
    }

    protected function widget(array $attributes = []): Widget
    {
        return Widget::create($attributes + [
            'name' => 'Voyager',
            'colour' => 'grey',
            'broken' => false,
            'explode' => false,
            'advisory_broken' => false,
            'warn' => false,
            'secret' => false,
        ]);
    }

    protected function user(int $id = 1): TestUser
    {
        $user = new TestUser;
        $user->id = $id;
        $user->exists = true;

        return $user;
    }
}
