<x-account-shell active-tab="elections" :title="$election->title" :user="$user" :membership="$membership ?? null">
  <div class="account-panel">
    <h1>{{ $election->title }}</h1>
    <p>{{ \App\Enums\ElectionStatus::label($election->status) }}</p>

    @if(session('status'))
      <p class="account-alert account-alert--success">{{ session('status') }}</p>
    @endif
    @if($errors->any())
      <div class="account-alert account-alert--error">
        <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
      </div>
    @endif

    <section>
      <h2>Meeting attendance</h2>
      <p>Checking in records that you are present at the EGM. It does <strong>not</strong> cast a vote.</p>
      @if($attended)
        <p>You are checked in.</p>
      @elseif(($onRoll ?? false) && in_array($election->status, ['scheduled', 'ballot_approved', 'open'], true))
        <form method="post" action="{{ route('account.elections.check-in', $election->id) }}">
          @csrf
          <button type="submit" class="btn btn-primary">Check in to the meeting</button>
        </form>
      @elseif(! ($onRoll ?? false))
        <p>Check-in is only available to members on the voters&rsquo; roll for this meeting.</p>
      @endif
    </section>

    <section>
      <h2>Your ballots</h2>
      @if($election->status !== 'open')
        <p>Voting is not open{{ $election->isClosedOrLater() ? ' (polls closed).' : ' yet.' }}</p>
      @elseif($entitlements->isEmpty())
        <p>You have no remaining ballot entitlements for this election.</p>
      @else
        @foreach($entitlements as $entitlement)
          <article class="election-ballot" style="margin-bottom:1.5rem;padding-bottom:1.5rem;border-bottom:1px solid #ddd;">
            <h3>{{ $entitlement->position->title }}</h3>
            <p>
              @if($entitlement->type === 'proxy')
                Voting by proxy for another member
              @else
                Your direct ballot
              @endif
            </p>
            <form method="post" action="{{ route('account.elections.cast', [$election->id, $entitlement->id]) }}">
              @csrf
              @foreach($entitlement->position->candidates->where('is_active', true) as $candidate)
                <label style="display:block;margin:.35rem 0;">
                  <input type="radio" name="choice" value="candidate:{{ $candidate->id }}" required>
                  {{ $candidate->name }}
                </label>
              @endforeach
              @if($entitlement->position->allow_abstain)
                <label style="display:block;margin:.35rem 0;">
                  <input type="radio" name="choice" value="abstain">
                  Abstain
                </label>
              @endif
              <label style="display:block;margin:1rem 0;">
                <input type="checkbox" name="confirm" value="1" required>
                I confirm this is my final choice and cannot be changed after submission.
              </label>
              <button type="submit" class="btn btn-primary">Submit ballot</button>
            </form>
          </article>
        @endforeach
      @endif
    </section>

    @if($results)
      <section>
        <h2>Certified results</h2>
        @foreach($results['positions'] as $pos)
          <h3>{{ $pos['title'] }}</h3>
          <ul>
            @foreach($pos['candidates'] as $c)
              <li>{{ $c['name'] }}: {{ $c['votes'] }}</li>
            @endforeach
            <li>Abstentions: {{ $pos['abstentions'] }}</li>
            <li>Winner: {{ $pos['winner']['name'] ?? 'Tie / none' }}</li>
          </ul>
        @endforeach
      </section>
    @endif

    <section>
      <h2>Report a problem</h2>
      <form method="post" action="{{ route('account.elections.complaint', $election->id) }}">
        @csrf
        <textarea name="body" rows="3" required placeholder="Describe a technical or electoral issue"></textarea>
        <button type="submit" class="btn">Submit complaint</button>
      </form>
    </section>
  </div>
</x-account-shell>
