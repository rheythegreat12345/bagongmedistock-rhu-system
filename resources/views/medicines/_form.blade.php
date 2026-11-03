@php $editing = $medicine->exists; @endphp
<form id="medicineForm" method="POST" action="{{ $editing ? route('medicines.update',$medicine) : route('medicines.store') }}">@csrf @if($editing)@method('PUT')@endif
<div class="card"><div class="card-header"><div><h2 class="card-title">Medicine information</h2><p class="card-subtitle">Stock quantity is managed separately through batches and transactions.</p></div><x-status-badge :status="$medicine->status ?? 'active'" /></div><div class="card-body"><div class="form-grid">
    <div class="field"><label class="required" for="medicine_code">Medicine ID</label><input class="input" id="medicine_code" name="medicine_code" value="{{ old('medicine_code',$medicine->medicine_code) }}" required placeholder="MED-021">@error('medicine_code')<div class="error-text">{{ $message }}</div>@enderror</div>
    <div class="field"><label for="barcode">Barcode value</label><input class="input mono" id="barcode" name="barcode" value="{{ old('barcode',$medicine->barcode) }}" placeholder="EAN / UPC / internal code"><div class="help-text">Can be entered manually or captured by a connected scanner.</div></div>
    <div class="field"><label class="required" for="generic_name">Generic name</label><input class="input" id="generic_name" name="generic_name" value="{{ old('generic_name',$medicine->generic_name) }}" required></div>
    <div class="field"><label for="brand_name">Brand name</label><input class="input" id="brand_name" name="brand_name" value="{{ old('brand_name',$medicine->brand_name) }}"></div>
    <div class="field"><label class="required" for="medicine_category_id">Category</label><select class="select" id="medicine_category_id" name="medicine_category_id" required><option value="">Choose category</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected((string)old('medicine_category_id',$medicine->medicine_category_id)===(string)$category->id)>{{ $category->name }}</option>@endforeach</select></div>
    <div class="field"><label for="strength">Strength</label><input class="input" id="strength" name="strength" value="{{ old('strength',$medicine->strength) }}" placeholder="e.g. 500 mg"></div>
    <div class="field"><label class="required" for="unit">Inventory unit</label><select class="select" id="unit" name="unit" required><option value="">Choose unit</option>@foreach(['Tablets','Capsules','Bottles','Vials','Ampoules','Sachets','Tubes','Drops','Inhalers','Boxes'] as $unit)<option value="{{ $unit }}" @selected(old('unit',$medicine->unit)===$unit)>{{ $unit }}</option>@endforeach</select></div>
    <div class="field"><label for="box_size">Units per box</label><input class="input" id="box_size" name="box_size" type="number" min="1" value="{{ old('box_size',$medicine->unitsPerBox()) }}" placeholder="e.g. 100"><p class="help-text">Required only when this medicine will be received, dispensed, or adjusted by box. Use the actual number on the package.</p></div>
    <div class="field"><label class="required" for="status">Record status</label><select class="select" id="status" name="status" required><option value="active" @selected(old('status',$medicine->status)==='active')>Active</option><option value="inactive" @selected(old('status',$medicine->status)==='inactive')>Inactive</option></select></div>
</div></div></div>
<div class="grid grid-2" style="margin-top:18px">
<div class="card"><div class="card-header"><div><h2 class="card-title">Stock thresholds</h2><p class="card-subtitle">Used for alerts and reorder recommendations.</p></div></div><div class="card-body"><div class="form-grid">
    <div class="field"><label class="required" for="minimum_stock_level">Minimum stock</label><input class="input" id="minimum_stock_level" name="minimum_stock_level" type="number" min="0" value="{{ old('minimum_stock_level',$medicine->minimum_stock_level) }}" required></div>
    <div class="field"><label class="required" for="reorder_level">Reorder level</label><input class="input" id="reorder_level" name="reorder_level" type="number" min="0" value="{{ old('reorder_level',$medicine->reorder_level) }}" required></div>
