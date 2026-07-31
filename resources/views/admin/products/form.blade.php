@extends('layouts.admin')

@section('content')
@php
$isEditMode = $isEdit ?? (!empty($product['id']));
$p          = $product ?? [];

$pageTitle   = $pageTitle  ?? ($isEditMode ? 'Edit Product' : 'New Product');
$activePage  = 'products';
$breadcrumbs = $breadcrumbs ?? [
    ['label' => 'Admin',    'url' => '/admin'],
    ['label' => 'Products', 'url' => '/admin/products'],
    ['label' => $isEditMode ? ($p['name'] ?? 'Edit') : 'New Product'],
];

$PRODUCT_CATEGORIES = $PRODUCT_CATEGORIES ?? [];
$GENDER_OPTIONS     = $GENDER_OPTIONS     ?? [];

/** Convert a tags array (or comma string) to a display string. */
$joinTags = fn ($v): string => is_array($v) ? implode(', ', $v) : (string)($v ?? '');

/** Convert a sizes array [ ['size'=>'S','stock'=>10], … ] to "S:10, M:8" */
$sizesToString = function (mixed $sizes): string {
    if (!is_array($sizes) || empty($sizes)) return '';
    return implode(', ', array_map(fn ($s) => ($s['size'] ?? '') . ':' . ($s['stock'] ?? 0), $sizes));
};
@endphp

@if(!empty($error))
  <div class="sys-notif sys-notif--error" role="alert">
    <i class="fas fa-triangle-exclamation" aria-hidden="true"></i>
    <span>{{ $error }}</span>
  </div>
@endif

