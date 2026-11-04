@extends('layouts.admin')

@section('content')
<div id="attendance-app"
     data-search-url="/admin/events/{{ $event->id }}/attendance/search"
     data-present-url="/admin/events/{{ $event->id }}/tickets/__ID__/present"
     data-undo-url="/admin/events/{{ $event->id }}/tickets/__ID__/undo-present"
     data-min="{{ $minSearch }}">

  <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.75rem; margin-bottom:1rem;">
    <div>
      <h2 style="margin:0; font-size:1.25rem;">{{ $event->title }}</h2>
      <p style="margin:0.15rem 0 0; color:var(--text-dim); font-size:0.85rem;">
        {{ $event->event_date?->format('l, j F Y \a\t H:i') }}@if($event->location) · {{ $event->location }}@endif
      </p>
    </div>
    <div style="text-align:right;">
      <div style="font-size:0.75rem; text-transform:uppercase; color:var(--text-dim);">Present</div>
      <div style="font-size:1.75rem; font-weight:700;"><span id="present-count">{{ $stats['present'] }}</span><small style="opacity:0.6;"> / <span id="sold-count">{{ $stats['ticketsSold'] }}</span></small></div>
    </div>
  </div>

  <label for="att-search" style="display:block; font-size:0.8rem; text-transform:uppercase; color:var(--text-dim); margin-bottom:0.35rem;">
    Find the customer: name, order number, email or ticket reference
  </label>
  <input id="att-search" type="search" class="admin-input" autocomplete="off" autofocus
         placeholder="Start typing…" style="width:100%; font-size:1.15rem; padding:0.9rem 1rem; margin-bottom:0.75rem;">

  <div id="att-msg" role="status" aria-live="polite" style="min-height:1.5rem; margin-bottom:0.75rem; font-size:0.9rem;"></div>
  <div id="att-results"></div>

  <p style="color:var(--text-dim); font-size:0.8rem; margin-top:1.5rem;">
    At the door: confirm the customer's <strong>email</strong> against the order, confirm the <strong>number of tickets</strong>,
    then mark each person who arrives. Member tickets are personal: ask for the membership card or ID.
    Only paid orders can be admitted. <a href="/admin/events/{{ $event->id }}/tickets" style="color:var(--green-bright);">Back to ticket list</a>
  </p>
</div>
@endsection
