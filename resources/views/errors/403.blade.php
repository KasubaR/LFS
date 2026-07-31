<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title>403 — Access denied</title>
  <link rel="icon" type="image/svg+xml" href="/favicon.svg">
  <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:ital,wght@0,300;0,400;0,500;0,700;1,300&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="{{ asset('/admin/css/admin.css') }}">
</head>
<body class="admin-body">
  <main class="admin-main" style="margin:0 auto;max-width:40rem;padding:3rem 1.5rem;">
    <div class="admin-form-card">
      <h1 class="admin-page-header__heading" style="margin-bottom:1rem;">Access denied</h1>
      <p>{{ isset($exception) ? ($exception->getMessage() ?: 'You do not have permission to access this page.') : 'You do not have permission to access this page.' }}</p>
      <p style="margin-top:1.25rem;">
        @if(request()->is('admin') || request()->is('admin/*'))
          <a href="{{ url('/admin/dashboard') }}" class="admin-btn admin-btn--primary">Back to dashboard</a>
        @else
          <a href="{{ url('/') }}" class="admin-btn admin-btn--primary">Back to site</a>
        @endif
      </p>
    </div>
  </main>
</body>
</html>
