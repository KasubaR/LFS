@extends('layouts.admin')

@section('content')
@php
  $flashSuccess = session('flash.success') ?? ($flash['success'] ?? null);
  $csrfToken = $csrfToken ?? csrf_token();
  $statusPill = match ((string) $election->status) {
      'open' => 'green',
      'closed', 'certified' => 'blue',
      'locked' => 'muted',
      'scheduled', 'ballot_approved', 'roll_locked' => 'orange',
      default => 'muted',
  };
  $breadcrumbs = [
      ['label' => 'Admin', 'url' => '/admin/dashboard'],
      ['label' => 'Elections', 'url' => '/admin/elections'],
      ['label' => $election->title],
  ];
@endphp

<div class="admin-page-header">
  <a href="{{ url('/admin/elections') }}" class="admin-page-header__back">
    <i class="fas fa-arrow-left" aria-hidden="true"></i> Back to Elections
  </a>
  <div class="admin-page-header--row">
    <div>
      <h2 class="admin-page-header__heading">{{ $election->title }}</h2>
      <p class="election-meta">
        <span class="status-pill status-pill--{{ $statusPill }}">{{ $statusLabel }}</span>
        <span class="election-meta__sep">·</span>
        {{ \App\Enums\ElectionType::label($election->type) }}
      </p>
    </div>
  </div>
</div>

@if($flashSuccess)
  <div class="sys-notif sys-notif--info" role="status" style="margin-bottom:1rem;">
    <i class="fas fa-circle-check" aria-hidden="true"></i>
    <span>{{ $flashSuccess }}</span>
  </div>
@endif
@if($errors->any())
  <div class="sys-notif sys-notif--error" role="alert" style="margin-bottom:1rem;">
    <i class="fas fa-triangle-exclamation" aria-hidden="true"></i>
    <span>{{ $errors->first() }}</span>
  </div>
@endif

{{-- Poll controls --}}
<div class="admin-card">
  <p class="admin-card__title">Poll controls</p>
  <div class="election-stats">
    <div class="election-stat">
      <span class="election-stat__label">Issued</span>
      <span class="election-stat__value">{{ $turnout['issued'] }}</span>
    </div>
    <div class="election-stat">
      <span class="election-stat__label">Used</span>
      <span class="election-stat__value">{{ $turnout['used'] }}</span>
    </div>
    <div class="election-stat">
      <span class="election-stat__label">Expired</span>
      <span class="election-stat__value">{{ $turnout['expired'] }}</span>
    </div>
  </div>
  <p class="election-hint">Turnout only while open — candidate totals stay hidden until close.</p>

  @if($canWrite)
  <div class="election-toolbar">
    @if($election->ballot_approved_at && !$election->isOpen() && !$election->isClosedOrLater())
      <form method="post" action="{{ url('/admin/elections/'.$election->id.'/open') }}" class="election-toolbar__item">
        @csrf
        <button class="admin-btn admin-btn--primary" type="submit" @disabled(! $canOpen)>
          <i class="fas fa-play" aria-hidden="true"></i> Open voting
        </button>
      </form>
      @unless($canOpen)
        <form method="post" action="{{ url('/admin/elections/'.$election->id.'/early-open-override') }}" class="election-inline-form">
          @csrf
          <div class="form-group">
            <label class="admin-label" for="early_reason">Early-open override reason</label>
            <input id="early_reason" class="admin-input" name="reason" required placeholder="Authorised reason">
          </div>
          <button class="admin-btn" type="submit">Record 48h override</button>
        </form>
      @endunless
    @endif

    @if($election->isOpen())
      <form method="post" action="{{ url('/admin/elections/'.$election->id.'/extend') }}" class="election-inline-form">
        @csrf
        <div class="form-group">
          <label class="admin-label" for="extend_close">New close time</label>
          <input id="extend_close" class="admin-input" type="datetime-local" name="scheduled_close_at" required>
        </div>
        <div class="form-group">
          <label class="admin-label" for="extend_reason">Reason</label>
          <input id="extend_reason" class="admin-input" name="reason" required placeholder="Extension reason">
        </div>
        <button class="admin-btn" type="submit">Extend</button>
      </form>
      <form method="post" action="{{ url('/admin/elections/'.$election->id.'/close') }}" class="election-toolbar__item" onsubmit="return confirm('Close voting for this election?');">
        @csrf
        <button class="admin-btn admin-btn--danger" type="submit">Close voting</button>
      </form>
    @endif

    @if(in_array($election->status, ['closed', 'certified'], true))
      <form method="post" action="{{ url('/admin/elections/'.$election->id.'/certify') }}" class="election-toolbar__item">
        @csrf
        <button class="admin-btn admin-btn--primary" type="submit">Certify results</button>
      </form>
    @endif

    @if($election->status === 'certified')
      <form method="post" action="{{ url('/admin/elections/'.$election->id.'/lock') }}" class="election-toolbar__item">
        @csrf
        <button class="admin-btn admin-btn--danger" type="submit">Permanent lock</button>
      </form>
    @endif

    @if($election->isClosedOrLater())
      <a class="admin-btn" href="{{ url('/admin/elections/'.$election->id.'/certificate') }}">
        <i class="fas fa-file-pdf" aria-hidden="true"></i> Certificate PDF
      </a>
      <a class="admin-btn" href="{{ url('/admin/elections/'.$election->id.'/participation') }}">
        <i class="fas fa-file-csv" aria-hidden="true"></i> Participation CSV
      </a>
    @endif
  </div>
  @endif
