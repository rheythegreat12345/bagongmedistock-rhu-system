<?php

use App\Models\AuditLog;
use App\Models\InventoryTransaction;
use App\Models\Medicine;
use App\Models\MedicineBatch;
use App\Models\MedicineCategory;
use App\Models\StockAdjustment;
use App\Models\Supplier;

function inventoryFixtures(): array
{
    $category = MedicineCategory::factory()->create();
    $supplier = Supplier::factory()->create();
    $medicine = Medicine::factory()->create([
        'medicine_category_id' => $category->id,
        'unit' => 'tablets',
        'minimum_stock_level' => 10,
        'maximum_stock_level' => 100,
    ]);

    return compact('medicine', 'supplier');
}

function batchUpdatePayload(MedicineBatch $batch, array $overrides = []): array
{
    return array_replace([
        'batch_number' => $batch->batch_number,
        'lot_number' => $batch->lot_number,
        'supplier_id' => $batch->supplier_id,
        'manufacturing_date' => $batch->manufacturing_date?->toDateString(),
        'expiration_date' => $batch->expiration_date->toDateString(),
        'unit_cost' => $batch->unit_cost,
        'status' => $batch->status,
        'received_at' => $batch->received_at?->toDateString(),
    ], $overrides);
}

test('stock in creates a batch transaction and audit record', function () {
    $user = userWithPermissions(['stock.receive']);
    $fixtures = inventoryFixtures();

    $this->actingAs($user)->post(route('stock.in.store'), [
        'medicine_id' => $fixtures['medicine']->id,
        'supplier_id' => $fixtures['supplier']->id,
        'batch_number' => 'BATCH-IN-001',
        'quantity' => 40,
        'unit_cost' => 2.5,
        'expiration_date' => today()->addYear()->toDateString(),
    ])->assertRedirect();

    $receivedBatch = MedicineBatch::query()->where('batch_number', 'BATCH-IN-001')->firstOrFail();

    expect($receivedBatch->quantity)->toBe(40)
        ->and($receivedBatch->received_at->toDateString())->toBe(today()->toDateString())
        ->and(InventoryTransaction::query()->where('type', 'stock_in')->count())->toBe(1);
    $this->assertDatabaseHas('audit_logs', ['action' => 'stock_in', 'user_id' => $user->id]);
});

test('stock in can select an existing batch without re-entering its identifying details', function () {
    $user = userWithPermissions(['stock.receive']);
    $fixtures = inventoryFixtures();
    $batch = MedicineBatch::factory()->create([
        'medicine_id' => $fixtures['medicine']->id,
        'supplier_id' => $fixtures['supplier']->id,
        'batch_number' => 'RESTOCK-SELECT-001',
        'lot_number' => 'LOT-SELECT-001',
        'quantity' => 12,
        'unit_cost' => 3.5,
        'expiration_date' => today()->addMonths(6),
    ]);

    $this->actingAs($user)->post(route('stock.in.store'), [
        'medicine_id' => $fixtures['medicine']->id,
        'medicine_batch_id' => $batch->id,
        'quantity' => 8,
    ])->assertRedirect();

    $transaction = InventoryTransaction::query()->where('type', 'stock_in')->firstOrFail();

    expect($batch->fresh()->quantity)->toBe(20)
        ->and($batch->fresh()->unit_cost)->toBe('3.50')
        ->and(MedicineBatch::query()->where('medicine_id', $fixtures['medicine']->id)->count())->toBe(1)
        ->and($transaction->medicine_batch_id)->toBe($batch->id)
        ->and($transaction->quantity)->toBe(8);
});

test('stock in does not allow selecting another medicine batch', function () {
    $user = userWithPermissions(['stock.receive']);
    $fixtures = inventoryFixtures();
    $otherMedicine = Medicine::factory()->create();
    $batch = MedicineBatch::factory()->create([
        'medicine_id' => $otherMedicine->id,
        'quantity' => 12,
    ]);

    $this->actingAs($user)->post(route('stock.in.store'), [
        'medicine_id' => $fixtures['medicine']->id,
        'medicine_batch_id' => $batch->id,
        'quantity' => 8,
    ])->assertSessionHasErrors('medicine_batch_id');

    expect($batch->fresh()->quantity)->toBe(12)
        ->and(InventoryTransaction::query()->where('type', 'stock_in')->count())->toBe(0);
});

