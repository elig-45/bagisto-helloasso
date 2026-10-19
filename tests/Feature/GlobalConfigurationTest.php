<?php

use Elig45\BagistoHelloAsso\Models\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('loads HelloAsso credentials from the shared connection instead of a channel connection', function () {
    Connection::create([
        'channel_code' => 'mejf',
        'environment' => 'sandbox',
        'organization_slug' => 'legacy-mejf',
    ]);
    Connection::create([
        'channel_code' => 'betar',
        'environment' => 'sandbox',
        'organization_slug' => 'legacy-betar',
    ]);

    expect(Connection::globalConnection())->toBeNull();

    $global = Connection::create([
        'channel_code' => Connection::GLOBAL_KEY,
        'environment' => 'sandbox',
        'organization_slug' => 'shared-organization',
    ]);

    expect(Connection::globalConnection()?->is($global))->toBeTrue();
});

it('merges channel connections and payment activation into one global configuration', function () {
    Connection::create([
        'channel_code' => 'mejf',
        'environment' => 'sandbox',
        'organization_slug' => 'mejf-sandbox',
        'client_id' => 'complete-client',
        'client_secret' => 'complete-secret',
    ]);
    Connection::create([
        'channel_code' => 'betar',
        'environment' => 'production',
        'organization_slug' => 'betar-production',
        'client_id' => 'incomplete-client',
        'updated_at' => now()->addMinute(),
    ]);

    Schema::create('core_config', function (Blueprint $table) {
        $table->id();
        $table->string('code');
        $table->text('value')->nullable();
        $table->string('channel_code')->nullable();
        $table->string('locale_code')->nullable();
        $table->timestamps();
    });

    DB::table('core_config')->insert([
        ['code' => 'sales.payment_methods.helloasso.active', 'value' => '0', 'channel_code' => 'mejf', 'locale_code' => null, 'created_at' => now(), 'updated_at' => now()],
        ['code' => 'sales.payment_methods.helloasso.active', 'value' => '1', 'channel_code' => 'betar', 'locale_code' => null, 'created_at' => now(), 'updated_at' => now()],
        ['code' => 'sales.payment_methods.helloasso.title', 'value' => 'MEJF HelloAsso', 'channel_code' => 'mejf', 'locale_code' => 'fr', 'created_at' => now(), 'updated_at' => now()],
        ['code' => 'sales.payment_methods.helloasso.title', 'value' => 'Bétar HelloAsso', 'channel_code' => 'betar', 'locale_code' => 'fr', 'created_at' => now(), 'updated_at' => now()->addMinute()],
    ]);

    $migration = require dirname(__DIR__, 2).'/src/Database/Migrations/2026_10_19_000001_make_helloasso_configuration_global.php';
    $migration->up();

    expect(Connection::query()->count())->toBe(3)
        ->and(Connection::globalConnection()?->organization_slug)->toBe('mejf-sandbox')
        ->and(DB::table('core_config')->where('code', 'sales.payment_methods.helloasso.active')->whereNull('channel_code')->value('value'))->toBe('1')
        ->and(DB::table('core_config')->where('code', 'sales.payment_methods.helloasso.title')->whereNull('channel_code')->value('value'))->toBe('Bétar HelloAsso');

    $migration->down();

    expect(Connection::query()->where('channel_code', Connection::GLOBAL_KEY)->exists())->toBeFalse()
        ->and(DB::table('core_config')->where('code', 'sales.payment_methods.helloasso.active')->whereNull('channel_code')->exists())->toBeFalse()
        ->and(Connection::query()->count())->toBe(2);
});