</div>

{{-- Quorum --}}
<div class="admin-card">
  <p class="admin-card__title">Quorum</p>
  <div class="election-stats">
    <div class="election-stat">
      <span class="election-stat__label">Good standing</span>
      <span class="election-stat__value">{{ $quorum['good_standing'] }}</span>
    </div>
    <div class="election-stat">
      <span class="election-stat__label">Attending</span>
      <span class="election-stat__value">{{ $quorum['attending'] }}</span>
    </div>
    <div class="election-stat">
      <span class="election-stat__label">By proxy</span>
      <span class="election-stat__value">{{ $quorum['represented_by_proxy'] }}</span>
    </div>
    <div class="election-stat">
      <span class="election-stat__label">Counted / required</span>
      <span class="election-stat__value">{{ $quorum['counted_for_quorum'] }} / {{ $quorum['quorum_required'] }}</span>
    </div>
  </div>
  <p class="election-hint">
    Quorum {{ $quorum['quorum_percent'] }}% —
    <strong>{{ $quorum['quorum_met'] ? 'met' : 'not met' }}</strong>
    · Confirmed: {{ $quorum['quorum_confirmed_at'] ?? '—' }}
  </p>
  @if($canWrite && $quorum['quorum_met'] && !$election->quorum_confirmed_at)
    <form method="post" action="{{ url('/admin/elections/'.$election->id.'/quorum/confirm') }}">
      @csrf
      <button class="admin-btn admin-btn--primary" type="submit">Confirm quorum</button>
    </form>
  @endif
</div>