test('new stock batches preserve and display their separate expiration dates', function () {
    $user = userWithPermissions(['stock.receive', 'transactions.view']);
    $fixtures = inventoryFixtures();
    $previousExpiration = today()->addMonths(6)->format('Y-m-d');
    $newExpiration = today()->addYear()->format('Y-m-d');

    foreach ([
        ['batch_number' => 'EXPIRY-OLD-001', 'expiration_date' => $previousExpiration],
        ['batch_number' => 'EXPIRY-NEW-001', 'expiration_date' => $newExpiration],
    ] as $receipt) {
        $this->actingAs($user)->post(route('stock.in.store'), [
            'medicine_id' => $fixtures['medicine']->id,
            'supplier_id' => $fixtures['supplier']->id,
            'batch_number' => $receipt['batch_number'],
            'quantity' => 10,
            'unit_cost' => 2.5,
            'expiration_date' => $receipt['expiration_date'],
        ])->assertRedirect();
    }

    $previousBatch = MedicineBatch::query()->where('batch_number', 'EXPIRY-OLD-001')->firstOrFail();
    $newBatch = MedicineBatch::query()->where('batch_number', 'EXPIRY-NEW-001')->firstOrFail();

    expect(substr((string) $previousBatch->getRawOriginal('expiration_date'), 0, 10))->toBe($previousExpiration)
        ->and(substr((string) $newBatch->getRawOriginal('expiration_date'), 0, 10))->toBe($newExpiration);

    $this->actingAs($user)
        ->get(route('transactions.index'))
        ->assertOk()
        ->assertSee('EXPIRY-OLD-001')
        ->assertSee('EXPIRY-NEW-001')
        ->assertSee(date('M d, Y', strtotime($previousExpiration)))
        ->assertSee(date('M d, Y', strtotime($newExpiration)));
});

test('stock in form shows only essential receipt fields', function () {
    $user = userWithPermissions(['stock.receive']);
    inventoryFixtures();

    $this->actingAs($user)
        ->get(route('stock.in'))
        ->assertOk()
        ->assertSee('name="medicine_batch_id"', false)
        ->assertSee('Existing batch (optional)')
        ->assertSee('for="batch_number"', false)
        ->assertSee('Batch / lot number')
        ->assertDontSee('for="storage_location_id"', false)
        ->assertDontSee('name="storage_location_id"', false)
        ->assertDontSee('Storage location')
        ->assertDontSee('physical location')
        ->assertSee('for="quantity_unit"', false)
        ->assertSee('data-box-receipt-medicine', false)
        ->assertSee('id="box-receipt-reminder"', false)
        ->assertSee('for="expiration_date"', false)
        ->assertDontSee('for="lot_number"', false)
        ->assertDontSee('for="manufacturing_date"', false)
        ->assertDontSee('for="received_at"', false)
        ->assertDontSee('for="reference_number"', false)
        ->assertDontSee('for="remarks"', false);
});

test('stock in batch selector lists only active unexpired batches', function () {
    $user = userWithPermissions(['stock.receive']);
    $fixtures = inventoryFixtures();

    MedicineBatch::factory()->create([
        'medicine_id' => $fixtures['medicine']->id,
        'batch_number' => 'SELECTABLE-BATCH',
        'expiration_date' => today()->addMonth(),
    ]);
    MedicineBatch::factory()->create([
        'medicine_id' => $fixtures['medicine']->id,
        'batch_number' => 'EXPIRED-BATCH',
        'expiration_date' => today()->subDay(),
    ]);
    MedicineBatch::factory()->create([
        'medicine_id' => $fixtures['medicine']->id,
        'batch_number' => 'INACTIVE-BATCH',
        'status' => 'inactive',
    ]);

    $this->actingAs($user)
        ->get(route('stock.in', ['medicine' => $fixtures['medicine']->id, 'restock' => 1]))
        ->assertOk()
        ->assertSee('SELECTABLE-BATCH')
        ->assertDontSee('EXPIRED-BATCH')
        ->assertDontSee('INACTIVE-BATCH');
});

