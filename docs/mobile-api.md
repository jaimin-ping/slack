# Mobile API Guide: Tasks & AI Agents

Everything in the web app's **Tasks** and **Agents** panels is available to the mobile app with the **same rules**. The server checks every permission, so the app never needs to enforce access itself.

- **Base URL:** `https://<host>/api/v1`
- **Format:** JSON. Send `Accept: application/json`.
- **Auth:** `Authorization: Bearer <token>` (Laravel Sanctum personal access token)
- **Workspace:** most routes start with `/w/{slug}`, the workspace slug from `GET /workspaces`.
- **Dates:** ISO 8601 (`2026-11-20T18:00:00+05:30`). Responses are in UTC with an offset. Show them in the user's time zone.

> The AI provider key never leaves the server. No response contains provider credentials, and the app never calls the AI directly.

---

## 1. Authentication

```http
POST /api/v1/login
{ "email": "jaimin@ping.local", "password": "password", "device_name": "Pixel 8" }
```

- **Success:** `{ "token": "1|abc...", ... }`. Store the token securely and send it on every request.
- **Two-factor:** if 2FA is on, the first call returns `two_factor_required: true`. Call again with `"code": "123456"`.
- **Other sign-in options** (already used by the app): `POST /login/email` with `POST /login/code` (email code), and `POST /sso/exchange`.

## 2. Errors

