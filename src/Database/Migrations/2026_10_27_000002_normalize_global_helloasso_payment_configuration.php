<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected const SNAPSHOTS_TABLE = 'helloasso_global_config_snapshots';

    public function up(): void
    {
        if (! Schema::hasTable('core_config')) {
            return;
        }

        if (! Schema::hasTable(self::SNAPSHOTS_TABLE)) {
            Schema::create(self::SNAPSHOTS_TABLE, function ($table) {
                $table->id();
                $table->unsignedBigInteger('record_id')->unique();
                $table->string('channel_code')->nullable();
                $table->longText('value')->nullable();
                $table->timestamps();
            });
        }

        DB::table('core_config')
            ->where('code', 'like', 'sales.payment_methods.helloasso.%')
            ->orderBy('id')
            ->get()
            ->groupBy(fn ($row) => $row->code."\0".($row->locale_code ?? ''))
            ->each(function (Collection $group) {
                $global = $this->newestRow($group->filter(fn ($row) => $row->channel_code === null));
                $selected = $global ?? $this->newestRow($group);
                $value = $selected->value;

                if (
                    ! $global
                    && $selected->code === 'sales.payment_methods.helloasso.active'
                    && $group->contains(fn ($row) => (string) $row->value === '1')
                ) {
                    $value = '1';
                }

                foreach ($group as $row) {
                    DB::table(self::SNAPSHOTS_TABLE)->insertOrIgnore([
                        'record_id' => $row->id,
                        'channel_code' => $row->channel_code,
                        'value' => $row->value,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                DB::table('core_config')
                    ->whereIn('id', $group->pluck('id'))
                    ->update([
                        'channel_code' => null,
                        'value' => $value,
                    ]);
            });
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::SNAPSHOTS_TABLE)) {
            return;
        }

        if (Schema::hasTable('core_config')) {
            DB::table(self::SNAPSHOTS_TABLE)->orderBy('id')->get()->each(function ($snapshot) {
                DB::table('core_config')
                    ->where('id', $snapshot->record_id)
                    ->update([
                        'channel_code' => $snapshot->channel_code,
                        'value' => $snapshot->value,
                    ]);
            });
        }

        Schema::drop(self::SNAPSHOTS_TABLE);
    }

    protected function newestRow(Collection $rows): ?object
    {
        return $rows->sort(function ($left, $right) {
            return [(string) $right->updated_at, (int) $right->id]
                <=> [(string) $left->updated_at, (int) $left->id];
        })->first();
    }
};
