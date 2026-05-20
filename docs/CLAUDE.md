# CLAUDE.md

This file is the entry point for Claude Code. Read this first before writing any code or making any changes.

---

## Project Summary

Build an AI-powered triage system for **Northwind Home Services**.
Inbound customer messages (email / webform / SMS) are automatically classified, prioritised, routed, and given a draft reply by an AI agent.

**Stack:** Laravel 11 (PHP 8.2) · React 18 + Vite (Laravel-integrated) · Anthropic Claude API

---

## Reference Documents

Read these documents before starting any task. They are the source of truth.

| File | Purpose |
|---|---|
| `docs/requirements.md` | Full functional and non-functional requirements |
| `docs/DESIGN.md` | Architecture, class design, API spec, data flows |
| `docs/DETAIL_DESIGN.md` | Full class definitions, component code, error flows, config files |
| `docs/system_prompt.md` | The complete system prompt for the triage agent — copy exactly |

---

## Repository Structure

```
northwind-triage/
├── CLAUDE.md                             ← you are here
├── docs/
│   ├── requirements.md
│   ├── DESIGN.md
│   ├── DETAIL_DESIGN.md
│   └── system_prompt.md
├── # Laravel 11 (includes React + Vite) — project root
│   ├── bootstrap/
│   │   └── app.php                       # ValidationException → 400 (Laravel 11 style)
│   ├── app/
│   │   ├── Http/Controllers/
│   │   │   └── TriageController.php
│   │   └── Services/
│   │       └── TriageAgentService.php
│   ├── config/
│   │   └── services.php                  # anthropic key/model/max_tokens
│   ├── resources/
│   │   ├── js/                           # React source files
│   │   │   ├── app.jsx                   # React entry point
│   │   │   └── components/
│   │   │       ├── MessageForm.jsx
│   │   │       ├── TriageResult.jsx
│   │   │       ├── CategoryBadge.jsx
│   │   │       └── PriorityBadge.jsx
│   │   └── views/
│   │       └── app.blade.php             # SPA shell — loads Vite assets
│   ├── routes/
│   │   ├── api.php                       # POST /api/triage, GET /api/health
│   │   └── web.php                       # catch-all → app.blade.php
│   ├── vite.config.js                    # @vitejs/plugin-react config
│   ├── package.json
│   ├── .env                              # never commit
│   ├── .env.example
│   ├── .php-cs-fixer.php
│   ├── .prettierrc
│   └── phpstan.neon
├── data/
│   ├── 05_Inbound_Messages.json          # 20 inbound messages (read-only)
│   ├── 06_Benchmark.json                 # gold-standard answers (read-only)
│   └── batch_results.json                # written by batch_run.php
└── scripts/
    └── batch_run.php                     # batch runner and scorer
```

---

## Key Rules — Read Before Writing Any Code

### Architecture — Laravel-integrated Vite
- React lives inside Laravel at `resources/js/`.
- Vite is configured via `vite.config.js` using `laravel-vite-plugin` + `@vitejs/plugin-react`.
- Laravel serves the SPA shell via `resources/views/app.blade.php`.
- `routes/web.php` has a catch-all route that returns `app.blade.php`.
- Because frontend and backend share the same origin (`localhost:8000`), **no CORS config is needed**.
- API calls from React use the relative path `/api/triage` (not `http://localhost:8000/api/triage`).

### Startup
- **Single command:** `php artisan serve --port=8000` starts everything.
- For hot module replacement during development, run `npm run dev` in a second terminal (optional).
- Production build: `npm run build` — Vite compiles assets into `public/build/`.

### Agent
- The system prompt lives in `TriageAgentService` as a `SYSTEM_PROMPT` constant.
- Copy the full prompt text from `docs/system_prompt.md` exactly — do not paraphrase or summarise.
- Model: `claude-sonnet-4-6`. Max tokens: 1024.
- The agent must handle all 20 messages with the same prompt. No per-message logic.
- Always strip markdown fences from the model response before JSON parsing.
- API call must include headers: `x-api-key`, `anthropic-version: 2023-06-01`, `content-type: application/json`.
- Set HTTP timeout to 30 seconds on every API call.

### Backend
- Single entry point: `POST /api/triage`
- Also expose: `GET /api/health`
- Validate that `body` is required. All other fields are optional.
- Override Laravel's default 422 validation response → return HTTP 400 via `bootstrap/app.php` (`withExceptions()`).
- Return HTTP 500 for API errors and JSON parse failures.
- Log all errors with `Log::error()` including the message body as context.
- Anthropic credentials are loaded via `config/services.php` using `env()` — never hardcode.
- Use `private readonly TriageAgentService $agent` in TriageController constructor.