test('medicine list dropdown shows each batch with a manage link', function () {
    $user = userWithPermissions(['medicines.view', 'medicines.edit']);
    $fixtures = inventoryFixtures();
    $batch = MedicineBatch::factory()->create([
        'medicine_id' => $fixtures['medicine']->id,
        'batch_number' => 'LIST-MANAGE-BATCH-001',
        'lot_number' => 'LOT-MANAGE-001',
    ]);

    $this->actingAs($user)
        ->get(route('medicines.index'))
        ->assertOk()
        ->assertSee('Batches (')
        ->assertSee('LIST-MANAGE-BATCH-001')
        ->assertSee('LOT-MANAGE-001')
        ->assertSee('Manage')
        ->assertDontSee('<th>Location</th>', false)
        ->assertSee(route('medicines.batches.expiration.edit', [$fixtures['medicine'], $batch]));
});

test('authorized users can correct the expiration date for one batch, including a past date', function () {
    $user = userWithPermissions(['medicines.view', 'medicines.edit']);
    $fixtures = inventoryFixtures();
    $batch = MedicineBatch::factory()->create([
        'medicine_id' => $fixtures['medicine']->id,
        'batch_number' => 'EXPIRY-CORRECTION-001',
        'quantity' => 10,
        'expiration_date' => today()->addYear(),
    ]);
    $otherBatch = MedicineBatch::factory()->create([
        'medicine_id' => $fixtures['medicine']->id,
        'batch_number' => 'EXPIRY-UNCHANGED-001',
        'expiration_date' => today()->addYears(2),
    ]);
    $availableStockBeforeCorrection = $fixtures['medicine']->fresh()->usableStockQuantity();

    $this->actingAs($user)
        ->get(route('medicines.show', $fixtures['medicine']))
        ->assertOk()
        ->assertSee('Edit batch')
        ->assertSee(route('medicines.batches.expiration.edit', [$fixtures['medicine'], $batch]));

    $this->get(route('medicines.batches.expiration.edit', [$fixtures['medicine'], $batch]))
        ->assertOk()
        ->assertSee('Medicine information')
        ->assertSee('Manage selected batch')
        ->assertSee($fixtures['medicine']->generic_name)
        ->assertSee($batch->batch_number)
        ->assertDontSee('Default cost')
        ->assertSee('Unit cost')
        ->assertDontSee('Stock record')
        ->assertDontSee('Use Stock Adjustment to change quantity')
        ->assertSee('name="batch_number"', false)
        ->assertSee('name="supplier_id"', false)
        ->assertDontSee('Storage condition')
        ->assertDontSee('Storage location')
        ->assertDontSee('name="storage_location_id"', false)
        ->assertDontSee('Manufacturing date')
        ->assertDontSee('name="manufacturing_date"', false)
        ->assertSee('name="expiration_date"', false)
        ->assertSee('name="unit_cost"', false)
        ->assertSee('Unit cost (₱)')
        ->assertSee('name="status"', false)
        ->assertSee('name="received_at"', false);

    $correctedDate = today()->subDay()->toDateString();

    $this->actingAs($user)
        ->patch(route('medicines.batches.expiration', [$fixtures['medicine'], $batch]), batchUpdatePayload($batch, [
            'expiration_date' => $correctedDate,
        ]))
        ->assertRedirect(route('medicines.show', $fixtures['medicine']))
        ->assertSessionHas('success', 'Batch details updated.');

    expect($batch->fresh()->expiration_date->toDateString())->toBe($correctedDate)
        ->and($otherBatch->fresh()->expiration_date->toDateString())->toBe(today()->addYears(2)->toDateString())
        ->and($fixtures['medicine']->fresh()->usableStockQuantity())->toBe($availableStockBeforeCorrection - $batch->quantity);

    $audit = AuditLog::query()->where('action', 'batch_updated')->firstOrFail();
    expect($audit->old_values)->toMatchArray([
        'batch_id' => $batch->id,
        'expiration_date' => today()->addYear()->toDateString(),
    ])->and($audit->new_values)->toMatchArray([
        'batch_id' => $batch->id,
        'expiration_date' => $correctedDate,
    ]);
});