</div></div></div>
<div class="card"><div class="card-header"><div><h2 class="card-title">Cost and storage</h2><p class="card-subtitle">Reference values; each batch keeps its actual received cost.</p></div></div><div class="card-body"><div class="form-grid">
    <div class="field"><label class="required" for="unit_cost">Default unit cost (₱)</label><input class="input" id="unit_cost" name="unit_cost" type="number" min="0" step="0.01" value="{{ old('unit_cost',$medicine->unit_cost) }}" required></div>
    <div class="field"><label for="reference_price">Reference price (₱)</label><input class="input" id="reference_price" name="reference_price" type="number" min="0" step="0.01" value="{{ old('reference_price',$medicine->reference_price) }}"></div>
</div></div></div></div>
<div class="card" style="margin-top:18px"><div class="card-header"><h2 class="card-title">Description</h2></div><div class="card-body"><div class="field"><label for="description">Medicine notes</label><textarea class="textarea" id="description" name="description">{{ old('description',$medicine->description) }}</textarea></div><div class="form-actions"><a class="btn btn-secondary" href="{{ $editing ? route('medicines.show',$medicine) : route('medicines.index') }}">Cancel</a><button id="saveButton" class="btn btn-primary" type="button"><x-icon name="check" class="icon-sm" /> {{ $editing ? 'Save changes' : 'Add medicine' }}</button></div></div></div>
</form>

@if($editing)
<div id="pinModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:white;padding:24px;border-radius:8px;max-width:400px;width:90%;box-shadow:0 4px 6px rgba(0,0,0,0.1);">
        <h3 style="margin:0 0 16px 0;font-size:18px;">Authorization Required</h3>
        <p style="margin:0 0 16px 0;color:#666;">Enter the four-digit PIN configured by an administrator to save your changes.</p>
        <div class="field" style="margin-bottom:16px;">
            <label class="required" for="modal_authorization_pin">Authorization PIN</label>
            <input class="input" id="modal_authorization_pin" type="password" inputmode="numeric" pattern="[0-9]{4}" minlength="4" maxlength="4" autocomplete="off" placeholder="••••" style="width:100%;">
            <p id="pinError" style="color:#dc2626;font-size:14px;margin-top:4px;display:none;">@error('authorization_pin'){{ $message }}@enderror</p>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;">
            <button id="cancelPinButton" class="btn btn-secondary" type="button">Cancel</button>
            <button id="confirmPinButton" class="btn btn-primary" type="button">Confirm</button>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const saveButton = document.getElementById('saveButton');
    const pinModal = document.getElementById('pinModal');
    const cancelPinButton = document.getElementById('cancelPinButton');
    const confirmPinButton = document.getElementById('confirmPinButton');
    const modalPinInput = document.getElementById('modal_authorization_pin');
    const pinError = document.getElementById('pinError');
    const medicineForm = document.getElementById('medicineForm');

    saveButton.addEventListener('click', function(e) {
        e.preventDefault();
        pinModal.style.display = 'flex';
        modalPinInput.value = '';
        pinError.style.display = 'none';
        modalPinInput.focus();
    });

    cancelPinButton.addEventListener('click', function() {
        pinModal.style.display = 'none';
    });

    confirmPinButton.addEventListener('click', function() {
        const pinValue = modalPinInput.value;
        if (pinValue.length !== 4 || !/^\d+$/.test(pinValue)) {
            pinError.textContent = 'The authorization PIN must be 4 digits.';
            pinError.style.display = 'block';
            return;
        }

        const pinInput = document.createElement('input');
        pinInput.type = 'hidden';
        pinInput.name = 'authorization_pin';
        pinInput.value = pinValue;
        medicineForm.appendChild(pinInput);

        pinModal.style.display = 'none';
        medicineForm.submit();
    });

    modalPinInput.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            confirmPinButton.click();
        }
    });

    pinModal.addEventListener('click', function(e) {
        if (e.target === pinModal) {
            pinModal.style.display = 'none';
        }
    });
});
</script>
@endif
