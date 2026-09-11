# Faheem Innovations — Chat App (complete Laravel project)

A Chatify/Slack-style chat app — login, DMs, group chats, file
attachments, read receipts, online presence, typing indicator — built
with AJAX polling (no websockets needed).

**This is a full, real Laravel project** (it includes `composer.json`,
`artisan`, `bootstrap/`, `config/`, everything) with the chat feature
already merged in. Unlike the previous version, you do **not** need to
create a separate Laravel project and copy files around — just install
dependencies and run it.

---

## 1. Install & run

```bash
cd faheem-chat-app          # this folder
composer install            # downloads Laravel + dependencies (needs internet)
cp .env.example .env
php artisan key:generate

touch database/database.sqlite   # the .env is already set to use SQLite
php artisan migrate
php artisan storage:link         # needed so uploaded files/images are viewable

php artisan serve
```

Visit **http://localhost:8000**. Click "Sign up", create an account, then
open a second browser (or an incognito window) and register a second
account so you have someone to chat with.

> Using MySQL/Postgres instead of SQLite? Just edit the `DB_*` values in
> `.env` before running `php artisan migrate`.

---

## 2. What's included

| Path | What it is |
|---|---|
| `app/Models/{Conversation,Message,...}.php` | Chat data model |
| `app/Http/Controllers/Api/*` | Shared business logic (conversations, messages, users, auth) |
| `app/Http/Controllers/Web/*` | Session-based login/register + the page that serves the chat UI |
| `database/migrations/2024_*` | Chat tables (added on top of Laravel's default users/cache/jobs tables) |
| `resources/views/auth/*` | Login & register pages |
| `resources/views/chat/index.blade.php` | The chat UI shell (sidebar + messages + new-chat modal) |
| `public/js/chat.js` | All client-side logic: polling, sending, presence, typing |
| `public/css/chat.css` | Chat-specific styling on top of Tailwind (loaded via CDN — no build step) |
| `routes/web.php` | Pages (`/login`, `/register`, `/chat`) + the AJAX endpoints under `/app/*` |
| `routes/api.php.optional` | A token-based REST API version of the same features — see step 4 below to enable it |

---

## 3. Feature tour

- **Sidebar** — polls `/app/conversations` every 4s: title/avatar, last message preview, unread badge, online dot.
- **New chat modal** — "Direct message" (pick one person, opens instantly) or "Group chat" (pick multiple + a name).
- **Messages** — polls `/app/conversations/{id}/messages?after_id=...` every ~2.5s; your own sent messages appear instantly.
- **Attachments** — 📎 button attaches one file per message; images preview inline, other files show as a download link.
- **Read receipts** — opening/receiving in a conversation marks it read; unread counts show for chats you haven't opened.
- **Typing indicator** — keystrokes ping `/app/conversations/{id}/typing`; everyone else polls it and sees "X is typing…" (auto-expires after 5s).
- **Presence** — the browser calls `/app/heartbeat` every 30s; a user is considered online if `last_seen_at` is within ~2 minutes.

---

## 4. Optional: enable the token API too

If you also want a headless REST API (for a mobile app, Postman, etc.)
using the same data:

```bash
php artisan install:api
```

This installs Sanctum and registers `routes/api.php`. Then replace the
generated `routes/api.php` with the contents of `routes/api.php.optional`
in this project. Full endpoint docs are in the comments at the top of
that file.

---

## 5. Customizing

- **Styling** — Tailwind utility classes directly in the Blade files, loaded via CDN in `resources/views/layouts/app.blade.php`. No build step, so edit freely; swap in a real Tailwind build later if you want production purging.
- **Avatars** — currently colored initials; add an upload flow to the `public` disk if you want real photos.
- **Group management UI** — the backend supports adding participants / leaving a group (`POST/DELETE /app/conversations/{id}/participants|leave`); only "create group" has UI so far — add buttons in the chat header for the rest if needed.
- **Real-time upgrade path** — swap polling for Laravel Reverb/Pusher later without touching the data model: broadcast a `MessageSent` event from `MessageController::store` and replace the `setInterval` polling in `chat.js` with an Echo listener.
- **File size limit** — 20MB by default (`MessageController::store`); adjust the `max` validation rule and your `php.ini` (`upload_max_filesize`, `post_max_size`) if needed.
