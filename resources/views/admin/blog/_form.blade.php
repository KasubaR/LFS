@php
$isEdit  = $isEdit  ?? ($post !== null);
$p       = $post    ?? [];
$postId  = $postId  ?? ($p['id'] ?? null);
$cats    = $categories ?? [];

$currentStatus = $p['status'] ?? 'draft';
$uiStatus = $currentStatus;
if ($currentStatus === 'draft' && !empty($p['publishDate'])) {
    $uiStatus = 'schedule';
}
$currentImg = $p['featuredImage'] ?? '';
$pageHeading = $isEdit ? 'Edit Post' : 'New Post';
$pageLede = $isEdit
    ? 'Update content, metadata, and publish settings for this article.'
    : 'Write and publish a news article for the LFS website.';
@endphp

<div class="blog-editor-page">

  <header class="admin-page-header">
    <a href="{{ url('/admin/blog/list') }}" class="admin-page-header__back">
      <i class="fas fa-arrow-left" aria-hidden="true"></i> Back to Blog
    </a>
    <h1 class="admin-page-header__heading">{{ $pageHeading }}</h1>
    <p class="blog-editor-page__lede">{{ $pageLede }}</p>
  </header>

  @if(!empty($error))
    <div class="gallery-error-banner blog-form__error" role="alert">
      <i class="fas fa-triangle-exclamation" aria-hidden="true"></i>
      <span>{{ $error }}</span>
    </div>
  @endif

  <form id="blog-form" class="blog-form" method="POST"
        action="{{ $isEdit ? '/admin/blog/' . htmlspecialchars($postId ?? '') : '/admin/blog' }}"
        enctype="multipart/form-data">

    <input type="hidden" name="_csrf" value="{{ $csrfToken ?? '' }}">

    <div class="blog-form__layout">

      <!-- Main column -->
      <div class="blog-form__main">
        <section class="blog-form__panel" aria-labelledby="blog-content-heading">
          <h2 id="blog-content-heading" class="blog-form__panel-title">Post content</h2>

          <div class="blog-form__field">
            <label class="admin-label" for="blog-title">
              Title <span class="admin-label__required">*</span>
            </label>
            <input type="text" id="blog-title" name="title" required
                   value="{{ $p['title'] ?? '' }}"
                   placeholder="Enter a compelling headline"
                   class="admin-input blog-form__title" />
          </div>

          <div class="blog-form__field">
            <label class="admin-label" for="blog-slug">Slug</label>
            <input type="text" id="blog-slug" name="slug"
                   value="{{ $p['slug'] ?? '' }}"
                   placeholder="auto-generated from title if left blank"
                   class="admin-input" />
            <p class="admin-help-text">Used in <code>/news/:slug</code> — must be URL-safe.</p>
          </div>

          <div class="blog-form__field">
            <label class="admin-label" for="blog-editor">Content</label>
            <div class="blog-form__editor-wrap">
              <div id="blog-editor"></div>
            </div>
            <input type="hidden" id="content-hidden" name="content"
                   value="{{ $p['content'] ?? '' }}">
          </div>

          <div class="blog-form__field">
            <label class="admin-label" for="blog-excerpt">Excerpt</label>
            <textarea id="blog-excerpt" name="excerpt" rows="3"
                      placeholder="Short summary shown in post listings…"
                      class="admin-textarea"
            >{{ $p['excerpt'] ?? '' }}</textarea>
            <p class="admin-help-text">Optional teaser text for the news index and social previews.</p>
          </div>
        </section>
      </div>

      <!-- Sidebar -->
      <aside class="blog-form__aside">

        <!-- Publish -->
        <div class="blog-form__card">
          <p class="blog-form__card-title">
            <i class="fas fa-paper-plane" aria-hidden="true"></i> Publish
          </p>

          <div class="blog-form__card-field">
            <label class="admin-label" for="blog-status">Status</label>
            <select id="blog-status" name="status" class="admin-input">
              <option value="draft"     {{ $uiStatus === 'draft'     ? 'selected' : '' }}>Draft</option>
              <option value="published" {{ $uiStatus === 'published' ? 'selected' : '' }}>Published</option>
              <option value="schedule"  {{ $uiStatus === 'schedule'  ? 'selected' : '' }}>Scheduled</option>
            </select>
            <input type="hidden" name="publishMode" id="publish-mode-hidden" value="">
          </div>

          <div class="blog-form__card-field blog-form__scheduled{{ $uiStatus === 'schedule' ? ' is-visible' : '' }}"
               id="scheduled-wrap">
            <label class="admin-label" for="blog-publish-date">Publish date &amp; time</label>
            <input type="datetime-local" id="blog-publish-date" name="publishDate"
                   value="{{ !empty($p['publishDate']) ? date('Y-m-d\TH:i', strtotime($p['publishDate'])) : '' }}"
                   class="admin-input" />
          </div>

          <div class="blog-form__actions">
            <button type="submit" name="action" value="publish"
                    data-blog-action="publish"
                    class="admin-btn admin-btn--primary admin-btn--block">
              <i class="fas fa-check" aria-hidden="true"></i>
              {{ $isEdit ? 'Update Post' : 'Publish Post' }}
            </button>
            <button type="submit" name="action" value="draft"
                    data-blog-action="draft"
                    class="admin-btn admin-btn--ghost admin-btn--block">
              <i class="fas fa-floppy-disk" aria-hidden="true"></i>
              Save as Draft
            </button>
            <button type="button" id="btn-preview"
                    class="admin-btn admin-btn--ghost admin-btn--block">
              <i class="fas fa-eye" aria-hidden="true"></i> Preview
            </button>
            <a href="/admin/blog/list" class="blog-form__cancel">Cancel</a>
          </div>
        </div>

        <!-- Meta -->
        <div class="blog-form__card">
          <p class="blog-form__card-title">
            <i class="fas fa-tags" aria-hidden="true"></i> Meta
          </p>

          <div class="blog-form__card-field">
            <label class="admin-label" for="blog-category">Category</label>
            <select id="blog-category" name="category" class="admin-input">
              <option value="">— Select —</option>
              @foreach($cats as $c)
                <option value="{{ $c }}"
                  {{ ($p['category'] ?? '') === $c ? 'selected' : '' }}>
                  {{ $c }}
                </option>
              @endforeach
            </select>
          </div>

          <div class="blog-form__card-field">
            <label class="admin-label" for="blog-author">Author</label>
            <input type="text" id="blog-author" name="author"
                   value="{{ $p['author'] ?? 'LFS Admin' }}"
                   class="admin-input" />
          </div>

          <div class="blog-form__card-field">
            <label class="admin-label" for="tag-input">Tags</label>
            <div id="tag-chips" class="blog-form__tags"></div>
            <input type="hidden" id="tags-hidden" name="tags"
                   value="{{ is_array($p['tags'] ?? null) ? implode(',', $p['tags']) : ($p['tags'] ?? '') }}">
            <input type="text" id="tag-input" placeholder="Add tag, press Enter"
                   class="admin-input" autocomplete="off" />
            <p class="admin-help-text">Press Enter or comma to add a tag.</p>
          </div>

          <div class="blog-form__card-field">
            <label class="blog-form__toggle">
              <input type="checkbox" name="featured" value="1"
                     {{ !empty($p['featured']) ? 'checked' : '' }}>
              <span class="blog-form__toggle-copy">
                <span class="blog-form__toggle-text">Featured post</span>
                <span class="admin-help-text">Highlighted on the blog index.</span>
              </span>
            </label>
          </div>
        </div>

        <!-- Featured image -->
        <div class="blog-form__card">
          <p class="blog-form__card-title">
            <i class="fas fa-image" aria-hidden="true"></i> Featured Image
          </p>

          @if($isEdit && $currentImg)
            <img src="{{ $currentImg }}" alt="Current featured image"
                 class="blog-form__image-preview" />
          @endif

          <img id="featured-img-preview" src="" alt="Featured image preview"
               class="blog-form__image-preview is-hidden" />

          <div class="blog-form__card-field">
            <label class="admin-label" for="featuredImageFile">Upload image</label>
            <label id="featured-upload-label" class="blog-form__upload-label" tabindex="0">
              <span class="blog-form__upload-zone">
                <i class="fas fa-cloud-arrow-up" aria-hidden="true"></i>
                <span>Click to choose JPEG, PNG, or WebP<br><small>Max 10 MB</small></span>
                <input type="file" id="featuredImageFile" name="featuredImageFile"
                       accept="image/jpeg,image/png,image/webp" />
              </span>
            </label>
          </div>

          <div class="blog-form__divider">or</div>

          <div class="blog-form__card-field">
            <label class="admin-label" for="featuredImage">Image URL</label>
            <input type="text" id="featuredImage" name="featuredImage"
                   value="{{ $currentImg }}"
                   placeholder="https://… or /images/…"
                   class="admin-input" />
          </div>
        </div>

      </aside>
    </div>
  </form>
</div>