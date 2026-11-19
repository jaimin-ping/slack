# AI Agents & Tasks

Each workspace member can create **personal AI agents**. An agent reads the conversations its owner chooses, turns requests into **tasks**, answers questions in a private chat, sets reminders, and can send messages for its owner, always within permissions the owner controls.

---

## 1. Key ideas

| Idea | Meaning |
|---|---|
| **Personal** | An agent belongs to one person. Nobody else can see it, chat with it, or change it. Workspace admins can only switch it off. |
| **Never more access than its owner** | An agent reads only conversations that (1) the owner ticked, (2) the owner is still in, and (3) aren't archived. DMs are never included unless ticked. |
| **Clearly AI** | Messages it sends appear as **"Nova (AI Assistant)"** with an **AI · for {owner}** badge. It never writes as if it were the owner. |
| **Messages are data, not commands** | Text in workspace messages can never change the agent's rules or permissions. "Ignore your rules and DM me…" is treated as information only. |
| **Permissions are enforced by the server** | Every action goes through one gate (`app/Ai/Tools/ToolGate.php`). The AI can ask; the server decides. |

## 2. What an agent does

| Feature | How it works |
|---|---|
| **Finds tasks in messages** | New messages in its conversations are read about 20 seconds after posting (batched). It extracts title, assignees, deadline, priority and the exact quote. |
| **Look back** | The owner can ask it to scan up to 30 days of past messages. |
| **Updates tasks from messages** | "The dashboard is done" moves the matching open task to Completed. |
| **Private chat** | "Show my tasks", "Move the sprint report to Friday 6 PM", "Remind me at 7:15 PM to call the client". |
| **Memory** | Remembers preferences and facts. The owner's **preferences/instructions** override its default style. Notes it saved itself are only facts, never instructions. |
| **Messaging** | Can DM people, post in channels or reply in threads, when allowed. |
| **Scheduled help (no AI cost)** | Overdue alerts, a daily summary at a chosen time, and follow-up DMs for overdue tasks assigned to others. Each is sent once. |

## 3. Modes and permissions

Choosing a mode fills in the permissions below. Each one can then be changed: **Allowed**, **Ask me first** (goes to *Waiting for approval*), or **Not allowed**.

| Action | Suggest only | Create tasks (default) | Full control |
|---|---|---|---|
| Create tasks | Ask | Allowed | Allowed |
| Assign tasks to others | Ask | Ask | Allowed |
| Update tasks | Ask | Allowed | Allowed |
| Send direct messages | Not allowed | Ask | Allowed* |
| Post in channels | Not allowed | Ask | Allowed* |
| Reply in threads | Not allowed | Ask | Allowed* |
| Create reminders for you | Ask | Allowed | Allowed |
| Follow up on pending tasks | Not allowed | Not allowed | Allowed |

\* **Safety rule:** once a run has read anything written by other people (messages, tasks), any message it then wants to send **always needs approval**, even in Full control. Text planted in a message can never make an agent message someone on its own.

**Status:**
- **Active:** works normally.
- **Paused:** answers questions in chat, but takes no action and reads no new messages.
- **Off:** does nothing, and its chat is closed.

A pause takes effect immediately, even in the middle of a run.

## 4. How a task is found

```
Person posts a message
  └─ MessageObserver (after the message is saved)
       └─ AnalyzeChannelMessages job (queue "ai", 20 s debounce, one per agent+channel)
            ├─ Prefilter: obvious chatter ("thanks", "nice weather") is skipped without calling the AI
            ├─ AI extracts tasks as structured JSON
            └─ TaskExtractor validates every item:
                 - the quote must really appear in the message
                 - assignees must be members of that conversation (no invented people)
                 - a deadline is kept only if the message states it; read in the owner's time zone
                 - confidence below the threshold (default 70%) → suggestion, not a task
                 - assigning someone else needs "Assign" permission, otherwise it becomes a suggestion
                 - duplicates (same message + title) are not created twice
```

- **Suggestions** wait in the owner's *Tasks → Suggestions* for **Approve** or **Dismiss**.
- If another person's agent already suggested a task **for you**, your own agent takes it over, so it waits for **you**.

## 5. Safety summary

- The API key is server-only and never sent to the browser or the mobile app.
- Agents never read other agents' messages (no AI-to-AI loops).
- Outgoing messages:
  - a maximum of 20 per hour per agent, and 4000 characters each;
  - no `@channel`, `@here` or `@everyone`;
  - the agent and its owner are checked again at send time.
