# LFS Website — Laravel Migration

Greenfield Laravel application for [Lusaka Fitness Squad](https://www.lfszambia.run), migrated from the custom PHP app in `../lfs-website-php`.

## Location

```
C:\xampp\htdocs\lfs-website-laravel\
```

Document root: `public/`

Local URL (XAMPP): `http://localhost/lfs-website-laravel/public`

## Setup

1. Copy `.env.example` to `.env` (or use the included `.env`) and set:
   - `DB_*` — same MySQL database as the legacy app (`lfs_db`)
   - `ADMIN_PASSWORD_HASH` — bcrypt hash for `/admin/door`
   - `LENCO_API_SECRET_KEY`, `LENCO_WEBHOOK_SECRET` — for shop payments

2. Install PHP dependencies (already done if `vendor/` exists):
   ```bash
   php C:\xampp\php\composer.phar install
   ```

3. Generate application key:
   ```bash
   php artisan key:generate
   ```

4. Run migrations (fresh install only — skip if DB already has tables from legacy app):
   ```bash
   php artisan migrate --path=database/migrations/2024_06_01_000001_create_lfs_tables.php
   ```

5. Link public storage for uploads:
   ```bash
   php artisan storage:link
   ```

6. Ensure uploads exist under `storage/app/public/uploads/` and `storage/app/public/files/` (copied from legacy project during migration).

## Frontend assets

Pre-built CSS/JS/images live in `public/css`, `public/js`, `public/images`, `public/admin/`. These were copied from the legacy project. Tailwind source can be rebuilt from the legacy `tailwind-input.css` via:

```bash
# In legacy project, or copy tailwind config to Laravel
npm run build:css
```

Laravel Vite is configured in `vite.config.js` for future Blade-first templates.

## Architecture

| Layer | Location |
|-------|----------|
| Routes | `routes/web.php`, `routes/admin.php`, `routes/api.php` |
| Controllers | `app/Http/Controllers/` |
| Services | `app/Services/` |
| Models | `app/Models/` |
| Public Blade views | `resources/views/pages/`, `resources/views/partials/` |
| Admin Blade views | `resources/views/admin/`, `resources/views/layouts/admin.blade.php` |
| Admin auth | Session + `ADMIN_PASSWORD_HASH` at `/admin/door` |

## Cutover checklist

See [docs/CUTOVER.md](docs/CUTOVER.md).

## Development

```bash
php artisan serve
# or use XAMPP with document root pointing at public/
```
