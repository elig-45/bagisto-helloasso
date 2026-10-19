<?php

namespace Elig45\BagistoHelloAsso\Tests;

use Elig45\BagistoHelloAsso\Providers\HelloAssoServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestbenchCase;

class TestCase extends OrchestraTestbenchCase
{
    protected function getPackageProviders($app): array
    {
        return [HelloAssoServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('app.admin_url', 'admin');
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $migration = require dirname(__DIR__).'/src/Database/Migrations/2026_10_18_000001_create_helloasso_connections_table.php';
        $migration->up();

        $migration = require dirname(__DIR__).'/src/Database/Migrations/2026_10_18_000002_update_helloasso_connection_activation_and_eligibility.php';
        $migration->up();

        $migration = require dirname(__DIR__).'/src/Database/Migrations/2026_10_19_000001_make_helloasso_configuration_global.php';
        $migration->up();
    }
}