{{-- Results --}}
@if($tally)
<div class="admin-card">
  <p class="admin-card__title">Results</p>
  @foreach($tally['positions'] as $pos)
    <div class="election-position">
      <h3 class="election-position__title">{{ $pos['title'] }}</h3>
      <div class="admin-table-wrap">
        <table class="admin-table admin-table--compact">
          <thead>
            <tr>
              <th>Candidate</th>
              <th>Votes</th>
            </tr>
          </thead>
          <tbody>
            @foreach($pos['candidates'] as $c)
              <tr>
                <td>{{ $c['name'] }}</td>
                <td>{{ $c['votes'] }}</td>
              </tr>
            @endforeach
            <tr><td>Abstentions</td><td>{{ $pos['abstentions'] }}</td></tr>
            <tr><td>Rejected</td><td>{{ $pos['rejected'] }}</td></tr>
            <tr><td>Incomplete</td><td>{{ $pos['incomplete'] }}</td></tr>
            <tr>
              <th>Winner</th>
              <td>{{ $pos['winner']['name'] ?? 'Tie / none' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  @endforeach
</div>
@endif

{{-- Settings --}}
@if($canWrite)
<div class="admin-card">
  <p class="admin-card__title">Election settings</p>
  <form method="post" action="{{ url('/admin/elections/'.$election->id) }}" class="election-settings-form">
    @csrf
    <input type="hidden" name="_csrf" value="{{ $csrfToken }}">

    <div class="form-group">
      <label class="admin-label" for="settings_title">Title</label>
      <input id="settings_title" class="admin-input" name="title" value="{{ old('title', $election->title) }}" required>
    </div>

    <div class="form-group">
      <label class="admin-label" for="settings_type">Type</label>
      <select id="settings_type" class="admin-input" name="type">
        @foreach($types as $value => $label)
          <option value="{{ $value }}" @selected(old('type', $election->type) === $value)>{{ $label }}</option>
        @endforeach
      </select>
    </div>

    <div class="form-group">
      <label class="admin-label" for="settings_description">Description</label>
      <textarea id="settings_description" class="admin-input" name="description" rows="3">{{ old('description', $election->description) }}</textarea>
    </div>

    <div class="election-form-grid">
      <div class="form-group">
        <label class="admin-label" for="settings_open">Scheduled open</label>
        <input id="settings_open" class="admin-input" type="datetime-local" name="scheduled_open_at" value="{{ old('scheduled_open_at', optional($election->scheduled_open_at)->format('Y-m-d\\TH:i')) }}">
      </div>
      <div class="form-group">
        <label class="admin-label" for="settings_close">Scheduled close</label>
        <input id="settings_close" class="admin-input" type="datetime-local" name="scheduled_close_at" value="{{ old('scheduled_close_at', optional($election->scheduled_close_at)->format('Y-m-d\\TH:i')) }}">
      </div>
      <div class="form-group">
        <label class="admin-label" for="settings_quorum">Quorum %</label>
        <input id="settings_quorum" class="admin-input" type="number" name="quorum_percent" min="1" max="100" value="{{ old('quorum_percent', $election->quorum_percent) }}">
      </div>
    </div>

    <button class="admin-btn admin-btn--primary" type="submit">Save settings</button>
  </form>
</div>
@endif

{{-- Ballot --}}
<div class="admin-card">
  <p class="admin-card__title">Ballot — positions &amp; candidates</p>
  <p class="election-hint">
    Approved:
    <strong>{{ $election->ballot_approved_at?->format('d M Y, H:i') ?? 'Not yet' }}</strong>
  </p>

  @if($canWrite && $election->isBallotMutable())
    <form method="post" action="{{ url('/admin/elections/'.$election->id.'/positions') }}" class="election-inline-form">
      @csrf
      <div class="form-group">
        <label class="admin-label" for="position_title">New position</label>
        <input id="position_title" class="admin-input" name="title" placeholder="Position title" required>
      </div>
      <label class="admin-label election-checkbox">
        <input type="checkbox" name="allow_abstain" value="1">
        Allow abstain
      </label>
      <button class="admin-btn admin-btn--primary" type="submit">Add position</button>
    </form>
  @endif

  <div class="election-toolbar">
    @if($canWrite && $election->isRollLocked() && !$election->ballot_approved_at)
      <form method="post" action="{{ url('/admin/elections/'.$election->id.'/ballot/approve') }}">
        @csrf
        <button class="admin-btn admin-btn--primary" type="submit">Approve ballot (starts 48h clock)</button>
      </form>
    @endif
    @if($canWrite && $election->ballot_approved_at && !$election->isOpen() && !$election->isClosedOrLater())
      <form method="post" action="{{ url('/admin/elections/'.$election->id.'/ballot/unlock') }}">
        @csrf
        <button class="admin-btn" type="submit">Unlock ballot for edits</button>
      </form>
    @endif
  </div>

  @forelse($election->positions as $position)
    <div class="election-position">
      <div class="election-position__header">
        <h3 class="election-position__title">
          {{ $position->title }}
          @if($position->allow_abstain)
            <span class="status-pill status-pill--muted">Abstain allowed</span>
          @endif
        </h3>
        @if($canWrite && $election->isBallotMutable())
          <form method="post" action="{{ url('/admin/elections/'.$election->id.'/positions/'.$position->id.'/delete') }}" onsubmit="return confirm('Delete this position?');">
            @csrf
            <button class="admin-btn admin-btn--danger admin-btn--sm" type="submit">Delete position</button>
          </form>
        @endif
      </div>

      <ul class="election-candidate-list">
        @forelse($position->candidates as $candidate)
          <li class="election-candidate-list__item">
            <span>{{ $candidate->name }}</span>
            @if($canWrite && $election->isBallotMutable())
              <form method="post" action="{{ url('/admin/elections/'.$election->id.'/candidates/'.$candidate->id.'/delete') }}">
                @csrf
                <button class="admin-btn admin-btn--danger admin-btn--sm" type="submit">Remove</button>
              </form>
            @endif
          </li>
        @empty
          <li class="election-hint">No candidates yet.</li>
        @endforelse
      </ul>

      @if($canWrite && $election->isBallotMutable())
        <form method="post" action="{{ url('/admin/elections/'.$election->id.'/positions/'.$position->id.'/candidates') }}" class="election-inline-form">
          @csrf
          <div class="form-group">
            <label class="admin-label" for="candidate_{{ $position->id }}">Add candidate</label>
            <input id="candidate_{{ $position->id }}" class="admin-input" name="name" placeholder="Candidate name" required>
          </div>
          <button class="admin-btn admin-btn--sm admin-btn--primary" type="submit">Add</button>
        </form>
      @endif
    </div>
  @empty
    <p class="admin-empty">No positions yet.</p>
  @endforelse
</div>

{{-- Voters’ roll --}}
<div class="admin-card">
  <p class="admin-card__title">Voters’ roll</p>
  <p class="election-hint">
    Locked:
    <strong>{{ $election->roll_locked_at?->format('d M Y, H:i') ?? 'No' }}</strong>
    · Rows: {{ $election->voters->count() }}
  </p>

  @if($canWrite && !$election->isRollLocked())
      <p class="election-hint">
      Columns: <code>membership_number</code>, <code>email</code>, or <code>phone</code>
      (any one identifies the member — provide whichever you have),
      <code>name</code> (optional display).
      <a class="message-view__link" href="{{ url('/admin/elections/roll-template') }}">
        <i class="fas fa-download" aria-hidden="true"></i> Download Excel template
      </a>
    </p>
    <form method="post" action="{{ url('/admin/elections/'.$election->id.'/roll/import') }}" enctype="multipart/form-data" class="election-inline-form">
      @csrf
      <div class="form-group">
        <label class="admin-label" for="import_file">Upload roll (CSV / Excel)</label>
        <input id="import_file" class="admin-input" type="file" name="import_file" accept=".csv,.xlsx,.xls" required>
      </div>
      <button class="admin-btn admin-btn--primary" type="submit">Upload roll</button>
    </form>
    @if($election->voters->count())
      <form method="post" action="{{ url('/admin/elections/'.$election->id.'/roll/lock') }}" class="election-toolbar">
        @csrf
        <button class="admin-btn" type="submit">Lock final roll</button>
      </form>
    @endif
  @elseif($canWrite && $election->isRollLocked() && !$election->isOpen() && !$election->isClosedOrLater())
    <form method="post" action="{{ url('/admin/elections/'.$election->id.'/roll/unlock') }}">
      @csrf
      <button class="admin-btn" type="submit">Unlock roll</button>
    </form>
  @endif

  <div class="admin-table-wrap election-table-wrap">
    <table class="admin-table">
      <thead>
        <tr>
          <th>Name</th>
          <th>Membership #</th>
          <th>Email</th>
          <th>Match</th>
          <th>Proxy</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        @forelse($election->voters->take(200) as $voter)
          @php
            $matchPill = match ($voter->match_status) {
                'matched' => 'green',
                'ambiguous' => 'orange',
                default => 'red',
            };
          @endphp
          <tr>
            <td>{{ $voter->raw_name ?: '—' }}</td>
            <td>{{ $voter->raw_membership_number ?: '—' }}</td>
            <td>{{ $voter->raw_email ?: '—' }}</td>
            <td>
              <span class="status-pill status-pill--{{ $matchPill }}">{{ $voter->match_status }}</span>
              @if($voter->excluded_at)
                <span class="status-pill status-pill--muted">excluded</span>
              @endif
            </td>
            <td>{{ $voter->represented_by_proxy ? 'Yes' : 'No' }}</td>
            <td class="cell-actions">
              @if($canWrite && !$voter->excluded_at)
                <form method="post" action="{{ url('/admin/elections/'.$election->id.'/voters/'.$voter->id.'/exclude') }}">
                  @csrf
                  <button class="admin-btn admin-btn--sm" type="submit">Exclude</button>
                </form>
              @endif
              @if($canWrite && !$election->isRollLocked() && $voter->match_status !== 'matched')
                <form method="post" action="{{ url('/admin/elections/'.$election->id.'/voters/'.$voter->id.'/link') }}" class="election-inline-form js-user-picker-form">
                  @csrf
                  <div class="js-user-picker election-user-picker">
                    <input type="text" class="admin-input js-user-picker-input" placeholder="Search name or email" autocomplete="off">
                    <input type="hidden" name="user_id" class="js-user-picker-value" required>
                    <div class="js-user-picker-results election-user-picker__results" hidden></div>
                  </div>
                  <button class="admin-btn admin-btn--sm admin-btn--primary" type="submit">Link</button>
                </form>
              @endif
            </td>
          </tr>
        @empty
          <tr>
            <td colspan="6" class="admin-empty">No voters imported yet.</td>
          </tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

{{-- Proxies --}}
<div class="admin-card">
  <p class="admin-card__title">Proxies</p>

  @if($canWrite && $election->isRollLocked() && !$election->isOpen())
    <form method="post" action="{{ url('/admin/elections/'.$election->id.'/proxies') }}" class="election-inline-form js-user-picker-form">
      @csrf
      <div class="form-group">
        <label class="admin-label" for="grantor_voter_id">Grantor (on roll)</label>
        <select id="grantor_voter_id" class="admin-input" name="grantor_voter_id" required>
          <option value="">Select…</option>
          @foreach($election->voters->where('match_status', 'matched')->whereNull('excluded_at')->where('represented_by_proxy', false) as $v)
            <option value="{{ $v->id }}">{{ $v->raw_name ?: $v->raw_email }} ({{ $v->raw_membership_number }})</option>
          @endforeach
        </select>
      </div>
      <div class="form-group">
        <label class="admin-label">Proxy holder</label>
        <div class="js-user-picker election-user-picker">
          <input type="text" class="admin-input js-user-picker-input" placeholder="Search name or email" autocomplete="off" required>
          <input type="hidden" name="holder_user_id" class="js-user-picker-value" required>
          <div class="js-user-picker-results election-user-picker__results" hidden></div>
        </div>
      </div>
      <button class="admin-btn admin-btn--primary" type="submit">Approve proxy</button>
    </form>
  @endif

  @if($election->proxies->isEmpty())
    <p class="admin-empty">No proxies approved.</p>
  @else
    <div class="admin-table-wrap">
      <table class="admin-table admin-table--compact">
        <thead>
          <tr>
            <th>Grantor</th>
            <th>Holder</th>
            <th>Status</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          @foreach($election->proxies as $proxy)
            <tr>
              <td>{{ $proxy->grantor?->raw_name ?: $proxy->grantor?->raw_email ?: '—' }}</td>
              <td>
                @if($proxy->holder)
                  {{ trim(($proxy->holder->other_names ?? '').' '.($proxy->holder->last_name ?? '')) }}
                  <span class="election-hint">({{ $proxy->holder->email }})</span>
                @else
                  #{{ $proxy->holder_user_id }}
                @endif
              </td>
              <td>
                <span class="status-pill status-pill--{{ $proxy->status === 'approved' ? 'green' : 'muted' }}">
                  {{ $proxy->status }}
                </span>
              </td>
              <td class="cell-actions">
                @if($canWrite && $proxy->status === 'approved' && !$election->isOpen())
                  <form method="post" action="{{ url('/admin/elections/'.$election->id.'/proxies/'.$proxy->id.'/revoke') }}">
                    @csrf
                    <button class="admin-btn admin-btn--sm" type="submit">Revoke</button>
                  </form>
                @endif
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  @endif
</div>

{{-- Complaints --}}
<div class="admin-card">
  <p class="admin-card__title">Complaints</p>

  @if($canWrite)
    <form method="post" action="{{ url('/admin/elections/'.$election->id.'/complaints') }}" class="election-inline-form">
      @csrf
      <div class="form-group">
        <label class="admin-label" for="complaint_body">Log complaint</label>
        <textarea id="complaint_body" class="admin-input" name="body" rows="3" required placeholder="Technical or electoral issue"></textarea>
      </div>
      <button class="admin-btn" type="submit">Log complaint</button>
    </form>
  @endif

  @if($election->complaints->isEmpty())
    <p class="admin-empty">No complaints logged.</p>
  @else
    <ul class="election-complaint-list">
      @foreach($election->complaints as $complaint)
        <li>
          <strong>{{ $complaint->reporter_name ?? 'Anonymous' }}</strong>
          <span class="status-pill status-pill--muted">{{ $complaint->status }}</span>
          <p>{{ $complaint->body }}</p>
        </li>
      @endforeach
    </ul>
  @endif
</div>

<script>
(function () {
  document.querySelectorAll('.js-user-picker').forEach(function (wrap) {
    var input = wrap.querySelector('.js-user-picker-input');
    var hidden = wrap.querySelector('.js-user-picker-value');
    var results = wrap.querySelector('.js-user-picker-results');
    var timer = null;
    var requestId = 0;

    function clearResults() {
      results.hidden = true;
      results.innerHTML = '';
    }

    function selectUser(id, label) {
      hidden.value = id;
      input.value = label;
      clearResults();
    }

    input.addEventListener('input', function () {
      hidden.value = '';
      var q = input.value.trim();
      if (timer) clearTimeout(timer);
      if (q.length < 2) {
        clearResults();
        return;
      }
      timer = setTimeout(function () {
        var thisRequest = ++requestId;
        fetch('/admin/elections/users/search?q=' + encodeURIComponent(q), {
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
        })
          .then(function (r) { return r.json(); })
          .then(function (users) {
            if (thisRequest !== requestId) return;
            results.innerHTML = '';
            if (!users.length) {
              results.innerHTML = '<div class="election-user-picker__empty">No members found</div>';
              results.hidden = false;
              return;
            }
            users.forEach(function (u) {
              var row = document.createElement('button');
              row.type = 'button';
              row.className = 'election-user-picker__option';
              row.textContent = u.label;
              row.addEventListener('click', function () { selectUser(u.id, u.label); });
              results.appendChild(row);
            });
            results.hidden = false;
          })
          .catch(function () { clearResults(); });
      }, 250);
    });

    document.addEventListener('click', function (e) {
      if (!wrap.contains(e.target)) clearResults();
    });
  });

  document.querySelectorAll('.js-user-picker-form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      var hidden = form.querySelector('.js-user-picker-value');
      if (hidden && !hidden.value) {
        e.preventDefault();
        alert('Pick a member from the search results before continuing.');
      }
    });
  });
})();
</script>
@endsection
