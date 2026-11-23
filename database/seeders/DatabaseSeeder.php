<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            UserSeeder::class,
            PermissionSeeder::class,
            MedicineCategorySeeder::class,
            SupplierSeeder::class,
            MedicineSeeder::class,
            MedicineBatchSeeder::class,
            SettingSeeder::class,
        ]);

        $this->call([
            InventoryTransactionSeeder::class,
            StockAdjustmentSeeder::class,
            InventoryNotificationSeeder::class,
            AuditLogSeeder::class,
        ]);
    }
}