test('batch details can be corrected without changing its manufacturing date or quantity', function () {
    $user = userWithPermissions(['medicines.edit']);
    $fixtures = inventoryFixtures();
    $batch = MedicineBatch::factory()->create([
        'medicine_id' => $fixtures['medicine']->id,
        'supplier_id' => $fixtures['supplier']->id,
        'batch_number' => 'BATCH-DETAILS-OLD',
        'lot_number' => 'LOT-DETAILS-OLD',
        'quantity' => 28,
        'received_at' => today()->subDays(30),
        'manufacturing_date' => today()->subYear(),
        'expiration_date' => today()->addYear(),
        'unit_cost' => 3.25,
    ]);
    $otherBatch = MedicineBatch::factory()->create([
        'medicine_id' => $fixtures['medicine']->id,
        'batch_number' => 'BATCH-DETAILS-OTHER',
        'quantity' => 9,
    ]);
    $newSupplier = Supplier::factory()->create();
    $receivedDate = $batch->received_at->toDateString();
    $manufacturingDate = $batch->manufacturing_date->toDateString();
    $correctedReceivedDate = today()->subDays(5)->toDateString();

    $this->actingAs($user)
        ->patch(route('medicines.batches.expiration', [$fixtures['medicine'], $batch]), batchUpdatePayload($batch, [
            'batch_number' => 'BATCH-DETAILS-CORRECTED',
            'lot_number' => 'LOT-DETAILS-CORRECTED',
            'supplier_id' => $newSupplier->id,
            'manufacturing_date' => today()->subMonths(18)->toDateString(),
            'expiration_date' => today()->addMonths(18)->toDateString(),
            'unit_cost' => 5.75,
            'status' => 'inactive',
            'quantity' => 999,
            'received_at' => $correctedReceivedDate,
        ]))
        ->assertRedirect(route('medicines.show', $fixtures['medicine']))
        ->assertSessionHas('success', 'Batch details updated.');

    $updatedBatch = $batch->fresh();
    expect($updatedBatch->batch_number)->toBe('BATCH-DETAILS-CORRECTED')
        ->and($updatedBatch->lot_number)->toBe('LOT-DETAILS-CORRECTED')
        ->and($updatedBatch->supplier_id)->toBe($newSupplier->id)
        ->and($updatedBatch->manufacturing_date->toDateString())->toBe($manufacturingDate)
        ->and($updatedBatch->expiration_date->toDateString())->toBe(today()->addMonths(18)->toDateString())
        ->and((float) $updatedBatch->unit_cost)->toBe(5.75)
        ->and($updatedBatch->status)->toBe('inactive')
        ->and($updatedBatch->quantity)->toBe(28)
        ->and($receivedDate)->not->toBe($correctedReceivedDate)
        ->and($updatedBatch->received_at->toDateString())->toBe($correctedReceivedDate)
        ->and($otherBatch->fresh()->batch_number)->toBe('BATCH-DETAILS-OTHER');

    $audit = AuditLog::query()->where('action', 'batch_updated')->firstOrFail();
    expect($audit->old_values)->toMatchArray([
        'batch_id' => $batch->id,
        'batch_number' => 'BATCH-DETAILS-OLD',
        'received_at' => $receivedDate,
    ])->and($audit->new_values)->toMatchArray([
        'batch_id' => $batch->id,
        'batch_number' => 'BATCH-DETAILS-CORRECTED',
        'received_at' => $correctedReceivedDate,
    ]);
});

test('batch expiration editing requires medicine edit permission', function () {
    $user = userWithPermissions(['medicines.view']);
    $fixtures = inventoryFixtures();
    $batch = MedicineBatch::factory()->create([
        'medicine_id' => $fixtures['medicine']->id,
    ]);

    $this->actingAs($user)
        ->get(route('medicines.show', $fixtures['medicine']))
        ->assertOk()
        ->assertDontSee('Edit batch');

    $this->get(route('medicines.batches.expiration.edit', [$fixtures['medicine'], $batch]))
        ->assertForbidden();

    $this->patch(route('medicines.batches.expiration', [$fixtures['medicine'], $batch]), [
        'expiration_date' => today()->addYear()->toDateString(),
    ])->assertForbidden();

    expect($batch->fresh()->expiration_date->toDateString())->toBe(today()->addYear()->toDateString());
});

test('batch expiration cannot be set before its manufacturing date', function () {
    $user = userWithPermissions(['medicines.edit']);
    $fixtures = inventoryFixtures();
    $manufacturingDate = today()->subMonth();
    $batch = MedicineBatch::factory()->create([
        'medicine_id' => $fixtures['medicine']->id,
        'manufacturing_date' => $manufacturingDate,
        'expiration_date' => today()->addYear(),
    ]);

    $this->actingAs($user)
        ->patch(route('medicines.batches.expiration', [$fixtures['medicine'], $batch]), batchUpdatePayload($batch, [
            'expiration_date' => $manufacturingDate->subDay()->toDateString(),
        ]))
        ->assertSessionHasErrors('expiration_date');

    expect($batch->fresh()->expiration_date->toDateString())->toBe(today()->addYear()->toDateString());
});

