@extends('layouts.admin')

@section('content')
@php
$breadcrumbs = $breadcrumbs ?? [];
$batches = $batches ?? [];
$importResult = session('import_result');
$importError = session('import_error');
$importStatus = session('import_status');
@endphp

<div class="admin-page-header admin-page-header--row">
  <h2 class="admin-page-header__heading">Import Members</h2>
  <a href="/admin/members" class="admin-btn admin-btn--ghost">
    <i class="fas fa-arrow-left" aria-hidden="true"></i> Back to Members
  </a>
</div>

@if($importError)
  <div class="admin-alert admin-alert--error">{{ $importError }}</div>
@endif

@if($importStatus)
  <div class="admin-alert admin-alert--success">{{ $importStatus }}</div>
@endif

@if($importResult)
  <div class="admin-card" style="margin-bottom:1.5rem;">
    <h3 class="admin-card__title">Import summary</h3>
    <p>Total rows: {{ $importResult['totalRows'] ?? 0 }}</p>
    <p>Imported: {{ $importResult['importedRows'] ?? 0 }}</p>
    <p>Skipped: {{ $importResult['skippedRows'] ?? 0 }}</p>
    <p>Errors: {{ $importResult['errorRows'] ?? 0 }}</p>

    @if(($importResult['importedRows'] ?? 0) > 0)
      <div class="admin-alert admin-alert--info" style="margin-top:1rem;">
        Imported accounts sign in with the shared temporary password configured in
        <code>MEMBER_IMPORT_TEMP_PASSWORD</code>. It's valid for these accounts until
        <strong>{{ \Illuminate\Support\Carbon::parse($importResult['tempPasswordExpiresAt'])->format('j M Y, H:i') }}</strong>
        — after that, or as soon as an account uses it, that member is required to set their own password.
        @if($importResult['welcomeEmailSent'] ?? false)
          Welcome emails with sign-in instructions were sent to imported members.
        @else
          Welcome emails were <strong>not</strong> sent — make sure imported members know the shared temporary
          password some other way before it expires.
        @endif
      </div>
    @endif

    @if(!empty($importResult['errors']))
      <h4 style="margin-top:1rem;">Skipped / issues</h4>
      <ul>
        @foreach(array_slice($importResult['errors'], 0, 20) as $error)
          <li>{{ $error }}</li>
        @endforeach
      </ul>
    @endif
  </div>
@endif

<div class="admin-card" style="margin-bottom:1.5rem;">
  <h3 class="admin-card__title">Upload file</h3>
  <p class="admin-text-dim">Upload Excel (.xlsx) or CSV export. Uses the <strong>All</strong> sheet for member rows.</p>

  <form method="post" action="{{ url('/admin/members/import') }}" enctype="multipart/form-data" style="margin-top:1rem;">
    @csrf
    <div style="margin-bottom:1rem;">
      <label for="import_file" class="admin-label">Member file</label>
      <input type="file" id="import_file" name="import_file" accept=".csv,.xlsx,.xls" required>
    </div>
    <label class="admin-checkbox" style="display:flex; align-items:center; gap:0.5rem; margin-bottom:1rem;">
      <input type="checkbox" name="send_welcome_email" value="1">
      Send welcome email (includes the shared temporary password and its expiry)
    </label>
    <button type="submit" class="admin-btn admin-btn--primary">
      <i class="fas fa-upload" aria-hidden="true"></i> Import Members
    </button>
  </form>
</div>

@if(!empty($batches))
  <div class="admin-card">
    <h3 class="admin-card__title">Recent imports</h3>
    <div class="admin-table-wrap">
      <table class="admin-table">
        <thead>
          <tr>
            <th>File</th>
            <th>Imported</th>
            <th>Skipped</th>
            <th>Date</th>
            <th>Status</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          @foreach($batches as $batch)
            <tr>
              <td>{{ $batch->filename }}</td>
              <td>{{ $batch->imported_rows }}</td>
              <td>{{ $batch->skipped_rows }}</td>
              <td>{{ $batch->imported_at?->format('j M Y H:i') }}</td>
              <td>{{ $batch->status }}</td>
              <td>
                @if($batch->status !== 'rolled_back')
                  <form method="post" action="{{ url('/admin/members/import/'.$batch->id.'/rollback') }}" style="display:inline;">
                    @csrf
                    <button type="submit" class="admin-btn admin-btn--ghost admin-btn--sm" onclick="return confirm('Rollback this import batch?')">
                      Rollback
                    </button>
                  </form>
                @endif
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  </div>
@endif

@endsection