<section class="admin-form-section">
  <form method="POST"
        action="{{ $isEditMode ? '/admin/products/' . htmlspecialchars($p['id']) : '/admin/products' }}"
        enctype="multipart/form-data"
        class="admin-form">

    <input type="hidden" name="_csrf" value="{{ $csrfToken ?? '' }}">

    <!-- Basic info -->
    <div class="admin-form__grid">
      <div class="admin-form__field">
        <label class="admin-label" for="name">
          Name <span class="admin-label__required">*</span>
        </label>
        <input type="text" id="name" name="name" class="admin-input" required
               value="{{ $p['name'] ?? '' }}"
               placeholder="e.g. LFS Running Jersey" />
      </div>

      <div class="admin-form__field">
        <label class="admin-label" for="slug">Slug</label>
        <input type="text" id="slug" name="slug" class="admin-input"
               value="{{ $p['slug'] ?? '' }}"
               placeholder="e.g. lfs-running-jersey (leave blank to auto-generate)" />
      </div>
    </div>

    <div class="admin-form__grid">
      <div class="admin-form__field">
        <label class="admin-label" for="price">
          Price (ZMW) <span class="admin-label__required">*</span>
        </label>
        <input type="number" id="price" name="price" class="admin-input"
               required min="0" step="0.01"
               value="{{ is_numeric($p['price'] ?? null) ? $p['price'] : '' }}" />
      </div>

      <div class="admin-form__field">
        <label class="admin-label" for="comparePrice">Compare at price</label>
        <input type="number" id="comparePrice" name="comparePrice" class="admin-input"
               min="0" step="0.01"
               value="{{ is_numeric($p['comparePrice'] ?? null) ? $p['comparePrice'] : '' }}" />
        <p class="admin-help-text">Optional "was" price to show sale discount.</p>
      </div>
    </div>

    <!-- Descriptions -->
    <div class="admin-form__field">
      <label class="admin-label" for="shortDescription">Short description</label>
      <textarea id="shortDescription" name="shortDescription" class="admin-textarea" rows="2"
                placeholder="Short punchy summary shown in cards and meta descriptions."
      >{{ $p['shortDescription'] ?? '' }}</textarea>
    </div>

    <div class="admin-form__field">
      <label class="admin-label" for="description">Full description</label>
      <textarea id="description" name="description" class="admin-textarea" rows="6"
                placeholder="Full product description, features, fit, etc."
      >{{ $p['description'] ?? '' }}</textarea>
    </div>

    <!-- Category & gender -->
    <div class="admin-form__grid">
      <div class="admin-form__field">
        <label class="admin-label" for="category">
          Category <span class="admin-label__required">*</span>
        </label>
        <select id="category" name="category" class="admin-input" required>
          <option value="">— Select —</option>
          @foreach($PRODUCT_CATEGORIES as $c)
            <option value="{{ $c }}"
              {{ ($p['category'] ?? '') === $c ? 'selected' : '' }}>
              {{ str_replace('-', ' ', $c) }}
            </option>
          @endforeach
        </select>
      </div>

      <div class="admin-form__field">
        <label class="admin-label" for="gender">
          Gender <span class="admin-label__required">*</span>
        </label>
        <select id="gender" name="gender" class="admin-input" required>
          @foreach($GENDER_OPTIONS as $g)
            <option value="{{ $g }}"
              {{ ($p['gender'] ?? 'unisex') === $g ? 'selected' : '' }}>
              {{ $g }}
            </option>
          @endforeach
        </select>
      </div>
    </div>

    <!-- Tags & sizes -->
    <div class="admin-form__grid">
      <div class="admin-form__field">
        <label class="admin-label" for="tags">Tags</label>
        <input type="text" id="tags" name="tags" class="admin-input"
               value="{{ $joinTags($p['tags'] ?? '') }}"
               placeholder="e.g. jersey, 2026, race, official" />
        <p class="admin-help-text">Comma-separated words used for search and grouping.</p>
      </div>

      <div class="admin-form__field">
        <label class="admin-label" for="sizes">Sizes &amp; stock</label>
        <input type="text" id="sizes" name="sizes" class="admin-input"
               value="{{ $sizesToString($p['sizes'] ?? []) }}"
               placeholder="e.g. S:10, M:8, L:4" />
        <p class="admin-help-text">Format: SIZE:QTY — comma-separated. Example: S:10, M:8, L:4</p>
      </div>
    </div>

    <!-- Stock & sort -->
    <div class="admin-form__grid">
      <div class="admin-form__field">
        <label class="admin-label" for="totalStock">Total stock</label>
        <input type="number" id="totalStock" name="totalStock" class="admin-input"
               min="0" step="1"
               value="{{ is_numeric($p['totalStock'] ?? null) ? $p['totalStock'] : '' }}" />
        <p class="admin-help-text">Optional; if sizes are provided, this can mirror the sum.</p>
      </div>

      <div class="admin-form__field">
        <label class="admin-label" for="sortOrder">Sort order</label>
        <input type="number" id="sortOrder" name="sortOrder" class="admin-input"
               step="1"
               value="{{ is_numeric($p['sortOrder'] ?? null) ? $p['sortOrder'] : '' }}" />
        <p class="admin-help-text">Higher values appear first when sorting by "popular".</p>
      </div>
    </div>

    <!-- Flags -->
    <div class="admin-form__field admin-form__field--inline">
      <label class="admin-checkbox">
        <input type="checkbox" name="featured" value="on"
               {{ !empty($p['featured']) ? 'checked' : '' }} />
        <span>Featured product</span>
      </label>

      <label class="admin-checkbox">
        <input type="checkbox" name="isActive" value="on"
               {{ ($p['isActive'] ?? true) ? 'checked' : '' }} />
        <span>Visible in shop</span>
      </label>
    </div>

    <!-- Images -->
    <div class="admin-form__field">
      <label class="admin-label" for="productImages">Product images</label>
      <input type="file" id="productImages" name="productImages[]" class="admin-input"
             accept="image/jpeg,image/png,image/webp" multiple />
      <p class="admin-help-text">
        Upload up to 5 images. Stored under <code>/uploads/products/{productId}</code>.
      </p>

      @if(!empty($p['images']) && is_array($p['images']))
        <div class="admin-image-grid">
          @foreach($p['images'] as $img)
            <figure class="admin-image-grid__item">
              <img src="{{ $img }}" alt="Product image" />
            </figure>
          @endforeach
        </div>
      @endif
    </div>

    <!-- Actions -->
    <div class="admin-form__actions">
      <button type="submit" class="admin-btn admin-btn--primary">
        {{ $isEditMode ? 'Update Product' : 'Create Product' }}
      </button>
      <a href="{{ url('/admin/products') }}" class="admin-btn admin-btn--ghost">Cancel</a>
    </div>

  </form>
</section>

@endsection