test('stock in converts boxes to base inventory units', function () {
    $user = userWithPermissions(['stock.receive']);
    $fixtures = inventoryFixtures();
    $fixtures['medicine']->update(['box_size' => 100]);

    $this->actingAs($user)->post(route('stock.in.store'), [
        'medicine_id' => $fixtures['medicine']->id,
        'supplier_id' => $fixtures['supplier']->id,
        'batch_number' => 'BOX-IN-001',
        'quantity' => 3,
        'quantity_unit' => 'box',
        'unit_cost' => 2.5,
        'expiration_date' => today()->addYear()->toDateString(),
    ])->assertRedirect();

    $receivedBatch = MedicineBatch::query()->where('batch_number', 'BOX-IN-001')->firstOrFail();
    $transaction = InventoryTransaction::query()->where('medicine_batch_id', $receivedBatch->id)->firstOrFail();

    expect($receivedBatch->quantity)->toBe(300)
        ->and($transaction->quantity)->toBe(300)
        ->and($transaction->metadata)->toMatchArray([
            'entered_quantity' => 3,
            'entered_unit' => 'box',
            'units_per_box' => 100,
        ]);
});

test('stock in requires the configured units per box before converting boxes', function () {
    $user = userWithPermissions(['stock.receive']);
    $fixtures = inventoryFixtures();

    $this->actingAs($user)->post(route('stock.in.store'), [
        'medicine_id' => $fixtures['medicine']->id,
        'supplier_id' => $fixtures['supplier']->id,
        'batch_number' => 'BOX-IN-NO-CONFIG',
        'quantity' => 3,
        'quantity_unit' => 'box',
        'unit_cost' => 2.5,
        'expiration_date' => today()->addYear()->toDateString(),
    ])->assertSessionHasErrors('quantity_unit');

    $this->assertDatabaseMissing('medicine_batches', ['batch_number' => 'BOX-IN-NO-CONFIG']);
});

test('stock in does not merge a different lot number into an existing batch', function () {
    $user = userWithPermissions(['stock.receive']);
    $fixtures = inventoryFixtures();
    $receipt = [
        'medicine_id' => $fixtures['medicine']->id,
        'supplier_id' => $fixtures['supplier']->id,
        'batch_number' => 'BATCH-LOT-001',
        'quantity' => 10,
        'unit_cost' => 2.5,
        'expiration_date' => today()->addYear()->toDateString(),
        'received_at' => today()->toDateString(),
    ];

    $this->actingAs($user)->post(route('stock.in.store'), [...$receipt, 'lot_number' => 'LOT-A'])->assertRedirect();

    $this->actingAs($user)
        ->post(route('stock.in.store'), [...$receipt, 'lot_number' => 'LOT-B'])
        ->assertSessionHasErrors('lot_number');

    expect(MedicineBatch::query()->where('batch_number', 'BATCH-LOT-001')->value('quantity'))->toBe(10);
});

test('stock in rejects receiving an existing batch from a different supplier', function () {
    $user = userWithPermissions(['stock.receive']);
    $fixtures = inventoryFixtures();
    $otherSupplier = Supplier::factory()->create();
    $receipt = [
        'medicine_id' => $fixtures['medicine']->id,
        'supplier_id' => $fixtures['supplier']->id,
        'batch_number' => 'BATCH-SOURCE-001',
        'quantity' => 10,
        'unit_cost' => 2.5,
        'expiration_date' => today()->addYear()->toDateString(),
    ];

    $this->actingAs($user)->post(route('stock.in.store'), $receipt)->assertRedirect();
    $batch = MedicineBatch::query()->where('batch_number', 'BATCH-SOURCE-001')->firstOrFail();

    $this->actingAs($user)
        ->post(route('stock.in.store'), [...$receipt, 'supplier_id' => $otherSupplier->id])
        ->assertSessionHasErrors('batch_number');

    expect($batch->supplier_id)->toBe($fixtures['supplier']->id)
        ->and($batch->quantity)->toBe(10);
});