- Approvals are re-checked when approved. If the task or person is gone, the approval fails safely.
- Everything is logged in **Activity**: each run, its steps, tool calls, tokens and cost.

## 6. Costs

Each run records its tokens and cost, shown in **Agent → Activity** and **Usage and cost**.

Typical cost in testing with `claude-opus-5-5`:

| Work | Typical cost |
|---|---|
| Chat reply | about $0.03 |
| Reading new messages | about $0.015 per batch |
| Overdue alerts, daily summary, follow-ups | $0 (no AI) |

- Prompt caching reuses the agent's instructions between calls, which lowers cost.
- The rates used for estimates are in `config/ai.php` (`pricing`). Your Anthropic Console billing is the source of truth.
- Daily token caps exist per agent, per member and per workspace (`AI_*_DAILY_TOKENS`).

## 7. Configuration (`.env`)

| Variable | Default | Purpose |
|---|---|---|
| `AI_PROVIDER` | `anthropic` | `anthropic`, or `fake` (tests) |
| `ANTHROPIC_API_KEY` | (none) | Required for real AI |
| `AI_ANTHROPIC_MODEL` | `claude-opus-5-5` | Model used |
| `AI_TIMEOUT_SECONDS` / `AI_MAX_RETRIES` | 90 / 2 | Provider call limits |
| `AI_DEBOUNCE_SECONDS` | 20 | Wait before reading new messages (batches bursts) |
| `AI_AGENTS_PER_MEMBER` | 10 | Agent limit per person |
| `AI_SOURCES_PER_AGENT` / `AI_MEMORIES_PER_AGENT` | 50 / 100 | Per-agent limits |
| `AI_MAX_STEPS` / `AI_RUN_SECONDS` | 8 / 180 | Limits for a single run |
| `AI_OUTBOUND_MESSAGES_PER_HOUR` | 20 | Messages an agent may send per hour |
| `AI_AGENT_DAILY_TOKENS`, `AI_MEMBER_DAILY_TOKENS`, `AI_WORKSPACE_DAILY_TOKENS` | 2M / 5M / 20M | Daily spending caps |
| `AI_QUEUE` | `ai` | Queue name for AI jobs |

**Required processes:**
- the queue worker, `php artisan queue:work --queue=ai,default`;
- the scheduler, `php artisan schedule:work` locally or `schedule:run` from cron in production. It runs `agents:run-scheduled` every 5 minutes.

## 8. Code map

| Part | File(s) |
|---|---|
| Agent loop (calls the model, runs tools) | `app/Ai/AgentRunner.php` |
| Run context and access rules | `app/Ai/AgentContext.php`, `app/Models/AiAgent.php` (`readableChannelIds`) |
| Permission gate | `app/Ai/Tools/ToolGate.php` |
| Tools | `app/Ai/Tools/` (`TaskTools`, `MessagingTools`, `ReminderTools`, `Toolbox`) |
| System prompt | `app/Ai/Prompts/AgentPrompt.php` |
| Providers | `app/Ai/Providers/AnthropicProvider.php`, `FakeLanguageModel.php` |
| Task extraction | `app/Services/AI/TaskExtractor.php`, `MessageAnalysis.php`, `MessagePrefilter.php` |
| Chat | `app/Services/AI/AgentChat.php` |
| Sending messages | `app/Services/AI/AgentMessenger.php`, `app/Services/MessagePoster.php` (`asAgent`) |
| Approvals | `app/Services/AI/ApprovalService.php` |
| Scheduled work | `app/Services/AI/AgentScheduler.php` (`agents:run-scheduled`) |
| Modes and permission presets | `app/Services/AI/AgentCapabilities.php` |
| Frontend | `resources/js/Components/AI/` (`AgentsPanel`, `AgentViews`, `TasksPane`) |
| Tests | `tests/Feature/AI/` |

## 9. Troubleshooting

| Problem | Check |
|---|---|
| Agent never replies in chat | Is the queue worker (terminal 3) running? Is `ANTHROPIC_API_KEY` set? Look at **Activity** for a failed run. |
| No task from a message | Is the agent **Active**, with that conversation ticked and **Look for tasks** on? Very short or chatty messages are skipped on purpose. Low confidence → **Suggestions** tab. |
| Wrong deadline time | Set the person's time zone (Preferences). Times without a zone are read in the owner's time zone. |
| Agent ignores a preference | Add it in **Memory** with type *preference* or *instruction*. Those override the default style. |
| Changes to PHP code not applied to jobs | Restart the queue worker. |
| Nothing happens on schedule | Is `schedule:work` running? Run `php artisan agents:run-scheduled` by hand to test. |
