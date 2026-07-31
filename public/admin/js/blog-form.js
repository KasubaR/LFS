(function () {
  "use strict";

  const form = document.getElementById("blog-form");
  if (!form) return;

  function sanitiseForQuill(html) {
    if (typeof DOMPurify === "undefined") return "";
    return DOMPurify.sanitize(html || "", { USE_PROFILES: { html: true } });
  }

  function htmlEsc(s) {
    return s
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  /* ── Quill rich text editor ─────────────────────── */
  const editorEl = document.getElementById("blog-editor");
  if (!editorEl || typeof Quill === "undefined") return;

  const quill = new Quill("#blog-editor", {
    theme: "snow",
    placeholder: "Write your post content here…",
    modules: {
      toolbar: [
        [{ header: [1, 2, 3, false] }],
        ["bold", "italic", "underline", "strike"],
        [{ list: "ordered" }, { list: "bullet" }],
        ["blockquote", "code-block"],
        ["link", "image"],
        [{ align: [] }],
        ["clean"],
      ],
    },
  });

  const contentHidden = document.getElementById("content-hidden");
  if (contentHidden && contentHidden.value) {
    quill.root.innerHTML = sanitiseForQuill(contentHidden.value);
  }

  form.addEventListener("submit", function () {
    if (contentHidden) {
      contentHidden.value = sanitiseForQuill(quill.root.innerHTML);
    }
  });

  /* ── Publish action buttons ─────────────────────── */
  const statusSelect = document.getElementById("blog-status");
  const publishModeHidden = document.getElementById("publish-mode-hidden");

  form.querySelectorAll("[data-blog-action]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      const action = btn.getAttribute("data-blog-action");
      if (!statusSelect || !publishModeHidden) return;
      if (action === "draft") {
        statusSelect.value = "draft";
        publishModeHidden.value = "draft";
      } else if (action === "publish") {
        publishModeHidden.value = statusSelect.value;
      }
    });
  });

  /* ── Tag chip input ─────────────────────────────── */
  const tagInput = document.getElementById("tag-input");
  const tagsHidden = document.getElementById("tags-hidden");
  const tagWrap = document.getElementById("tag-chips");

  if (tagInput && tagsHidden && tagWrap) {
    let currentTags = tagsHidden.value
      ? tagsHidden.value.split(",").map(function (s) { return s.trim(); }).filter(Boolean)
      : [];

    function syncTags(tags) {
      tagsHidden.value = tags.join(",");
    }

    function renderTags(tags) {
      tagWrap.innerHTML = "";
      tags.forEach(function (t, i) {
        const chip = document.createElement("span");
        chip.className = "tag-chip";
        chip.innerHTML =
          htmlEsc(t) +
          '<button type="button" class="tag-chip__remove" aria-label="Remove tag ' +
          htmlEsc(t) +
          '">×</button>';
        chip.querySelector("button").addEventListener("click", function () {
          tags.splice(i, 1);
          renderTags(tags);
          syncTags(tags);
        });
        tagWrap.appendChild(chip);
      });
    }

    renderTags(currentTags);

    tagInput.addEventListener("keydown", function (e) {
      if (e.key === "Enter" || e.key === ",") {
        e.preventDefault();
        const val = tagInput.value.trim().replace(/,$/, "");
        if (val && currentTags.indexOf(val) === -1) {
          currentTags.push(val);
          renderTags(currentTags);
          syncTags(currentTags);
        }
        tagInput.value = "";
      }
    });
  }

  /* ── Publish mode toggle ────────────────────────── */
  const scheduledWrap = document.getElementById("scheduled-wrap");

  function toggleScheduled() {
    if (!scheduledWrap || !statusSelect) return;
    scheduledWrap.classList.toggle("is-visible", statusSelect.value === "schedule");
  }

  if (statusSelect) {
    statusSelect.addEventListener("change", toggleScheduled);
    toggleScheduled();
  }

  /* ── Featured image preview ─────────────────────── */
  const imgFile = document.getElementById("featuredImageFile");
  const imgPreview = document.getElementById("featured-img-preview");
  const uploadLabel = document.getElementById("featured-upload-label");

  if (imgFile && imgPreview) {
    imgFile.addEventListener("change", function () {
      const file = this.files[0];
      if (!file) return;
      const reader = new FileReader();
      reader.onload = function (e) {
        imgPreview.src = e.target.result;
        imgPreview.classList.remove("is-hidden");
      };
      reader.readAsDataURL(file);
    });
  }

  if (uploadLabel && imgFile) {
    uploadLabel.addEventListener("keydown", function (e) {
      if (e.key === "Enter" || e.key === " ") {
        e.preventDefault();
        imgFile.click();
      }
    });
  }

  /* ── Preview button ─────────────────────────────── */
  const previewBtn = document.getElementById("btn-preview");
  if (previewBtn) {
    previewBtn.addEventListener("click", function () {
      const titleEl = document.getElementById("blog-title");
      const excerptEl = document.getElementById("blog-excerpt");
      const title = (titleEl && titleEl.value) || "(No title)";
      const excerpt = (excerptEl && excerptEl.value) || "";
      const body = quill.root.innerHTML;
      const safeBody =
        typeof DOMPurify !== "undefined"
          ? DOMPurify.sanitize(body || "", { USE_PROFILES: { html: true } })
          : body || "";
      const win = window.open("", "_blank");
      win.document.write(
        '<!DOCTYPE html><html><head><meta charset="UTF-8">' +
        "<title>Preview: " + title.replace(/</g, "&lt;") + "</title>" +
        "<style>body{font-family:system-ui,sans-serif;max-width:780px;margin:2.5rem auto;padding:1rem 1.5rem;color:#111;line-height:1.7}" +
        "h1{margin-bottom:0.5rem}p.excerpt{color:#555;font-size:1.05rem;border-left:3px solid #4a7c59;padding-left:1rem;margin-bottom:2rem}" +
        "img{max-width:100%;border-radius:6px}" +
        ".preview-banner{background:#4a7c59;color:#fff;padding:0.5rem 1rem;font-size:0.8rem;margin-bottom:1.5rem;border-radius:4px}" +
        "</style></head><body>" +
        '<div class="preview-banner">Preview — not yet published</div>' +
        "<h1>" + title.replace(/</g, "&lt;") + "</h1>" +
        (excerpt ? '<p class="excerpt">' + excerpt.replace(/</g, "&lt;") + "</p>" : "") +
        safeBody +
        "</body></html>"
      );
      win.document.close();
    });
  }
})();
