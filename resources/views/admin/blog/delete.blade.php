@extends('layouts.admin')

@section('content')
@php
$p = $post ?? [];
@endphp

<div style="max-width:540px; margin:0 auto; padding:2rem 0;">

  <!-- Warning card -->
  <div style="background:rgba(220,50,50,0.06); border:1px solid rgba(220,50,50,0.25); border-radius:12px; padding:2rem;">

    <div style="display:flex; align-items:center; gap:0.75rem; margin-bottom:1.5rem;">
      <div style="width:42px; height:42px; border-radius:50%; background:rgba(220,50,50,0.15); display:flex; align-items:center; justify-content:center; flex-shrink:0;">
        <i class="fas fa-trash" style="color:#dc3232; font-size:1.1rem;" aria-hidden="true"></i>
      </div>
      <div>
        <h2 style="margin:0; font-size:1.05rem; color:var(--off-white); font-weight:700;">Delete Post</h2>
        <p style="margin:0; font-size:0.82rem; color:var(--text-dim);">This action cannot be undone.</p>
      </div>
    </div>

    <!-- Post summary -->
    <div style="background:var(--black-soft); border:1px solid var(--border-mid); border-radius:8px; padding:1rem 1.1rem; margin-bottom:1.5rem;">
      <p style="margin:0 0 0.3rem; font-weight:600; color:var(--off-white); font-size:0.95rem;">
        {{ $p['title'] ?? 'Untitled' }}
      </p>
      @if(!empty($p['excerpt']))
        <p style="margin:0 0 0.6rem; font-size:0.83rem; color:var(--text-dim); line-height:1.5;">
          {{ mb_strimwidth($p['excerpt'], 0, 140, '…') }}
        </p>
      @endif
      <div style="display:flex; gap:0.75rem; flex-wrap:wrap; font-size:0.78rem; color:var(--text-dim);">
        <span><i class="fas fa-tag" aria-hidden="true"></i> {{ $p['category'] ?? '—' }}</span>
        <span><i class="fas fa-user" aria-hidden="true"></i> {{ $p['author'] ?? '—' }}</span>
        <span>
          @if(($p['status'] ?? '') === 'published')
            <span style="color:var(--green-bright,#7ecb93);"><i class="fas fa-circle-check" aria-hidden="true"></i> Published</span>
          @else
            <i class="fas fa-file-pen" aria-hidden="true"></i> Draft
          @endif
        </span>
      </div>
    </div>

    <p style="font-size:0.88rem; color:var(--text-dim); margin-bottom:1.25rem; line-height:1.6;">
      Are you sure you want to permanently delete this post?
      The featured image file will also be removed from the server.
    </p>

    <!-- Actions -->
    <div style="display:flex; gap:0.75rem; flex-wrap:wrap;">
      <form method="POST"
            action="/admin/blog/{{ $p['id'] ?? '' }}/delete">
        <input type="hidden" name="_csrf" value="{{ $csrfToken ?? '' }}">
        <button type="submit"
                style="padding:0.65rem 1.4rem; background:#c0392b; color:#fff; border:none; border-radius:8px; font-family:var(--font-body); font-size:0.9rem; font-weight:600; cursor:pointer;">
          <i class="fas fa-trash" aria-hidden="true"></i> Yes, delete permanently
        </button>
      </form>
      <a href="/admin/blog/list"
         style="padding:0.65rem 1.2rem; background:var(--black-soft); border:1px solid var(--border-mid); border-radius:8px; color:var(--off-white); text-decoration:none; font-size:0.9rem; display:inline-flex; align-items:center;">
        Cancel
      </a>
    </div>

  </div>
</div>

@endsection
