@extends('layouts.app')
@section('title', 'Admin Accounts')
@section('content')
<div class="page-header"><div><div class="breadcrumb"><a href="{{ route('dashboard') }}">Dashboard</a><x-icon name="chevron" class="icon-sm" /> Admin Accounts</div><h1 class="page-title">Admin account controls</h1><p class="page-subtitle">Approve access requests and reset staff passwords securely.</p></div></div>

<section class="card"><div class="card-header"><div><h2 class="card-title">Pending access requests</h2><p class="card-subtitle">Inactive accounts cannot sign in until you approve them.</p></div><span class="badge badge-warning">{{ $pendingUsers->count() }} pending</span></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Staff member</th><th>Role</th><th>Requested</th><th class="text-right">Action</th></tr></thead><tbody>@forelse($pendingUsers as $pendingUser)<tr><td><strong>{{ $pendingUser->name }}</strong><span class="table-secondary">{{ $pendingUser->email }}</span></td><td><span class="badge badge-info">{{ $pendingUser->role?->name ?? 'No role' }}</span></td><td>{{ $pendingUser->created_at->format('M d, Y') }}</td><td class="text-right"><form method="POST" action="{{ route('users.approve', $pendingUser) }}" data-confirm="Approve access for {{ $pendingUser->name }}?">@csrf @method('PUT')<button class="btn btn-primary" type="submit">Approve account</button></form></td></tr>@empty<tr><td colspan="4"><div class="empty-state"><h3>No pending requests</h3><p>New staff requests will appear here after email verification.</p></div></td></tr>@endforelse</tbody></table></div></section>

<section class="card" style="margin-top:18px"><div class="card-header"><div><h2 class="card-title">Staff account security</h2><p class="card-subtitle">Reset passwords or end all active sessions for pharmacist and staff accounts. Each action is recorded in the audit log.</p></div></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Staff member</th><th>Role</th><th>Status</th><th>Account security</th></tr></thead><tbody>@forelse($staffUsers as $staffUser)<tr><td><strong>{{ $staffUser->name }}</strong><span class="table-secondary">{{ $staffUser->email }}</span></td><td><span class="badge badge-info">{{ $staffUser->role?->name ?? 'No role' }}</span></td><td><x-status-badge :status="$staffUser->status" /></td><td><div class="table-actions"><form method="POST" action="{{ route('admin.accounts.password.reset', $staffUser) }}" class="table-actions" data-confirm="Reset the password for {{ $staffUser->name }}? Their active sessions will also end.">@csrf @method('PUT')<div class="input-wrap"><input class="input" style="min-width:180px" name="password" type="password" autocomplete="new-password" required placeholder="New strong password" id="password-{{ $staffUser->id }}"><button type="button" onclick="togglePassword('password-{{ $staffUser->id }}')" class="password-toggle" style="background:none;border:none;cursor:pointer;padding:4px;transition:opacity 0.2s;"><x-icon name="eye" /></button></div><div class="input-wrap"><input class="input" style="min-width:180px" name="password_confirmation" type="password" autocomplete="new-password" required placeholder="Confirm password" id="password-confirm-{{ $staffUser->id }}"><button type="button" onclick="togglePassword('password-confirm-{{ $staffUser->id }}')" class="password-toggle" style="background:none;border:none;cursor:pointer;padding:4px;transition:opacity 0.2s;"><x-icon name="eye" /></button></div><button class="btn btn-secondary" type="submit">Reset password</button></form><form method="POST" action="{{ route('admin.accounts.sessions.destroy', $staffUser) }}" data-confirm="End all active sessions for {{ $staffUser->name }}? They will need to sign in again.">@csrf @method('DELETE')<button class="btn btn-danger" type="submit">End sessions</button></form></div></td></tr>@empty<tr><td colspan="4"><div class="empty-state"><h3>No staff accounts found</h3></div></td></tr>@endforelse</tbody></table></div><x-pagination :paginator="$staffUsers" /></section>

<script>
function togglePassword(inputId) {
    const input = document.getElementById(inputId);
    const button = input.nextElementSibling;
    const icon = button.querySelector('svg');
    
    if (input.type === 'password') {
        input.type = 'text';
        icon.innerHTML = '<path d="M17.94 17.94A10.4 10.4 0 0 1 12 20c-7 0-11-6-11-6a10.4 10.4 0 0 1-5.94-2.06L1 1l22 22Z"/>';
    } else {
        input.type = 'password';
        icon.innerHTML = '<path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/><circle cx="12" cy="12" r="2.5"/>';
    }
}
</script>
@endsection
