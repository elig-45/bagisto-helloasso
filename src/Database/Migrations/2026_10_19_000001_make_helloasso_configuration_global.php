<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('helloasso_global_migration_records')) {
            Schema::create('helloasso_global_migration_records', function ($table) {
                $table->id();
                $table->string('table_name');
                $table->unsignedBigInteger('record_id');
                $table->timestamps();
            });
        }

        $this->mergeConnections();
        $this->mergePaymentMethodConfiguration();
    }

    public function down(): void
    {
        if (! Schema::hasTable('helloasso_global_migration_records')) {
            return;
        }

        foreach (DB::table('helloasso_global_migration_records')->get() as $record) {
            DB::table($record->table_name)->where('id', $record->record_id)->delete();
        }

        Schema::drop('helloasso_global_migration_records');
    }

    protected function mergeConnections(): void
    {
        $connections = DB::table('helloasso_connections')->get();

        if ($connections->isEmpty()) {
            return;
        }

        $global = $connections->firstWhere('channel_code', 'global');

        if (! $global) {
            $global = $connections
                ->sortByDesc(fn ($connection) => [
                    (int) (filled($connection->client_id) && filled($connection->client_secret)),
                    (int) filled($connection->organization_slug),
                    (int) ($connection->environment === 'sandbox'),
                    (string) $connection->updated_at,
                ])
                ->first();
        }

        if ($global->channel_code === 'global') {
            return;
        }

        $attributes = (array) $global;
        unset($attributes['id']);
        $attributes['channel_code'] = 'global';

        $this->insertGlobalRecord('helloasso_connections', $attributes);
    }

    protected function mergePaymentMethodConfiguration(): void
    {
        if (! Schema::hasTable('core_config')) {
            return;
        }

        $rows = DB::table('core_config')
            ->where('code', 'like', 'sales.payment_methods.helloasso.%')
            ->get();

        $rows->groupBy(fn ($row) => $row->code."\0".($row->locale_code ?? ''))
            ->each(function (Collection $group) {
                if ($group->contains(fn ($row) => $row->channel_code === null)) {
                    return;
                }

                $selected = $group->sortByDesc(fn ($row) => (string) $row->updated_at)->first();

                $value = $selected->value;

                if ($selected->code === 'sales.payment_methods.helloasso.active' && $group->contains('value', '1')) {
                    $value = '1';
                }

                $attributes = (array) $selected;
                unset($attributes['id']);
                $attributes['channel_code'] = null;
                $attributes['value'] = $value;

                $this->insertGlobalRecord('core_config', $attributes);
            });
    }

    protected function insertGlobalRecord(string $table, array $attributes): void
    {
        $id = DB::table($table)->insertGetId($attributes);

        DB::table('helloasso_global_migration_records')->insert([
            'table_name' => $table,
            'record_id' => $id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