### TriageAgentService — required private methods
- `triage(array $message): array` — main entry point
- `buildUserMessage(array $message): string` — formats channel/sender/subject/body/received_at + channel context hint
- `stripMarkdownFences(string $raw): string` — strips ` ```json ` fences if model adds them
- `validateResult(array $data): array` — checks all 6 required fields exist, casts types, throws on missing field

### Frontend
- React entry point: `resources/js/app.jsx`
- Components: `MessageForm`, `TriageResult`, `CategoryBadge`, `PriorityBadge`
- Must display all 6 fields: `category`, `priority`, `route_to`, `needs_human_review`, `draft_reply`, `reasoning`
- `needs_human_review = true` must show a red warning banner
- No external state management library — React `useState` is sufficient
- API calls use relative path: `fetch('/api/triage', ...)`
- Category colour mapping: EMERGENCY=red, COMPLAINT=orange, BOOKING=blue, QUOTE=purple, BILLING=yellow, OUT_OF_SCOPE=grey
- Priority colour mapping: P1=red, P2=amber, P3=green

### Data files
- `data/05_Inbound_Messages.json` and `data/06_Benchmark.json` are **read-only**. Never modify them.
- The batch runner reads both files and writes results to `data/batch_results.json`.
- Add `usleep(500000)` between API calls in the batch runner to avoid rate limiting.

### Pricing rule (critical)
- The draft reply must **never** quote a "from" price.
- It **may** quote a fixed price (e.g. "$120 for a tap washer").
- Full price list is in `docs/system_prompt.md` under "SERVICE CATALOGUE REFERENCE".

---

## Routing Reference

The agent must return one of these exact values in `route_to`:

| Category | route_to | Notes |
|---|---|---|
| BOOKING | `Dispatch` | |
| EMERGENCY | `Dispatch` | On-call for plumbing and electrical |
| QUOTE | `Sales` | |
| BILLING | `Accounts` | |
| COMPLAINT | `Customer Care` | |
| OUT_OF_SCOPE | `Customer Care` | Sends polite decline |
| COMPLAINT with billing dispute > $500 | `Customer Care + Accounts` | CC both teams |

After-hours HVAC fault → `BOOKING`, `P2`, `Dispatch`. Draft reply must include "we'll be in touch first thing tomorrow".

---

## Build Order

Follow this order. Do not skip ahead.

```
1. vite.config.js
   - laravel-vite-plugin + @vitejs/plugin-react

2. package.json
   - react, react-dom, @vitejs/plugin-react, laravel-vite-plugin
   - prettier, eslint-config-prettier (dev)
   - scripts: dev, build, lint, format

3. resources/views/app.blade.php
   - SPA shell with @vite(['resources/js/app.jsx'])

4. routes/web.php
   - catch-all Route::get → return view('app')

5. app/Services/TriageAgentService.php
   - SYSTEM_PROMPT constant (copy from docs/system_prompt.md)
   - triage(array $message): array
   - buildUserMessage(array $message): string
   - stripMarkdownFences(string $raw): string
   - validateResult(array $data): array

6. config/services.php
   - anthropic.key, anthropic.model, anthropic.max_tokens

7. app/Http/Controllers/TriageController.php
   - triage(Request $request): JsonResponse
   - health(): JsonResponse

8. bootstrap/app.php
   - ValidationException → HTTP 400 (via withExceptions())

9. routes/api.php
   - POST /triage → TriageController@triage
   - GET  /health → TriageController@health

10. .env.example
    - ANTHROPIC_API_KEY=
    - ANTHROPIC_MODEL=claude-sonnet-4-6
    - ANTHROPIC_MAX_TOKENS=1024

11. scripts/batch_run.php
    - Load data/05_Inbound_Messages.json
    - Call TriageAgentService for each message
    - scoreMessage() and scoreRoute() functions
    - Score against data/06_Benchmark.json
    - Print summary + write data/batch_results.json

12. resources/js/components/CategoryBadge.jsx
13. resources/js/components/PriorityBadge.jsx
14. resources/js/components/MessageForm.jsx
15. resources/js/components/TriageResult.jsx
16. resources/js/app.jsx
    - useState for loading / result / error
    - fetch('/api/triage') on submit
```

---

## Key Config Files

### vite.config.js
```js
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';

export default defineConfig({
  plugins: [
    laravel({ input: ['resources/js/app.jsx'], refresh: true }),
    react(),
  ],
});
```

### resources/views/app.blade.php
```html
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Northwind Triage</title>
  @vite(['resources/js/app.jsx'])
</head>
<body>
  <div id="app"></div>
</body>
</html>
```

### resources/js/app.jsx (entry point)
```jsx
import { createRoot } from 'react-dom/client';
import App from './components/App';