test('expired stock is not treated as usable stock or available for reorder decisions', function () {
    $fixtures = inventoryFixtures();
    MedicineBatch::factory()->create([
        'medicine_id' => $fixtures['medicine']->id,
        'supplier_id' => $fixtures['supplier']->id,
        'quantity' => 500,
        'expiration_date' => today()->subDay(),
    ]);

    $medicine = $fixtures['medicine']->fresh();

    expect($medicine->usableStockQuantity())->toBe(0)
        ->and($medicine->stockStatus())->toBe('out')
        ->and($medicine->recommendedReorderQuantity())->toBe(100);
});

test('expired medicine cannot be received', function () {
    $user = userWithPermissions(['stock.receive']);
    $fixtures = inventoryFixtures();

    $this->actingAs($user)->post(route('stock.in.store'), [
        'medicine_id' => $fixtures['medicine']->id,
        'supplier_id' => $fixtures['supplier']->id,
        'batch_number' => 'EXPIRED-001',
        'quantity' => 10,
        'unit_cost' => 1,
        'expiration_date' => today()->subDay()->toDateString(),
        'received_at' => today()->toDateString(),
    ])->assertSessionHasErrors('expiration_date');

    expect(MedicineBatch::query()->count())->toBe(0);
});

test('stock out uses FEFO and never consumes expired batches', function () {
    $user = userWithPermissions(['stock.release', 'transactions.view', 'reports.view']);
    $fixtures = inventoryFixtures();
    $medicine = $fixtures['medicine'];
    $early = MedicineBatch::factory()->create(['medicine_id' => $medicine->id, 'supplier_id' => $fixtures['supplier']->id, 'batch_number' => 'EARLY', 'expiration_date' => today()->addMonth(), 'quantity' => 5]);
    $later = MedicineBatch::factory()->create(['medicine_id' => $medicine->id, 'supplier_id' => $fixtures['supplier']->id, 'batch_number' => 'LATER', 'expiration_date' => today()->addYear(), 'quantity' => 10]);
    $expired = MedicineBatch::factory()->create(['medicine_id' => $medicine->id, 'supplier_id' => $fixtures['supplier']->id, 'batch_number' => 'EXPIRED', 'expiration_date' => today()->subDay(), 'quantity' => 50]);

    $this->actingAs($user)->post(route('stock.out.store'), [
        'medicine_id' => $medicine->id,
        'quantity' => 8,
        'type' => 'dispensed',
        'purpose' => 'Patient dispensing',
        'recipient' => 'Ana Cruz',
    ])->assertRedirect();

    expect($early->fresh()->quantity)->toBe(0)
        ->and($later->fresh()->quantity)->toBe(7)
        ->and($expired->fresh()->quantity)->toBe(50)
        ->and(InventoryTransaction::query()->where('type', 'dispensed')->count())->toBe(2);

    $auditLog = AuditLog::query()->where('action', 'stock_out')->firstOrFail();

    expect($auditLog->old_values)->toMatchArray([
        'available_stock' => 15,
        'quantity_released' => 0,
    ])->and($auditLog->new_values)->toMatchArray([
        'available_stock' => 7,
        'quantity_released' => 8,
        'purpose' => 'Patient dispensing',
        'recipient' => 'Ana Cruz',
    ]);
    $this->assertDatabaseHas('inventory_notifications', [
        'type' => 'medicine_dispensed',
        'title' => 'Medicine dispensed',
    ]);

    $this->actingAs($user)
        ->get(route('transactions.index'))
        ->assertOk()
        ->assertSee('Processed by')
        ->assertSee('Recipient')
        ->assertSee($user->name)
        ->assertSee('Ana Cruz');

    $this->get(route('reports.index', ['report' => 'dispensing']))
        ->assertOk()
        ->assertSee('Processed by')
        ->assertSee($user->name)
        ->assertSee('Ana Cruz');
});

test('stock out form shows only essential release fields', function () {
    $user = userWithPermissions(['stock.release']);
    inventoryFixtures();

    $this->actingAs($user)
        ->get(route('stock.out'))
        ->assertOk()
        ->assertSee('for="medicine_id"', false)
        ->assertSee('for="quantity"', false)
        ->assertSee('for="quantity_unit"', false)
        ->assertSee('data-box-release-medicine', false)
        ->assertSee('id="box-quantity-reminder"', false)
        ->assertSee('for="type"', false)
        ->assertSee('for="purpose"', false)
        ->assertSee('for="recipient"', false)
        ->assertSee('data-dispense-type', false)
        ->assertSee('0 tablets left')
        ->assertDontSee('for="medicine_batch_id"', false)
        ->assertDontSee('for="transacted_at"', false)
        ->assertDontSee('for="reference_number"', false)
        ->assertDontSee('for="remarks"', false);
});

