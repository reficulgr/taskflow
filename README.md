# TaskFlow

A time-budgeted task manager built around a simple idea: time, not checklists. Every day gets a fixed number of minutes per life category, and the app tells you what's worth working on right now.

## What it does

**Category-budgeted days.** Work is sorted into five categories — *Revenue Today, Revenue Tomorrow, Exploration, Entertainment, Social-Household* — each with its own daily minute budget. You can transfer unused minutes between categories for the day when plans change.

**Three kinds of tasks.**
- **Flexible** — pick up whenever priority says so
- **Recurring** — schedules like *every N weeks* or *specific weekdays*, with an `eligible` gate so a task can't be completed twice in one cycle
- **One-Off** — dated tasks with deadlines

**A priority engine.** Instead of you triaging a pile, `get_next_up` computes the top 3 tasks right now, factoring in eligibility, snoozes, deadlines and remaining category budget.

**Skills as a measurement of work.** Tasks and completions are tagged with skills from a personal skill tree rooted in four archetypes (*Magician, Lover, Warrior, King*). The tree is a DAG — a skill can have many parents — and completed minutes roll up through every ancestor branch, deduplicated per completion. Tree edits (rename, re-parent, archive) re-interpret history on the next read; deleting a skill that's referenced is refused — archive instead.

**First-class timer.** Start, watch and complete timers against tasks; the timer stamps the history entry with actual start/end times.

**API + MCP integration.** Everything the UI can do is exposed over a token-authenticated HTTP API, and a bundled MCP server turns that API into MCP tools — so an AI assistant (e.g. opencode) can *act on your TaskFlow on your behalf*: read next-up, start timers, complete tasks, manage the skill tree.

## Repo layout

```
├── index.php            Task list (main UI)
├── browse.php / edit_task.php / edit_completed.php / complete.php / action.php
├── calendar.php         Scheduled view
├── collections.php      Projects / collections
├── report.php           Time reports
├── skills.php           Skill tree management UI
├── settings.php         App settings (incl. API token management)
├── login.php            Registration + login
├── api.php              HTTP API (all MCP tools map to endpoints here)
├── api_auth.php         Bearer-token auth (SHA-256 hashed tokens in DB)
├── api_token.php        Token generation UI
├── db.php               PDO connection + session hardening
├── config.example.php   Template for the gitignored config.php
├── functions.php        Shared logic (priority engine, budgets, skills roll-up)
├── skill_spider script (skills_spider.js) + timer.js
├── schema.sql           Full database schema
├── migrate_*.sql        Incremental migrations
├── mcp-server/          Python MCP server wrapping the API
└── .github/workflows/deploy.yml  FTPS deployment to prod on push to main
```

## Setup

Requirements: PHP 8 with PDO MySQL, a MySQL/MariaDB database, and a web server. No framework, no build step — it runs on plain shared hosting.

1. Import `schema.sql` into the database, then apply `migrate_*.sql` in order.
2. Create `config.php` from `config.example.php` and fill in real credentials:

   ```php
   define('DB_HOST', 'localhost');
   define('DB_NAME', 'your_db_name');
   define('DB_USER', 'your_db_user');
   define('DB_PASS', 'your_db_password');
   ```

   `config.php` is gitignored — it lives only on the server.
3. Point the web root at this directory. Sessions are stored in `sessions/`, which `db.php` prepares automatically (0700 dir + Apache deny rules).

### MCP server

```bash
cd mcp-server
uv sync                     # creates .venv from pyproject/uv.lock
uv run server.py
```

Configure it in your MCP client with two environment variables:

| Variable             | Purpose                                   |
|----------------------|-------------------------------------------|
| `TASKFLOW_API_BASE`  | API URL (defaults to prod)                |
| `TASKFLOW_API_TOKEN` | Your personal token — treat like a password |

Generate the token in the app under **Settings → Manage API Token**. The raw value is shown once; only its hash is stored server-side.

## Deployment

On every push to `main` (plus a manual **Run workflow** button), GitHub Actions uploads the repo to production over FTPS (`curl`, one file at a time — no deletes, so server-side files can never be wiped by a deploy).

GitHub Actions secrets used:

| Secret            | Contents                            |
|-------------------|-------------------------------------|
| `FTP_HOST`        | FTP server hostname                 |
| `FTP_USER`        | FTP username                        |
| `FTP_PASS`        | FTP password                        |
| `FTP_REMOTE_DIR`  | Target directory on the server      |

Excluded from deployment: `.git*`, `.github/`, `mcp-server/`, `*.sql`, `config.example.php`. **`config.php` is server-owned** — deploy it manually (once) as it is not in git:

```bash
curl --ssl-reqd --ftp-create-dirs -u "$FTP_USER:$FTP_PASS" \
  -T config.php "ftp://$FTP_HOST/$FTP_REMOTE_DIR/config.php"
```

## Security notes

- DB credentials live only in the gitignored `config.php`; `config.example.php` is the shared template.
- API tokens are stored as SHA-256 hashes; generating a new token revokes the old one.
- Sessions: strict mode, HttpOnly + SameSite=Lax cookies, 30-day lifetime, private save path outside the default GC scope.
- Deployment secrets exist only as encrypted GitHub Actions secrets and are masked in all workflow logs.