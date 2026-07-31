@extends('layouts.admin')

@section('content')
@php
$isEdit = $isEdit ?? ($event !== null);
$ev     = $event  ?? [];

$distRows = [];
if (isset($_POST['dist_label']) && is_array($_POST['dist_label'])) {
    $ex = $_POST['dist_route_existing'] ?? [];
    if (!is_array($ex)) {
        $ex = [];
    }
    foreach ($_POST['dist_label'] as $i => $lbl) {
        $p = (string) ($ex[$i] ?? '');
        $distRows[] = [
            'label'      => (string) $lbl,
            'routeImage' => $p !== '' ? $p : null,
        ];
    }
} elseif (!empty($ev['distanceRoutes']) && is_array($ev['distanceRoutes'])) {
    foreach ($ev['distanceRoutes'] as $dr) {
        $distRows[] = [
            'label'      => (string) ($dr['label'] ?? ''),
            'routeImage' => $dr['routeImage'] ?? null,
        ];
    }
}
if ($distRows === []) {
    $distRows[] = ['label' => '', 'routeImage' => null];
}

$pageTitle  = $pageTitle  ?? ($isEdit ? 'Edit Event' : 'New Event');
$activePage = 'events';
$breadcrumbs = $breadcrumbs ?? [
    ['label' => 'Admin',  'url' => '/admin'],
    ['label' => 'Events', 'url' => '/admin/events'],
    ['label' => $isEdit ? ($ev['title'] ?? 'Edit') : 'New Event'],
];

$cats = $eventCategories ?? [];

/**
 * Format a datetime string to datetime-local input value (YYYY-MM-DDTHH:mm).
 * Uses UTC so server timezone doesn't shift values on round-trip.
 */
if (! function_exists('toDateTimeLocal')) {
    function toDateTimeLocal(?string $d): string {
        if (!$d) return '';
        $ts = strtotime($d);
        if ($ts === false) return '';
        return gmdate('Y-m-d\TH:i', $ts);
    }
}
@endphp

@if(!empty($error))
  <div class="gallery-error-banner" role="alert" style="margin-bottom:1rem;">
    <i class="fas fa-triangle-exclamation"></i>
    <span>{{ $error }}</span>
  </div>
@endif