test('stock out converts boxes to base inventory units', function () {
    $user = userWithPermissions(['stock.release']);
    $fixtures = inventoryFixtures();
    $fixtures['medicine']->update(['box_size' => 100]);
    $batch = MedicineBatch::factory()->create([
        'medicine_id' => $fixtures['medicine']->id,
        'supplier_id' => $fixtures['supplier']->id,
        'quantity' => 300,
        'expiration_date' => today()->addYear(),
    ]);

    $this->actingAs($user)->post(route('stock.out.store'), [
        'medicine_id' => $fixtures['medicine']->id,
        'quantity' => 1,
        'quantity_unit' => 'box',
        'type' => 'stock_out',
        'purpose' => 'Clinic supply release',
    ])->assertRedirect();

    $transaction = InventoryTransaction::query()->where('medicine_batch_id', $batch->id)->latest('id')->firstOrFail();

    expect($batch->fresh()->quantity)->toBe(200)
        ->and($transaction->quantity)->toBe(100)
        ->and($transaction->metadata)->toMatchArray([
            'entered_quantity' => 1,
            'entered_unit' => 'box',
            'units_per_box' => 100,
        ]);
});

test('stock out blocks boxes when fewer than 100 units are available', function () {
    $user = userWithPermissions(['stock.release']);
    $fixtures = inventoryFixtures();
    $fixtures['medicine']->update(['box_size' => 10]);
    $batch = MedicineBatch::factory()->create([
        'medicine_id' => $fixtures['medicine']->id,
        'supplier_id' => $fixtures['supplier']->id,
        'quantity' => 99,
        'expiration_date' => today()->addYear(),
    ]);

    $this->actingAs($user)->post(route('stock.out.store'), [
        'medicine_id' => $fixtures['medicine']->id,
        'quantity' => 1,
        'quantity_unit' => 'box',
        'type' => 'stock_out',
        'purpose' => 'Clinic supply release',
    ])->assertSessionHasErrors('quantity_unit');

    expect($batch->fresh()->quantity)->toBe(99);
});

test('dispensing to a patient requires the patient name', function () {
    $user = userWithPermissions(['stock.release']);
    $fixtures = inventoryFixtures();
    $batch = MedicineBatch::factory()->create([
        'medicine_id' => $fixtures['medicine']->id,
        'supplier_id' => $fixtures['supplier']->id,
        'quantity' => 10,
        'expiration_date' => today()->addYear(),
    ]);

    $this->actingAs($user)
        ->post(route('stock.out.store'), [
            'medicine_id' => $fixtures['medicine']->id,
            'quantity' => 2,
            'type' => 'dispensed',
            'purpose' => 'Patient dispensing',
        ])
        ->assertSessionHasErrors('recipient');

    expect($batch->fresh()->quantity)->toBe(10);
});

test('expiration monitoring searches batches, medicines, and suppliers', function () {
    $user = userWithPermissions(['medicines.view']);
    $fixtures = inventoryFixtures();
    $fixtures['medicine']->update(['generic_name' => 'Searchable Medicine']);
    $fixtures['supplier']->update(['name' => 'Searchable Supplier']);

    MedicineBatch::factory()->create([
        'medicine_id' => $fixtures['medicine']->id,
        'supplier_id' => $fixtures['supplier']->id,
        'batch_number' => 'SEARCH-BATCH-001',
        'lot_number' => 'SEARCH-LOT-001',
        'quantity' => 10,
    ]);

    MedicineBatch::factory()->create(['batch_number' => 'HIDDEN-BATCH-001', 'quantity' => 10]);

    $this->actingAs($user)
        ->get(route('inventory.expirations', ['search' => 'SEARCH-BATCH']))
        ->assertOk()
        ->assertSee('SEARCH-BATCH-001')
        ->assertDontSee('HIDDEN-BATCH-001')
        ->assertSee('Search batches');
});

