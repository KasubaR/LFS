<x-account-shell active-tab="elections" title="Elections" :user="$user" :membership="$membership ?? null">
  <div class="account-panel">
    <h1>Elections</h1>
    @if(session('status'))
      <p class="account-alert account-alert--success">{{ session('status') }}</p>
    @endif
    @if($elections->isEmpty())
      <p>No elections are available for your account right now.</p>
    @else
      <ul class="account-list">
        @foreach($elections as $election)
          <li>
            <a href="{{ route('account.elections.show', $election->id) }}">
              <strong>{{ $election->title }}</strong>
              <span>{{ \App\Enums\ElectionStatus::label($election->status) }}</span>
            </a>
          </li>
        @endforeach
      </ul>
    @endif
  </div>
</x-account-shell>
