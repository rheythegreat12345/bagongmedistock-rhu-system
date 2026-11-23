<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medicine_batches', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('storage_location_id');
        });

        Schema::dropIfExists('storage_locations');
    }

    public function down(): void
    {
        Schema::create('storage_locations', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name')->unique();
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $locationId = DB::table('storage_locations')->insertGetId([
            'code' => 'DEFAULT',
            'name' => 'Default storage',
            'description' => 'Recreated when rolling back storage-location removal.',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::table('medicine_batches', function (Blueprint $table): void {
            $table->foreignId('storage_location_id')
                ->nullable()
                ->constrained()
                ->restrictOnDelete();
        });

        DB::table('medicine_batches')->whereNull('storage_location_id')->update([
            'storage_location_id' => $locationId,
        ]);
    }
};
