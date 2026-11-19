# Ping Chat

A team chat app (channels, DMs, threads, huddles, canvases, files, reminders) with a **task module** and **personal AI agents** that find tasks in conversations and help each person stay on top of their work.

- **Web:** Laravel 12 + Inertia v2 + React 18 + TypeScript + Tailwind (Vite 7)
- **Real time:** Laravel Reverb (WebSockets) + Laravel Echo
- **Mobile:** the same features over a token API at `/api/v1` (Laravel Sanctum). See [docs/mobile-api.md](docs/mobile-api.md)
- **Database:** PostgreSQL 17 (tests use in-memory SQLite)
- **AI:** Anthropic Claude through the app's own provider layer. See [docs/ai-agents.md](docs/ai-agents.md)

---

## 1. Requirements

| Tool | Version | Notes |
|---|---|---|
| PHP | 8.2+ | Extensions: `pdo_pgsql`, `pgsql`, `openssl`, `mbstring`, `fileinfo`, `gd` |
| Composer | 2.x | |
| Node.js | 20+ | With npm |
| PostgreSQL | 17 | Local server on `127.0.0.1:5432` |

> **Windows / XAMPP:** enable `extension=pdo_pgsql` and `extension=pgsql` in `C:\xampp\php\php.ini`. If generating keys fails with an OpenSSL error, set `OPENSSL_CONF=C:\xampp\php\extras\ssl\openssl.cnf`.

## 2. First-time setup

```bash
composer install
npm install

cp .env.example .env          # then fill in the values below
php artisan key:generate
php artisan reverb:install    # only if REVERB_* keys are empty

# Create the database first (e.g. in psql: CREATE DATABASE ping_slack;)
php artisan migrate

# There is no public sign-up: create the first user (workspace owner)
php artisan chat:first-user you@example.com "Your Name"
```

Key `.env` values:

```dotenv
APP_URL=http://localhost:5000

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=ping_slack
DB_USERNAME=postgres
DB_PASSWORD=...

QUEUE_CONNECTION=database     # background jobs (AI, push notifications)
BROADCAST_CONNECTION=reverb   # real-time updates

# AI agents (optional: the chat app works without them)
AI_PROVIDER=anthropic
ANTHROPIC_API_KEY=            # server only; never exposed to web or mobile clients
AI_ANTHROPIC_MODEL=claude-opus-5-5
```

## 3. Running the app locally

Open **5 terminals** in the project folder:

| # | Command | What it does |
|---|---|---|
| 1 | `php artisan serve --port=5000` | Web server: http://localhost:5000 (the `--port` is required) |
| 2 | `php artisan reverb:start` | WebSocket server (port 8080) for live updates |
| 3 | `php artisan queue:work --queue=ai,default` | Background jobs: AI agents, push notifications |
| 4 | `php artisan schedule:work` | Scheduler: reminders, scheduled messages, overdue alerts, daily summaries |
| 5 | `npm run dev` | Vite dev server for the React frontend |

Then open **http://localhost:5000** and choose **Sign in with a password**.

**Notes**
- After changing PHP code used by background jobs, **restart terminal 3** (Ctrl+C, run again). Web requests pick up changes on their own.
- Terminal 3 shows `RUNNING` / `DONE` per job. An empty screen just means it's waiting for work.
- Without terminal 3 the chat still works, but AI agents, push notifications and other queued work wait until it starts.

## 4. Tests

```bash
php artisan test                         # full suite (SQLite in memory, fake AI: no API key or cost)
php artisan test tests/Feature/AI        # AI agents and tasks only
npx tsc --noEmit                         # TypeScript check
npm run build                            # production build
vendor/bin/pint                          # PHP code style
```

Tests never call a real AI service: `phpunit.xml` sets `AI_PROVIDER=fake`, and each test scripts the AI's answers.

> Tests run on SQLite, so PostgreSQL-only problems (for example, a failed statement aborting a whole transaction) only show on the real database. Check database-touching changes against PostgreSQL as well.

## 5. Project map

| Area | Where |
|---|---|
| Chat (channels, messages, threads, DMs) | `app/Http/Controllers/ChannelController.php`, `MessageController.php`, `app/Services/MessagePoster.php` |
| Tasks | `app/Services/Tasks/` (`TaskService`, `TaskAccess`, `TaskQuery`, `TaskPresenter`), `app/Http/Controllers/Tasks/` |
| AI agents | `app/Ai/` (runner, tools, prompts, providers), `app/Services/AI/`, `app/Jobs/AI/`, `app/Http/Controllers/AI/` |
| Message → agent hook | `app/Observers/MessageObserver.php` |
| AI settings and limits | `config/ai.php` |
| Web routes / mobile API | `routes/web.php` / `routes/api.php` (`/api/v1`) |
| Scheduled commands | `routes/console.php` |
| Frontend | `resources/js/Pages/Workspace/Show.tsx`, `resources/js/Components/Chat/`, `resources/js/Components/AI/` |
| Tests | `tests/Feature/`, `tests/Feature/AI/`, `tests/Feature/Tasks/` |

## 6. Documentation

- [docs/ai-agents.md](docs/ai-agents.md): how the AI agents and task detection work, permissions, safety, costs, configuration and troubleshooting
- [docs/mobile-api.md](docs/mobile-api.md): API guide for the mobile team (tasks, agents, approvals, real-time events)

## 7. Production checklist

- Run the queue worker under a process manager (Supervisor/systemd): `php artisan queue:work --queue=ai,default`
- Cron: `* * * * * php artisan schedule:run`
- Run Reverb behind TLS (`wss://`), and set `REVERB_*` / `VITE_REVERB_*`
- `npm run build`, `php artisan migrate --force`, `php artisan optimize`
- Keep `ANTHROPIC_API_KEY` in the server environment only, and review the cost limits in `config/ai.php`