| Status | Meaning | What to do |
|---|---|---|
| 401 | Missing or invalid token | Sign in again |
| 403 | Not allowed (e.g. not a workspace member, or not allowed to manage this) | Show "You don't have permission" |
| 404 | Not found **or not visible to you** (someone else's agent or a private task) | Treat as gone |
| 422 | Validation failed: `{ "message": "...", "errors": { "field": ["..."] } }` | Show the field errors |
| 429 | Too many requests | Retry later |

---

## 3. Tasks

### Task object

```json
{
  "id": 15, "workspace_id": 1,
  "title": "Write the deployment checklist", "description": null,
  "status": "pending", "paused_from": null, "priority": "normal",
  "start_date": null, "due_at": "2026-11-23T18:29:59+00:00", "completed_at": null,
  "archived": false, "is_overdue": false, "confidence": 0.9,
  "creator": { "id": 2, "name": "Jaimin", "avatar_url": null },
  "agent": { "id": 10, "name": "Max", "display_name": "Max (AI Assistant)", "avatar_url": null, "avatar_preset": "robot" },
  "assignees": [{ "id": 2, "name": "Jaimin", "avatar_url": null }],
  "source": { "available": true, "state": "active", "message_id": 19, "channel_id": 1,
              "channel_type": "public", "channel_label": "#general",
              "quote": "please write the deployment checklist by Monday", "url": "/w/ping-holdings/c/1?message=19" },
  "can": { "manage": true, "work": true },
  "created_at": "...", "updated_at": "..."
}
```

**Fields to know:**
- `agent` is `null` for tasks people created. When it's set, show an **AI** badge.
- `can.manage` (edit, assign, delete, archive) and `can.work` (change status, comment) tell you which buttons to show.
- `source.available: false` means the original message was deleted or can't be seen.
- `GET /tasks/{id}` also returns `dependencies` and `history` (every event, including comments, oldest first).

**Values:**
- `status`: `suggested`, `pending`, `in_progress`, `blocked`, `paused`, `completed`, `cancelled`
- `priority`: `low`, `normal`, `high`, `urgent`

### Endpoints (`/w/{slug}/tasks`)

| Method | Path | Body / query | Notes |
|---|---|---|---|
| GET | `/tasks` | `scope`, `status[]`, `priority[]`, `q`, `due`, `channel_id`, `agent_id`, `archived`, `sort`, `per_page`, `page` | Returns `{ data: [Task], meta: { current_page, last_page, total } }` |
| GET | `/tasks/counts` | (none) | Badge counts per scope, plus `overdue` |
| POST | `/tasks` | `title`*, `description`, `priority`, `start_date`, `due_at`, `assignee_ids[]`, `depends_on_ids[]`, `source_message_id` | 201 + Task |
| GET | `/tasks/{id}` | (none) | Full detail |
| PATCH | `/tasks/{id}` | Any of `title`, `description`, `priority`, `start_date`, `due_at`, `depends_on_ids[]` | Needs `can.manage` |
| DELETE | `/tasks/{id}` | (none) | 204 |
| POST | `/tasks/{id}/status` | `status`: `pending`, `in_progress`, `blocked`, `completed` or `cancelled` | Needs `can.work` |
| POST | `/tasks/{id}/pause` · `/resume` | (none) | |
| POST | `/tasks/{id}/approve` · `/reject` | `reason` (reject, optional) | For **suggested** tasks only (creator) |
| PUT | `/tasks/{id}/assignees` | `user_ids[]` (max 20) | Replaces the assignee list |
| POST | `/tasks/{id}/comments` | `body`* (max 4000) | |
| POST | `/tasks/{id}/archive` · `/unarchive` | (none) | |

**List options:**
- `scope`:
  - `mine`: assigned to me;
  - `assigned_by_me`: I created it for others;
  - `pending_approval`: **Suggestions**;
  - `team`: from conversations I'm in;
  - `completed`;
  - `all`.
- `due`: `overdue`, `today`, `week`, `none`.
- `sort`: `due`, `priority`, `recent`.

**Tabs in the web app:**

| Tab | Request |
|---|---|
| My tasks | `scope=mine` |
| I assigned | `scope=assigned_by_me` |
| Suggestions | `scope=pending_approval` |
| Team | `scope=team` |
| Done | `scope=completed` |

**Create a task from a message:** send `source_message_id`. The task keeps a link and quote, and assignees must be members of that conversation.

**Rate limits:** creating tasks is limited to 60 per minute; per-task actions to 120 per minute.

---

## 4. AI agents

### Agent object

```json
{
  "id": 8, "workspace_id": 1, "owner_id": 1,
  "name": "Nova", "display_name": "Nova (AI Assistant)",
  "description": "My personal work assistant", "personality": "...", "instructions": "...", "responsibilities": "...",
  "avatar_url": null, "avatar_preset": "rocket",
  "status": "active", "mode": "auto_tasks",
  "settings": {
    "task_detection": { "enabled": true, "realtime": true, "min_confidence": 0.7, "only_mine": false },
    "notifications": { "notify_assignees": true, "overdue_alerts": true },
    "daily_summary": { "enabled": true, "time": "18:00" }
  },
  "permissions": { "create_task": "allow", "assign_task": "approve", "update_task": "allow",
                   "send_direct_message": "approve", "send_channel_message": "approve", "reply_in_thread": "approve",
                   "create_reminder": "allow", "follow_up": "deny" },
  "sources": [{ "channel_id": 1, "type": "public", "label": "#general" }],
  "paused_at": null, "last_run_at": "...", "created_at": "...", "updated_at": "..."
}
```

**Values:**
- `status`: `active`, `paused` (answers chat, takes no action) or `inactive` (Off).
- `mode`: `suggest`, `auto_tasks` or `controlled` (Full control). Choosing a mode resets `permissions` to that mode's preset.
- Each permission: `allow`, `approve` (Ask me first) or `deny`.
- `avatar_preset`: `robot`, `spark`, `owl`, `compass`, `rocket`, `leaf`.

**Start screens with `GET /agents/options`.** It returns all of these, so the app doesn't hard-code them: `statuses`, `modes`, `policies`, `actions` (with labels), `presets` (the permissions for each mode), `avatar_presets`, `default_settings`, and `channels` (the conversations this user can grant, with labels).

### Endpoints (`/w/{slug}/agents`)

All of these are **owner only**. Someone else's agent returns **404**.

| Method | Path | Body | Notes |
|---|---|---|---|
| GET | `/agents` | (none) | My agents in this workspace (array) |
| GET | `/agents/options` | (none) | Form options (see above) |
| POST | `/agents` | `name`*, `description`, `personality`, `instructions`, `responsibilities`, `avatar_preset`, `mode`, `permissions{}`, `settings{}`, `source_channel_ids[]` | 201 + Agent. Max 10 per person. 20 per minute |
| GET | `/agents/{id}` | (none) | |
| PATCH | `/agents/{id}` | Same fields as create | |
| DELETE | `/agents/{id}` | (none) | 204 |
| POST | `/agents/{id}/status` | `status` | Admins may only switch other people's agents **off** (`inactive`). Otherwise 403 |
| PUT | `/agents/{id}/sources` | `channel_ids[]` | Only conversations the owner is in. DMs only if ticked |
| PUT | `/agents/{id}/permissions` | `mode` and/or `permissions{}` | |
| POST | `/agents/{id}/avatar` | multipart `photo` (png/jpg/webp, ≤2 MB) **or** `preset` | |
| DELETE | `/agents/{id}/avatar` | (none) | |
| GET | `/agents/{id}/avatar` | (none) | Image (`/api/v1/agents/{id}/avatar`) |

### Chat with an agent

| Method | Path | Body | Notes |
|---|---|---|---|
| GET | `/agents/{id}/chat` | `?before={messageId}` | Last 50 messages, oldest first |
| POST | `/agents/{id}/chat` | `message`* (max 4000) | **202**: the reply arrives later. 10 per minute. 422 if the agent is Off |

Chat message object: `{ "id", "agent_id", "role": "owner" | "agent", "content", "run_id", "created_at" }`

**Flow:**
1. POST the user's message. It returns the saved message right away; show it in the chat.
2. Show a "thinking…" indicator.
3. The answer arrives as the real-time event **`agent.chat`** (section 6). If the socket is down, poll `GET /chat`.

**Text format:** plain text with `**bold**` and simple `-` or `1.` lists. No headings, tables or code blocks.

### Memory

| Method | Path | Body |
|---|---|---|
| GET | `/agents/{id}/memories` | (none) |
| POST | `/agents/{id}/memories` | `kind`* (`preference`, `instruction`, `fact`, `context`), `content`* (2–2000), `pinned` |
| PATCH | `/agents/{id}/memories/{memoryId}` | Any of the above |
| DELETE | `/agents/{id}/memories/{memoryId}` | (none) |

- **Memory object:** `{ id, kind, content, origin: "owner" | "agent", pinned, created_at, updated_at }`.
- `origin: "agent"` means the agent saved it itself. Show it as **"saved by {name}"**. Editing it makes it the owner's.

### Look back (scan older messages)

```http
POST /w/{slug}/agents/{id}/analyze
{ "channel_ids": [1], "from": "2026-11-12", "to": "2026-11-19" }
```

- **Response:** 202 `{ "status": "queued", ... }`. New tasks then arrive via `task.changed`.
- **Limits:** at most 30 days, only the agent's own conversations, 5 requests per minute. The agent must be Active.

### Activity and usage

| Method | Path | Query | Returns |
|---|---|---|---|
| GET | `/agents/{id}/runs` | `status`, `trigger` (`message`, `history`, `chat`, `schedule`) | `{ data: [Run], meta }`, 30 per page |
| GET | `/agents/{id}/runs/{runId}` | (none) | Run with its `step_log` |
| GET | `/agents/usage` | `days` (1–90), `scope=mine` or `workspace` (admins only) | Runs, tokens and cost per agent |

**Run object:** `{ id, agent_id, trigger, status, model, steps, tokens: { input, output, cache_read, cache_write }, cost_usd, summary, ... }`

---

## 5. Approvals ("Waiting for approval")

When an agent needs permission (for example, to message someone), it creates an approval for its owner.

| Method | Path | Body |
|---|---|---|
| GET | `/w/{slug}/approvals` | `?status=pending` (default), `executed`, `rejected`, `failed` or `expired` |
| POST | `/w/{slug}/approvals/{id}/approve` | (none) |
| POST | `/w/{slug}/approvals/{id}/reject` | `reason` (optional) |

- **Approval object:** `{ id, agent: { id, name }, action, summary, payload, task_id, status, result, error, decided_at, created_at }`.
  - `summary` is the human-readable line to show, for example `Message Jaimin: "Hi Jaimin, …"`.
- **Approve response:**
  - `status: "executed"` means it was done.
  - `status: "failed"` with `error` means it could no longer be done: everything is re-checked at that moment, and the person or task may be gone.
- **Repeat calls:** approving the same approval twice returns 422.

---

## 6. Real-time events

Subscribe with Pusher-protocol clients (Reverb) to the private channel **`private-App.Models.User.{myUserId}`**.

**Channel auth:**

```http
POST /api/v1/broadcasting/auth
Authorization: Bearer <token>
socket_id=…&channel_name=private-App.Models.User.2
```

A user can only subscribe to their own channel; any other returns 403.

| Event | Payload | What to do |
|---|---|---|
| `task.changed` | `{ workspace_id, task_id, action }` | Refresh that task and the counts |
| `agent.chat` | Chat message object | Append to that agent's chat; hide "thinking…" |
| `agent.run` | `{ workspace_id, agent_id, run_id, trigger, status }` | Refresh Activity if it's open |
| `agent.approval` | `{ workspace_id, approval_id, agent_id, status }` | Update the approval badge and list |
| `agent.status` | `{ id, workspace_id, status, paused_at }` | Update the agent's status (e.g. an admin switched it off) |

Existing chat events (`MessageSent`, and so on) are unchanged.

---

## 7. Agent messages in chat

Messages an agent sends appear in normal channel and DM message lists (`GET /w/{slug}/c/{channel}/messages`) with these fields:

```json
{
  "id": 42, "body": "Hi Jaimin, this is Nova, Admin's AI assistant. ...",
  "is_bot": true,
  "user": { "id": 0, "name": "Nova (AI Assistant)" },
  "agent": { "id": 8, "name": "Nova", "owner_id": 1, "owner_name": "Admin", "avatar_url": null, "avatar_preset": "rocket" }
}
```

**Display rules:**
- When `agent` is present, show the agent's avatar (or the preset), the name `user.name`, and a badge **"AI · for {owner_name}"**.
- Only the **agent's owner** (and workspace admins) can delete these messages. Others get 403.

**Older app versions** that ignore `agent` still show the message correctly as a bot named "Nova (AI Assistant)".

## 8. Push notifications

Unchanged. Pushes are sent for:
- DMs, including from agents;
- @mentions;
- thread replies;
- channels set to "All messages".

Task assignment also sends a push to the assignee.