test('low stock alerts can be searched without changing summary totals', function () {
    $user = userWithPermissions(['medicines.view']);
    $fixtures = inventoryFixtures();
    $fixtures['medicine']->update(['generic_name' => 'Searchable Low Stock Medicine']);

    MedicineBatch::factory()->create([
        'medicine_id' => $fixtures['medicine']->id,
        'supplier_id' => $fixtures['supplier']->id,
        'quantity' => 2,
    ]);

    $otherMedicine = Medicine::factory()->create([
        'minimum_stock_level' => 10,
        'maximum_stock_level' => 100,
        'generic_name' => 'Other Low Stock Medicine',
    ]);
    MedicineBatch::factory()->create(['medicine_id' => $otherMedicine->id, 'quantity' => 1]);

    $this->actingAs($user)
        ->get(route('inventory.low-stock', ['search' => 'Searchable Low Stock']))
        ->assertOk()
        ->assertSee('Searchable Low Stock Medicine')
        ->assertDontSee('Other Low Stock Medicine')
        ->assertSee('Search low-stock medicines')
        ->assertSee('Out of stock');
});

test('low stock alerts display zero for a medicine without batches', function () {
    $user = userWithPermissions(['medicines.view']);
    Medicine::factory()->create([
        'generic_name' => 'Zero Stock Medicine',
        'unit' => 'tablets',
        'minimum_stock_level' => 10,
    ]);

    $this->actingAs($user)
        ->get(route('inventory.low-stock', ['search' => 'Zero Stock Medicine']))
        ->assertOk()
        ->assertSee('Zero Stock Medicine')
        ->assertSee('>0</strong> tablets', false);
});

test('an adjustment cannot reduce batch stock below zero', function () {
    $user = userWithPermissions(['stock.adjust']);
    $fixtures = inventoryFixtures();
    $batch = MedicineBatch::factory()->create(['medicine_id' => $fixtures['medicine']->id, 'supplier_id' => $fixtures['supplier']->id, 'quantity' => 4]);

    $this->actingAs($user)->post(route('stock.adjustment.store'), [
        'medicine_batch_id' => $batch->id,
        'quantity_difference' => -5,
        'reason' => 'damaged',
        'notes' => 'Damaged during inspection.',
    ])->assertSessionHasErrors('quantity_difference');

    expect($batch->fresh()->quantity)->toBe(4)
        ->and(StockAdjustment::query()->count())->toBe(0);
});

test('a valid adjustment records before and after stock', function () {
    $user = userWithPermissions(['stock.adjust']);
    $fixtures = inventoryFixtures();
    $batch = MedicineBatch::factory()->create(['medicine_id' => $fixtures['medicine']->id, 'supplier_id' => $fixtures['supplier']->id, 'quantity' => 10]);

    $this->actingAs($user)->post(route('stock.adjustment.store'), [
        'medicine_batch_id' => $batch->id,
        'quantity_difference' => -2,
        'reason' => 'damaged',
        'notes' => 'Packaging damaged during inspection.',
    ])->assertRedirect();

    expect($batch->fresh()->quantity)->toBe(8)
        ->and(StockAdjustment::query()->where('quantity_difference', -2)->exists())->toBeTrue();
    $this->assertDatabaseHas('inventory_transactions', ['previous_stock' => 10, 'new_stock' => 8]);
});

test('an adjustment converts boxes to base inventory units', function () {
    $user = userWithPermissions(['stock.adjust']);
    $fixtures = inventoryFixtures();
    $fixtures['medicine']->update(['box_size' => 50]);
    $batch = MedicineBatch::factory()->create([
        'medicine_id' => $fixtures['medicine']->id,
        'supplier_id' => $fixtures['supplier']->id,
        'quantity' => 200,
    ]);

    $this->actingAs($user)->post(route('stock.adjustment.store'), [
        'medicine_batch_id' => $batch->id,
        'quantity_difference' => -1,
        'quantity_unit' => 'box',
        'reason' => 'damaged',
        'notes' => 'One damaged box was removed.',
    ])->assertRedirect();

    $transaction = InventoryTransaction::query()->where('medicine_batch_id', $batch->id)->latest('id')->firstOrFail();

    expect($batch->fresh()->quantity)->toBe(150)
        ->and($transaction->metadata)->toMatchArray([
            'quantity_difference' => -50,
            'entered_quantity' => -1,
            'entered_unit' => 'box',
            'units_per_box' => 50,
        ]);
});