createRoot(document.getElementById('app')).render(<App />);
```

---

## API Contract

### POST /api/triage

Request:
```json
{
  "body":        "string (required)",
  "channel":     "email | webform | sms (optional)",
  "sender_name": "string (optional)",
  "subject":     "string | null (optional)",
  "received_at": "ISO8601 (optional)"
}
```

Response 200:
```json
{
  "category":           "EMERGENCY",
  "priority":           "P1",
  "route_to":           "Dispatch",
  "needs_human_review": false,
  "draft_reply":        "Hi Kevin — ...",
  "reasoning":          "SOP explicitly lists..."
}
```

Response 400:
```json
{ "error": "The body field is required." }
```

Response 500:
```json
{ "error": "Anthropic API call failed: ..." }
```

---

## Scoring Logic (batch_run.php)

| Field | Scoring |
|---|---|
| `category` | exact match = 1, no match = 0 |
| `priority` | exact match = 1, no match = 0 |
| `route_to` | exact match = 1, primary team correct but cc missed = 0.5, no match = 0 |
| `needs_human_review` | exact match = 1, no match = 0 |

**Strict accuracy** = messages where all 4 fields score 1 ÷ 20 × 100%

`scoreRoute()` logic:
- Exact match → 1.0
- `explode(' + ', $agent)[0]` === `explode(' + ', $bench)[0]` → 0.5 (primary correct, cc missed)
- Otherwise → 0.0

Output to `data/batch_results.json`:
```json
{
  "summary": {
    "strict_accuracy":   0.0,
    "category_accuracy": 0.0,
    "priority_accuracy": 0.0,
    "route_accuracy":    0.0,
    "flag_accuracy":     0.0
  },
  "results": [
    {
      "id":        "MSG-001",
      "agent":     { "category": "...", "priority": "...", "route_to": "...", "needs_human_review": false, "draft_reply": "...", "reasoning": "..." },
      "benchmark": { "category": "...", "priority": "...", "route_to": "...", "needs_human_review": false },
      "scores":    { "category": 1, "priority": 1, "route_to": 1, "needs_human_review": 1, "strict_match": true }
    }
  ]
}
```

---

## Error Handling Reference

### Backend error flow
```
body missing               → 400 { "error": "The body field is required." }
ANTHROPIC_API_KEY not set  → 500 { "error": "ANTHROPIC_API_KEY is not set." }
API non-2xx response       → 500 { "error": "Anthropic API call failed: ..." }
malformed JSON             → 500 { "error": "Failed to parse agent response as JSON: ..." }
missing field in response  → 500 { "error": "Agent response missing required field: ..." }
All errors                 → Log::error() with message body as context
```

### Frontend error flow
```
Network error (backend not running) → display "Failed to fetch"
HTTP 400                            → display data.error
HTTP 500                            → display data.error
User can correct and retry          → no page reload needed
```

---

## Local Development Commands

### Install and start
```bash
composer install
npm install
npm install --save-dev prettier eslint-config-prettier
cp .env.example .env
# Set ANTHROPIC_API_KEY in .env
php artisan key:generate
php artisan serve --port=8000
# Visit http://localhost:8000
```

### Hot module replacement (optional, second terminal)
```bash
npm run dev
```

### Build for production
```bash
npm run build
```

### Batch runner
```bash
php scripts/batch_run.php
```

### Static analysis
```bash
# PHP
composer require --dev friendsofphp/php-cs-fixer phpstan/phpstan
./vendor/bin/php-cs-fixer fix --dry-run --diff
./vendor/bin/phpstan analyse app --level=5

# JS
npm run lint
npx prettier --check resources/js/
```

---

## Environment Variables

| Variable | Required | Description |
|---|---|---|
| `ANTHROPIC_API_KEY` | ✅ Yes | Anthropic API key (sk-ant-...) |
| `ANTHROPIC_MODEL` | No | Default: `claude-sonnet-4-6` |
| `ANTHROPIC_MAX_TOKENS` | No | Default: `1024` |

---

## Do Not

- Do not create a separate `frontend/` directory — React lives in `resources/js/`
- Do not configure CORS — same origin, not needed
- Do not use `http://localhost:8000/api/triage` in React — use relative path `/api/triage`
- Do not modify `data/05_Inbound_Messages.json` or `data/06_Benchmark.json`
- Do not hardcode `ANTHROPIC_API_KEY`
- Do not commit `.env`
- Do not hand-tune the system prompt to pass specific benchmark cases — adjust the rules, not the answers
- Do not build multiple agents or a routing layer — one prompt, one API call per message
- Do not quote "from" prices in draft replies
- Do not use exclamation marks or emoji in draft replies
- Do not add unnecessary dependencies or architectural complexity
- Do not skip `stripMarkdownFences()` — the model occasionally adds fences despite instructions
- Do not return 422 for validation errors — override to 400 in `bootstrap/app.php`
- Do not forget `usleep(500000)` between calls in the batch runner
