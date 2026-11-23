@extends('layouts.app')
@section('title', 'Manage batch · '.$medicine->generic_name)
@section('content')
<div class="page-header">
    <div>
        <div class="breadcrumb">
            <a href="{{ route('medicines.index') }}">Medicines</a>
            <x-icon name="chevron" class="icon-sm" />
            <a href="{{ route('medicines.show', $medicine) }}">{{ $medicine->generic_name }}</a>
            <x-icon name="chevron" class="icon-sm" />
            {{ $batch->batch_number }}
        </div>
        <h1 class="page-title">Manage batch</h1>
        <p class="page-subtitle">Review the medicine information and update details for this batch.</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-secondary" href="{{ route('medicines.show', $medicine) }}">Back to medicine</a>
    </div>
</div>

<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Medicine information</h2>
            <p class="card-subtitle">Complete catalog details for the medicine linked to this batch.</p>
        </div>
        <x-status-badge :status="$medicine->stockStatus()" />
    </div>
    <div class="card-body">
        <dl class="details-grid">
            <div class="detail"><dt>Medicine ID</dt><dd class="mono">{{ $medicine->medicine_code }}</dd></div>
            <div class="detail"><dt>Generic name</dt><dd>{{ $medicine->generic_name }}</dd></div>
            <div class="detail"><dt>Brand name</dt><dd>{{ $medicine->brand_name ?: '—' }}</dd></div>
            <div class="detail"><dt>Category</dt><dd>{{ $medicine->category?->name ?? '—' }}</dd></div>
            <div class="detail"><dt>Strength</dt><dd>{{ $medicine->strength ?: '—' }}</dd></div>
            <div class="detail"><dt>Dosage form</dt><dd>{{ $medicine->dosage_form ?: '—' }}</dd></div>
            <div class="detail"><dt>Dosage reference</dt><dd>{{ $medicine->dosage ?: '—' }}</dd></div>
            <div class="detail"><dt>Inventory unit</dt><dd>{{ $medicine->unit }}</dd></div>
            <div class="detail"><dt>Current stock</dt><dd>{{ number_format($medicine->current_stock ?? $medicine->batches()->sum('quantity')) }} {{ $medicine->unit }}</dd></div>
            <div class="detail"><dt>Minimum stock level</dt><dd>{{ number_format($medicine->minimum_stock_level) }}</dd></div>
            <div class="detail"><dt>Reorder level</dt><dd>{{ number_format($medicine->reorder_level) }}</dd></div>
            <div class="detail"><dt>Maximum stock level</dt><dd>{{ number_format($medicine->maximum_stock_level) }}</dd></div>
            <div class="detail"><dt>Unit cost</dt><dd>₱{{ number_format((float) $medicine->unit_cost, 2) }}</dd></div>
            <div class="detail"><dt>Reference price</dt><dd>{{ $medicine->reference_price ? '₱'.number_format((float) $medicine->reference_price, 2) : '—' }}</dd></div>
            <div class="detail"><dt>Barcode</dt><dd class="mono">{{ $medicine->scannableBarcodeValue() }}</dd></div>
            <div class="detail"><dt>Catalog status</dt><dd>{{ ucfirst($medicine->status) }}</dd></div>
            <div class="detail span-2"><dt>Description</dt><dd>{{ $medicine->description ?: 'No description recorded.' }}</dd></div>
        </dl>
    </div>
</section>

<section class="card" style="margin-top:18px">
    <div class="card-header">
        <div>
            <h2 class="card-title">Manage selected batch</h2>
            <p class="card-subtitle">Update batch identifiers, supplier, expiration date, unit cost, status, or received date. Quantity is preserved.</p>
        </div>
        <x-status-badge :status="$batch->expirationStatus()" />
    </div>
    <div class="card-body">
        <form method="POST" action="{{ route('medicines.batches.expiration', [$medicine, $batch]) }}">
            @csrf
            @method('PATCH')
            <div class="form-grid">
                <div class="field">
                    <label for="batch_number">Batch number</label>
                    <input class="input" id="batch_number" name="batch_number" type="text" maxlength="80" value="{{ old('batch_number', $batch->batch_number) }}" required>
                    @error('batch_number')<p class="error-text">{{ $message }}</p>@enderror
                </div>
                <div class="field">
                    <label for="lot_number">Lot number</label>
                    <input class="input" id="lot_number" name="lot_number" type="text" maxlength="80" value="{{ old('lot_number', $batch->lot_number) }}">
                    @error('lot_number')<p class="error-text">{{ $message }}</p>@enderror
                </div>
                <div class="field">
                    <label for="supplier_id">Supplier</label>
                    <select class="select" id="supplier_id" name="supplier_id">
                        <option value="">No supplier</option>
                        @foreach($suppliers as $supplier)
                            <option value="{{ $supplier->id }}" @selected((string) old('supplier_id', $batch->supplier_id) === (string) $supplier->id)>{{ $supplier->name }}{{ $supplier->is_active ? '' : ' (inactive)' }}</option>
                        @endforeach
                    </select>
                    @error('supplier_id')<p class="error-text">{{ $message }}</p>@enderror
                </div>
                <div class="field">
                    <label for="unit_cost">Unit cost (₱)</label>
                    <input class="input" id="unit_cost" name="unit_cost" type="number" step="0.01" min="0" max="9999999999.99" value="{{ old('unit_cost', $batch->unit_cost) }}" required>
                    @error('unit_cost')<p class="error-text">{{ $message }}</p>@enderror
                </div>
                <div class="field">
                    <label for="expiration_date">Expiration date</label>
                    <input class="input" id="expiration_date" name="expiration_date" type="date" value="{{ old('expiration_date', $batch->expiration_date->toDateString()) }}" required>
                    @error('expiration_date')
                        <p class="error-text">{{ $message }}</p>
                    @enderror
                </div>
                <div class="field">
                    <label for="received_at">Received date</label>
                    <input class="input" id="received_at" name="received_at" type="date" max="{{ today()->toDateString() }}" value="{{ old('received_at', $batch->received_at?->toDateString()) }}">
                    @error('received_at')<p class="error-text">{{ $message }}</p>@enderror
                </div>
                <div class="field span-2">
                    <label for="batch_status">Batch status</label>
                    <select class="select" id="batch_status" name="status" required>
                        <option value="active" @selected(old('status', $batch->status) === 'active')>Active</option>
                        <option value="inactive" @selected(old('status', $batch->status) === 'inactive')>Inactive</option>
                    </select>
                    <p class="help-text">Inactive batches are excluded from stock release and restock selection.</p>
                    @error('status')<p class="error-text">{{ $message }}</p>@enderror
                </div>
            </div>
            <div class="form-actions">
                <a class="btn btn-secondary" href="{{ route('medicines.show', $medicine) }}">Cancel</a>
                <button class="btn btn-primary" type="submit">Save batch changes</button>
            </div>
        </form>
    </div>
</section>
@endsection