<div style="max-width:720px;">
  <form method="POST"
        action="{{ $isEdit ? '/admin/events/' . htmlspecialchars($ev['id']) : '/admin/events' }}"
        id="eventForm"
        enctype="multipart/form-data">

    <input type="hidden" name="_csrf" value="{{ $csrfToken ?? '' }}">

    <!-- Title -->
    <div class="form-group" style="margin-bottom:1.25rem;">
      <label class="form-label" for="title">Title <span style="color:var(--flag-red);">*</span></label>
      <input type="text" id="title" name="title" required
             value="{{ $ev['title'] ?? '' }}"
             placeholder="e.g. LFS Saturday LSD Run"
             style="width:100%; padding:0.6rem 0.85rem; background:var(--black-soft); border:1px solid var(--border-mid); border-radius:8px; color:var(--off-white); font-family:var(--font-body); font-size:0.9rem;" />
    </div>

    <!-- Slug -->
    <div class="form-group" style="margin-bottom:1.25rem;">
      <label class="form-label" for="slug">Slug</label>
      <input type="text" id="slug" name="slug"
             value="{{ $ev['slug'] ?? '' }}"
             placeholder="e.g. lfs-saturday-lsd-run (leave blank to auto-generate from title)"
             style="width:100%; padding:0.6rem 0.85rem; background:var(--black-soft); border:1px solid var(--border-mid); border-radius:8px; color:var(--off-white); font-family:var(--font-body); font-size:0.9rem;" />
      <small style="display:block; margin-top:0.35rem; color:var(--text-dim); font-size:0.8rem;">
        URL-friendly; used in /events/:slug. Auto-generated from title if empty on create.
      </small>
    </div>

    <!-- Description -->
    <div class="form-group" style="margin-bottom:1.25rem;">
      <label class="form-label" for="description">Description</label>
      <textarea id="description" name="description" rows="4" placeholder="Event description"
                style="width:100%; padding:0.6rem 0.85rem; background:var(--black-soft); border:1px solid var(--border-mid); border-radius:8px; color:var(--off-white); font-family:var(--font-body); font-size:0.9rem; resize:vertical;"
      >{{ $ev['description'] ?? '' }}</textarea>
    </div>

    <!-- Location -->
    <div class="form-group" style="margin-bottom:1.25rem;">
      <label class="form-label" for="location">Location</label>
      <input type="text" id="location" name="location"
             value="{{ $ev['location'] ?? '' }}"
             placeholder="e.g. Arcades, Lusaka"
             style="width:100%; padding:0.6rem 0.85rem; background:var(--black-soft); border:1px solid var(--border-mid); border-radius:8px; color:var(--off-white); font-family:var(--font-body); font-size:0.9rem;" />
    </div>

    <!-- Recurrence -->
    @php
      $recType = $ev['recurrenceType'] ?? 'none';
      $recDays = array_filter(array_map('trim', explode(',', $ev['recurrenceDays'] ?? '')));
      $allDays = ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'];
      $dayLabels = ['monday'=>'Mon','tuesday'=>'Tue','wednesday'=>'Wed','thursday'=>'Thu',
                    'friday'=>'Fri','saturday'=>'Sat','sunday'=>'Sun'];
    @endphp
    <div class="form-group" style="margin-bottom:1.25rem;">
      <label class="form-label">Recurrence</label>
      <div style="display:flex; gap:1.25rem; margin-top:0.4rem;">
        <label style="display:flex; align-items:center; gap:0.45rem; cursor:pointer; font-size:0.9rem; color:var(--off-white);">
          <input type="radio" name="recurrenceType" value="none" id="rec_none"
                 {{ $recType !== 'weekly' ? 'checked' : '' }}>
          One-off event
        </label>
        <label style="display:flex; align-items:center; gap:0.45rem; cursor:pointer; font-size:0.9rem; color:var(--off-white);">
          <input type="radio" name="recurrenceType" value="weekly" id="rec_weekly"
                 {{ $recType === 'weekly' ? 'checked' : '' }}>
          Repeats weekly
        </label>
      </div>

      <!-- Day checkboxes — shown only for weekly -->
      <div id="recurrence_days_wrap" style="margin-top:0.85rem; display:{{ $recType === 'weekly' ? 'flex' : 'none' }}; flex-wrap:wrap; gap:0.5rem;">
        @foreach($allDays as $d)
        <label style="display:flex; align-items:center; gap:0.35rem; cursor:pointer;
                       padding:0.4rem 0.8rem; background:var(--black-soft);
                       border:1px solid var(--border-mid); border-radius:6px;
                       font-size:0.82rem; color:var(--off-white); user-select:none;"
               class="rec-day-label" data-day="{{ $d }}">
          <input type="checkbox" name="recurrence_days[]" value="{{ $d }}"
                 {{ in_array($d, $recDays, true) ? 'checked' : '' }}>
          {{ $dayLabels[$d] }}
        </label>
        @endforeach
      </div>
    </div>

    <!-- Event date -->
    <div class="form-group" style="margin-bottom:1.25rem;" id="eventDate_wrap">
      <label class="form-label" for="eventDate" id="eventDate_label">
        Event date <span style="color:var(--flag-red);" id="eventDate_required">*</span>
      </label>
      <input type="datetime-local" id="eventDate" name="eventDate"
             value="{{ toDateTimeLocal($ev['eventDate'] ?? null) }}"
             style="width:100%; padding:0.6rem 0.85rem; background:var(--black-soft); border:1px solid var(--border-mid); border-radius:8px; color:var(--off-white); font-family:var(--font-body); font-size:0.9rem;" />
      <small id="eventDate_hint" style="display:none; margin-top:0.35rem; color:var(--text-dim); font-size:0.8rem;">
        Next occurrence date (optional for recurring events).
      </small>
    </div>

    <!-- Distances (one row per option + optional route map image) -->
    <div class="form-group event-form__distances-wrap" style="margin-bottom:1.25rem;">
      <span class="form-label">Distances &amp; route maps</span>
      <p class="event-form__distances-hint" style="font-size:0.8rem; color:var(--text-dim); margin:0.35rem 0 0.75rem;">
        Add each distance on its own row (e.g. 10K, 21.1K). Upload a route image per distance; images appear on the public event page.
      </p>
      <ul class="event-form__distances" id="lfsDistanceRows" style="list-style:none; margin:0; padding:0; display:flex; flex-direction:column; gap:1rem;">
        @foreach($distRows as $dr)
        @php
          $drLabel = $dr['label'] ?? '';
          $drImg   = $dr['routeImage'] ?? null;
        @endphp
        <li class="event-form__distance-row" data-distance-row>
          <div style="display:grid; gap:0.5rem; grid-template-columns:1fr; max-width:100%;">
            <label>
              <span class="form-label" style="display:block; margin-bottom:0.3rem; font-size:0.78rem; color:var(--text-dim);">Distance label</span>
              <input type="text" name="dist_label[]" value="{{ $drLabel }}"
                placeholder="e.g. 10K"
                class="admin-input" style="width:100%;" />
            </label>
            <input type="hidden" name="dist_route_existing[]" value="{{ (string) ($drImg ?? '') }}" />
            <label>
              <span class="form-label" style="display:block; margin-bottom:0.3rem; font-size:0.78rem; color:var(--text-dim);">Route image</span>
              <input type="file" name="dist_route_file[]" accept="image/jpeg,image/png,image/webp" class="event-form__file" />
            </label>
            @if(is_string($drImg) && $drImg !== '')
            <div class="event-form__distance-preview" style="display:flex; align-items:center; gap:0.75rem; padding:0.65rem 0.9rem; background:rgba(74,124,89,0.1); border:1px solid rgba(74,124,89,0.3); border-radius:8px;">
              <i class="fas fa-image" style="color:var(--green-bright); font-size:1rem; flex-shrink:0;"></i>
              <span style="font-size:0.82rem; color:var(--off-white); flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                Image uploaded: <strong>{{ basename((string) $drImg) }}</strong>
              </span>
              <a href="{{ asset(ltrim((string) $drImg, '/')) }}"
                 target="_blank" rel="noopener"
                 style="font-size:0.8rem; color:var(--green-bright); text-decoration:none; white-space:nowrap; flex-shrink:0;">
                View &rarr;
              </a>
            </div>
            @endif
            <div style="display:flex; justify-content:flex-end;">
              <button type="button" class="lfsRemoveDistance admin-btn-ghost" style="font-size:0.8rem; padding:0.35rem 0.6rem; border:1px solid var(--border-mid); border-radius:6px; background:transparent; color:var(--text-dim); cursor:pointer;">
                Remove row
              </button>
            </div>
          </div>
        </li>
        @endforeach
      </ul>
      <button type="button" id="lfsAddDistance" style="margin-top:0.75rem; font-size:0.85rem; padding:0.45rem 0.9rem; border-radius:8px; border:1px dashed var(--border-mid); background:var(--black-soft); color:var(--green-bright); cursor:pointer;">
        + Add distance
      </button>
    </div>

    <script>
    (function () {
      const radios   = document.querySelectorAll('input[name="recurrenceType"]');
      const daysWrap = document.getElementById('recurrence_days_wrap');
      const dateInput = document.getElementById('eventDate');
      const dateReq   = document.getElementById('eventDate_required');
      const dateHint  = document.getElementById('eventDate_hint');

      function toggle() {
        const isWeekly = document.getElementById('rec_weekly').checked;
        daysWrap.style.display  = isWeekly ? 'flex' : 'none';
        dateInput.required      = !isWeekly;
        dateReq.style.display   = isWeekly ? 'none' : '';
        dateHint.style.display  = isWeekly ? 'block' : 'none';
      }

      radios.forEach(r => r.addEventListener('change', toggle));
      toggle();

      /* Highlight checked day labels */
      daysWrap.addEventListener('change', function (e) {
        if (e.target.type !== 'checkbox') return;
        e.target.closest('label').style.borderColor =
          e.target.checked ? 'var(--flag-green)' : 'var(--border-mid)';
      });
      /* Init highlight on load */
      daysWrap.querySelectorAll('input[type=checkbox]:checked').forEach(cb => {
        cb.closest('label').style.borderColor = 'var(--flag-green)';
      });
    }());
    </script>

    <!-- Category -->
    <div class="form-group" style="margin-bottom:1.25rem;">
      <label class="form-label" for="category">Category</label>
      <select id="category" name="category"
              style="width:100%; padding:0.6rem 0.85rem; background:var(--black-soft); border:1px solid var(--border-mid); border-radius:8px; color:var(--off-white); font-family:var(--font-body); font-size:0.9rem;">
        <option value="">— Select —</option>
        @foreach($cats as $c)
          <option value="{{ $c }}"
            {{ ($ev['category'] ?? '') === $c ? 'selected' : '' }}>
            {{ $c }}
          </option>
        @endforeach
      </select>
    </div>

    <!-- Registration type -->
    @php $regType = $ev['registrationType'] ?? 'open'; @endphp
    <div class="form-group" style="margin-bottom:1.25rem;">
      <label class="form-label">Registration</label>
      <div style="display:flex; gap:1rem; flex-wrap:wrap; margin-top:0.4rem;">
        @foreach(['open' => 'Open to all', 'members' => 'Members only', 'none' => 'No registration'] as $val => $lbl)
        <label style="display:flex; align-items:center; gap:0.45rem; cursor:pointer; font-size:0.9rem; color:var(--off-white);">
          <input type="radio" name="registrationType" value="{{ $val }}" id="reg_{{ $val }}"
                 {{ $regType === $val ? 'checked' : '' }}>
          {{ $lbl }}
        </label>
        @endforeach
      </div>
    </div>

    <!-- External registration link -->
    <div class="form-group" style="margin-bottom:1.25rem;">
      <label class="form-label" for="registrationLink">External registration link</label>
      <input type="url" id="registrationLink" name="registrationLink"
             value="{{ $ev['registrationLink'] ?? '' }}"
             placeholder="https://squidal.com/… or any external URL"
             style="width:100%; padding:0.6rem 0.85rem; background:var(--black-soft); border:1px solid var(--border-mid); border-radius:8px; color:var(--off-white); font-family:var(--font-body); font-size:0.9rem;" />
      <small style="display:block; margin-top:0.35rem; color:var(--text-dim); font-size:0.8rem;">
        If set, the Register button will link here instead of the contact page.
      </small>
    </div>

    <!-- Registration open / close dates — hidden when type is 'none' -->
    <div id="reg_dates_wrap">
      <div class="form-group" style="margin-bottom:1.25rem;">
        <label class="form-label" for="registrationOpen">Registration opens</label>
        <input type="datetime-local" id="registrationOpen" name="registrationOpen"
               value="{{ toDateTimeLocal($ev['registrationOpen'] ?? null) }}"
               style="width:100%; padding:0.6rem 0.85rem; background:var(--black-soft); border:1px solid var(--border-mid); border-radius:8px; color:var(--off-white); font-family:var(--font-body); font-size:0.9rem;" />
      </div>
      <div class="form-group" style="margin-bottom:1.25rem;">
        <label class="form-label" for="registrationClose">Registration closes</label>
        <input type="datetime-local" id="registrationClose" name="registrationClose"
               value="{{ toDateTimeLocal($ev['registrationClose'] ?? null) }}"
               style="width:100%; padding:0.6rem 0.85rem; background:var(--black-soft); border:1px solid var(--border-mid); border-radius:8px; color:var(--off-white); font-family:var(--font-body); font-size:0.9rem;" />
      </div>
    </div>

    <script>
    (function () {
      const radios  = document.querySelectorAll('input[name="registrationType"]');
      const wrap    = document.getElementById('reg_dates_wrap');
      const regOpen = document.getElementById('registrationOpen');
      const regClose = document.getElementById('registrationClose');
      function toggle() {
        const none = document.getElementById('reg_none').checked;
        wrap.style.display = none ? 'none' : '';
        // Hidden inputs can still fail constraint validation; the browser then cannot
        // focus them ("not focusable"). Disabled fields are skipped by validation and POST.
        if (regOpen) regOpen.disabled = none;
        if (regClose) regClose.disabled = none;
      }
      radios.forEach(r => r.addEventListener('change', toggle));
      toggle();
    }());
    </script>

    <!-- Banner image: upload or URL -->
    <div class="form-group event-form__banner-group" style="margin-bottom:1.25rem;">
      @if($isEdit && !empty($ev['bannerImage']))
        <div class="event-form__banner-preview">
          <p class="event-form__banner-current-label">Current banner:</p>
          <img src="{{ $ev['bannerImage'] }}" alt="Current banner" class="event-form__banner-image" />
        </div>
      @endif
      <label class="form-label" for="bannerImageFile">Banner image</label>
      <input type="file" id="bannerImageFile" name="bannerImageFile"
             accept="image/jpeg,image/png,image/webp" class="event-form__file" />
      <p class="event-form__banner-hint">Upload an image or paste a URL below. Leave both empty for no banner.</p>
      <label class="form-label" for="bannerImage">Or banner image URL</label>
      <input type="text" id="bannerImage" name="bannerImage" class="admin-input"
             value="{{ $ev['bannerImage'] ?? '' }}"
             placeholder="https://… or /images/…" />
      <div class="form-group" style="margin-top:1rem;">
        <label id="featureOnHomeLabel"
               style="display:flex; align-items:flex-start; gap:0.55rem; cursor:pointer; font-size:0.9rem; color:var(--off-white); line-height:1.4;
                      padding:0.65rem 0.9rem; border-radius:8px; border:1px solid var(--border-mid); transition:background 0.15s, border-color 0.15s;">
          <input type="checkbox" name="featureOnHome" value="1" id="featureOnHome"
            {{ !empty($ev['featureOnHome']) ? 'checked' : '' }}
            style="margin-top:0.2rem; flex-shrink:0;" />
          <span>Feature on home page hero <small style="display:block; color:var(--text-dim); font-size:0.78rem; margin-top:0.2rem;">(banner + title shown on the hero; multiple events allowed—their slides and labels rotate first, then gallery images; requires a banner and an upcoming event date)</small></span>
        </label>
        <p id="featureOnHomeNoBanner" style="display:none; margin:0.45rem 0 0; font-size:0.82rem; color:#e05252;">
          <i class="fas fa-triangle-exclamation"></i>
          A banner image is required to feature this event on the home page. Upload or paste a banner URL above first.
        </p>
      </div>
      <script>
      (function () {
        var cb    = document.getElementById('featureOnHome');
        var lbl   = document.getElementById('featureOnHomeLabel');
        var warn  = document.getElementById('featureOnHomeNoBanner');
        var fileIn = document.getElementById('bannerImageFile');
        var urlIn  = document.getElementById('bannerImage');

        function hasBanner() {
          return (fileIn && fileIn.files && fileIn.files.length > 0) ||
                 (urlIn  && urlIn.value.trim() !== '') ||
                 {{ !empty($ev['bannerImage']) ? 'true' : 'false' }};
        }

        function update() {
          var checked = cb.checked;
          lbl.style.background   = checked ? 'rgba(74,124,89,0.12)' : '';
          lbl.style.borderColor  = checked ? 'rgba(74,124,89,0.5)'  : 'var(--border-mid)';
          warn.style.display     = (checked && !hasBanner()) ? 'block' : 'none';
        }

        cb.addEventListener('change', update);
        if (fileIn) fileIn.addEventListener('change', update);
        if (urlIn)  urlIn.addEventListener('input',  update);
        update();
      }());
      </script>
    </div>

    <!-- Event brochure (PDF) -->
    <div class="form-group event-form__brochure-group" style="margin-bottom:1.25rem;">
      <span class="form-label">Event brochure (PDF)</span>
      <p class="event-form__brochure-hint" style="font-size:0.8rem; color:var(--text-dim); margin:0.35rem 0 0.75rem;">
        Optional file visitors can download on the public event page. You can upload a PDF or paste a direct link. Max 50 MB.
      </p>
      @if($isEdit && !empty($ev['brochurePdf']))
      @php
        $brochurePreview = (string) $ev['brochurePdf'];
        $brochurePreviewHref = (str_starts_with($brochurePreview, 'http://') || str_starts_with($brochurePreview, 'https://'))
          ? $brochurePreview
          : asset(ltrim($brochurePreview, '/'));
        $brochureFilename = basename($brochurePreview);
      @endphp
      <div style="display:flex; align-items:center; gap:0.75rem; padding:0.65rem 0.9rem; margin-bottom:0.85rem; background:rgba(74,124,89,0.1); border:1px solid rgba(74,124,89,0.3); border-radius:8px;">
        <i class="fas fa-file-pdf" style="color:var(--green-bright); font-size:1rem; flex-shrink:0;"></i>
        <span style="font-size:0.85rem; color:var(--off-white); flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
          PDF uploaded: <strong>{{ $brochureFilename }}</strong>
        </span>
        <a href="{{ $brochurePreviewHref }}"
           target="_blank" rel="noopener"
           style="font-size:0.8rem; color:var(--green-bright); text-decoration:none; white-space:nowrap; flex-shrink:0;">
          View &rarr;
        </a>
      </div>
      <label style="display:flex; align-items:flex-start; gap:0.55rem; cursor:pointer; font-size:0.9rem; color:var(--off-white); line-height:1.4; margin-bottom:0.75rem;">
        <input type="checkbox" name="remove_brochure" value="1" style="margin-top:0.2rem; flex-shrink:0;" />
        <span>Remove brochure (no download on the public page)</span>
      </label>
      @endif
      <label class="form-label" for="brochurePdfFile">Upload PDF</label>
      <input type="file" id="brochurePdfFile" name="brochurePdfFile" accept="application/pdf,.pdf" class="event-form__file" />
      <label class="form-label" for="brochurePdf" style="margin-top:0.75rem;">Or brochure URL / path</label>
      <input type="text" id="brochurePdf" name="brochurePdf" class="admin-input"
        value="{{ $ev['brochurePdf'] ?? '' }}"
        placeholder="https://… or /files/…" />
      <small style="display:block; margin-top:0.35rem; color:var(--text-dim); font-size:0.8rem;">
        Leave empty to keep the current brochure when editing. A new upload replaces the stored file.
      </small>
    </div>

    <!-- Actions -->
    <div style="display:flex; align-items:center; gap:0.75rem; flex-wrap:wrap;">
      <button type="submit"
              style="padding:0.6rem 1.25rem; background:var(--flag-green); color:#fff; border:none; border-radius:8px; font-family:var(--font-body); font-size:0.9rem; font-weight:600; cursor:pointer;">
        {{ $isEdit ? 'Update event' : 'Create event' }}
      </button>
      <a href="{{ url('/admin/events') }}"
         style="padding:0.6rem 1rem; background:var(--black-soft); border:1px solid var(--border-mid); border-radius:8px; color:var(--text-dim); text-decoration:none; font-size:0.9rem;">
        Cancel
      </a>
    </div>

  </form>
</div>

@endsection
