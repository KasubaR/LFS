# Production cutover — LFS Laravel

Use this checklist when switching from `lfs-website-php` to `lfs-website-laravel`.

## Pre-cutover

- [ ] Full database backup
- [ ] Copy `uploads/` and `files/event-brochures/` into `storage/app/public/`
- [ ] Run `php artisan storage:link` on the server
- [ ] Set production `.env`:
  - `APP_URL=https://www.lfszambia.run`
  - `APP_ENV=production`
  - `APP_DEBUG=false`
  - `DB_*`, `ADMIN_PASSWORD_HASH`, Lenco keys
- [ ] If DB is empty, run LFS migrations; if DB already live, **do not** re-run table migrations

## Apache / XAMPP

**Production:** set document root to `lfs-website-laravel/public`.

**Local XAMPP subfolder:** browse to `http://localhost/lfs-website-laravel/public` or configure a vhost with `DocumentRoot` pointing at `public/`.

## URL verification

- [ ] `/` — home
- [ ] `/events`, `/events/{slug}`
- [ ] `/news`, `/news/{slug}`
- [ ] `/gallery`, `/gallery/{albumId}`
- [ ] `/contact` (GET + POST)
- [ ] `/shop`, `/shop/checkout`, `/shop/order/{orderNumber}`
- [ ] `/admin/door` — admin login
- [ ] Lenco webhook: `POST /shop/checkout/webhook` (update URL in Lenco dashboard if domain changes)

## Post-cutover

- [ ] Smoke test admin CRUD (events, blog, gallery, products, messages, orders)
- [ ] Verify uploaded images and brochures load from `/storage/uploads/...` or `/uploads/...`
- [ ] Archive or retire the legacy `lfs-website-php` deploy (keep in git history)
- [ ] Update Lenco webhook URL if the public hostname changed

## Rollback

Point Apache document root back to `lfs-website-php/` (legacy `index.php` front controller). Database is shared — no schema rollback needed if migrations were not destructive.
