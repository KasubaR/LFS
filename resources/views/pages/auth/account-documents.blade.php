@extends('layouts.app')

@section('content')
@php
  $documentGroups = $documentGroups ?? [];
  $hasPaidMembership = $hasPaidMembership ?? false;
@endphp

<x-account-shell
  active-tab="documents"
  title="Document Library"
  :user="$user"
  :membership="$membership ?? null"
  :status="$status ?? null"
>
  @if(!$hasPaidMembership)
    <div class="account-empty" role="status">
      <div class="account-empty__icon" aria-hidden="true"><i class="fas fa-lock"></i></div>
      <h2 class="account-empty__title">Documents unlock after payment</h2>
      <p class="account-empty__text">
        The document library becomes available once your membership payment is confirmed.
      </p>
    </div>
  @elseif(empty($documentGroups))
    <div class="account-empty" role="status">
      <div class="account-empty__icon" aria-hidden="true"><i class="fas fa-folder-open"></i></div>
      <h2 class="account-empty__title">No documents yet</h2>
      <p class="account-empty__text">
        Member documents will appear here when LFS publishes them.
      </p>
    </div>
  @else
    <div class="account-document-library">
      @foreach($documentGroups as $category => $documents)
        <section class="account-document-group" aria-labelledby="doc-group-{{ $category }}">
          <h2 class="account-document-group__title" id="doc-group-{{ $category }}">
            {{ $documents[0]['categoryLabel'] ?? ucfirst(str_replace('_', ' ', $category)) }}
          </h2>
          <ul class="account-document-list">
            @foreach($documents as $document)
              <li class="account-document-card">
                <div class="account-document-card__main">
                  <h3 class="account-document-card__title">{{ $document['title'] }}</h3>
                  @if(!empty($document['description']))
                    <p class="account-document-card__desc">{{ $document['description'] }}</p>
                  @endif
                  <p class="account-document-card__meta">
                    {{ $document['originalFilename'] }}
                    @if(($document['fileSize'] ?? 0) > 0)
                      · {{ number_format(((int) $document['fileSize']) / 1024, 0) }} KB
                    @endif
                  </p>
                </div>
                <div class="account-document-card__side">
                  <a
                    href="{{ route('account.documents.download', $document['id']) }}"
                    class="account-document-card__link"
                    data-full-reload
                  >
                    <i class="fas fa-download" aria-hidden="true"></i> Download
                  </a>
                </div>
              </li>
            @endforeach
          </ul>
        </section>
      @endforeach
    </div>
  @endif
</x-account-shell>
@endsection
