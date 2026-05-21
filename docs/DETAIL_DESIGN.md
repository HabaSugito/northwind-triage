# Detail Design Document

**Project:** Northwind Home Services — Automated Customer Enquiry Triage System
**Date:** May 2025
**Stack:** Laravel 11 (PHP 8.2) + React 18 + Anthropic Claude API

---

## 1. Backend — TriageAgentService

### 1.1 File Location

```
app/Services/TriageAgentService.php
```

### 1.2 Responsibilities

- Hold the system prompt as a private constant
- Build the formatted user message string from inbound message fields
- Call the Anthropic API via Laravel's HTTP client
- Strip markdown fences from the response if present
- Parse the JSON response into a `TriageResult` array
- Throw exceptions on API failure or malformed JSON

### 1.3 Full Class Definition

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class TriageAgentService
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
    /* --- paste full content of docs/system_prompt.md here --- */
    PROMPT;

    private string $apiKey;
    private string $model;
    private int    $maxTokens;

    public function __construct()
    {
        $this->apiKey    = config('services.anthropic.key');
        $this->model     = config('services.anthropic.model', 'claude-sonnet-4-6');
        $this->maxTokens = (int) config('services.anthropic.max_tokens', 1024);

        if (empty($this->apiKey)) {
            throw new RuntimeException('ANTHROPIC_API_KEY is not set.');
        }
    }

    /**
     * Run triage on a single inbound message.
     *
     * @param  array{
     *   body: string,
     *   channel?: string,
     *   sender_name?: string,
     *   subject?: string|null,
     *   received_at?: string
     * } $message
     * @return array{
     *   category: string,
     *   priority: string,
     *   route_to: string,
     *   needs_human_review: bool,
     *   draft_reply: string,
     *   reasoning: string
     * }
     */
    public function triage(array $message): array
    {
        $userMessage = $this->buildUserMessage($message);

        $response = Http::withHeaders([
            'x-api-key'         => $this->apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ])->timeout(30)->post('https://api.anthropic.com/v1/messages', [
            'model'      => $this->model,
            'max_tokens' => $this->maxTokens,
            'system'     => self::SYSTEM_PROMPT,
            'messages'   => [
                ['role' => 'user', 'content' => $userMessage],
            ],
        ]);

        if ($response->failed()) {
            throw new RuntimeException(
                'Anthropic API call failed: ' . $response->status() . ' ' . $response->body()
            );
        }

        $raw = $response->json('content.0.text', '');
        $raw = $this->stripMarkdownFences($raw);

        $data = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            throw new RuntimeException('Failed to parse agent response as JSON: ' . $raw);
        }

        return $this->validateResult($data);
    }

    /**
     * Format the inbound message fields into a structured string for the API.
     */
    private function buildUserMessage(array $message): string
    {
        $channelContext = match ($message['channel'] ?? 'email') {
            'email'   => 'This message came in via email (support@northwind.com.au).',
            'webform' => 'This message was submitted via the website contact form.',
            'sms'     => 'This message came in via SMS — often short and possibly after-hours.',
            default   => '',
        };

        $lines = [
            'Channel: '  . ($message['channel']     ?? 'email'),
            'Received: ' . ($message['received_at']  ?? 'unknown'),
            'From: '     . ($message['sender_name']  ?? 'Unknown'),
            'Subject: '  . ($message['subject']      ?? '(none)'),
            'Body: '     . ($message['body']         ?? ''),
        ];

        if ($channelContext) {
            $lines[] = '';
            $lines[] = '[Channel context: ' . $channelContext . ']';
        }

        return implode("\n", $lines);
    }

    /**
     * Strip markdown code fences that the model may add despite instructions.
     */
    private function stripMarkdownFences(string $raw): string
    {
        $raw = trim($raw);
        if (str_starts_with($raw, '```')) {
            $raw = preg_replace('/^```json?\n?/', '', $raw);
            $raw = preg_replace('/\n?```$/',      '', $raw);
        }
        return trim($raw);
    }

    /**
     * Validate and normalise the parsed result array.
     * Throws if required fields are missing.
     */
    private function validateResult(array $data): array
    {
        $required = ['category', 'priority', 'route_to', 'needs_human_review', 'draft_reply', 'reasoning'];

        foreach ($required as $field) {
            if (!array_key_exists($field, $data)) {
                throw new RuntimeException("Agent response missing required field: {$field}");
            }
        }

        return [
            'category'           => (string)  $data['category'],
            'priority'           => (string)  $data['priority'],
            'route_to'           => (string)  $data['route_to'],
            'needs_human_review' => (bool)    $data['needs_human_review'],
            'draft_reply'        => (string)  $data['draft_reply'],
            'reasoning'          => (string)  $data['reasoning'],
        ];
    }
}
```

### 1.4 Config Registration

Add to `config/services.php`:

```php
'anthropic' => [
    'key'        => env('ANTHROPIC_API_KEY'),
    'model'      => env('ANTHROPIC_MODEL',      'claude-sonnet-4-6'),
    'max_tokens' => env('ANTHROPIC_MAX_TOKENS', 1024),
],
```

---

## 2. Backend — TriageController

### 2.1 File Location

```
app/Http/Controllers/TriageController.php
```

### 2.2 Responsibilities

- Accept and validate the POST /api/triage request
- Delegate to TriageAgentService
- Return structured JSON responses
- Return 400 for validation errors, 500 for agent errors
- Provide GET /api/health endpoint

### 2.3 Full Class Definition

```php
<?php

namespace App\Http\Controllers;

use App\Services\TriageAgentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class TriageController extends Controller
{
    public function __construct(
        private readonly TriageAgentService $agent
    ) {}

    /**
     * POST /api/triage
     * Accepts a raw customer message and returns a structured triage decision.
     */
    public function triage(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'body'        => ['required', 'string', 'min:1'],
            'channel'     => ['nullable', 'string', 'in:email,webform,sms'],
            'sender_name' => ['nullable', 'string', 'max:255'],
            'subject'     => ['nullable', 'string', 'max:500'],
            'received_at' => ['nullable', 'string'],
        ]);

        try {
            $result = $this->agent->triage($validated);
            return response()->json($result, 200);
        } catch (Throwable $e) {
            Log::error('Triage agent error', [
                'message' => $e->getMessage(),
                'body'    => $request->input('body'),
            ]);
            return response()->json(
                ['error' => 'Anthropic API call failed: ' . $e->getMessage()],
                500
            );
        }
    }

    /**
     * GET /api/health
     */
    public function health(): JsonResponse
    {
        return response()->json(['status' => 'ok'], 200);
    }
}
```

### 2.4 Validation Error Format

Laravel's default validation returns 422. Override in `bootstrap/app.php` (via `withExceptions()`) to return 400:

```php
use Illuminate\Validation\ValidationException;

$this->renderable(function (ValidationException $e) {
    $first = collect($e->errors())->flatten()->first();
    return response()->json(['error' => $first], 400);
});
```

---

## 3. Backend — Routing

### 3.1 File Location

```
routes/api.php
```

### 3.2 Full Route Definition

```php
<?php

use App\Http\Controllers\TriageController;
use Illuminate\Support\Facades\Route;

Route::post('/triage', [TriageController::class, 'triage']);
Route::get('/health',  [TriageController::class, 'health']);
```

---

## 4. Vite Configuration (Laravel-integrated)

React lives inside Laravel. No separate frontend directory or CORS configuration is needed — frontend and API share the same origin (`localhost:8000`).

### 4.1 vite.config.js

```js
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';

export default defineConfig({
  plugins: [
    laravel({
      input: ['resources/js/app.jsx'],
      refresh: true,
    }),
    react(),
  ],
});
```

### 4.2 resources/views/app.blade.php

SPA shell — loaded by the web catch-all route.

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

### 4.3 routes/web.php

Catch-all route returns the SPA shell for all non-API requests.

```php
<?php

use Illuminate\Support\Facades\Route;

Route::get('/{any}', function () {
    return view('app');
})->where('any', '.*');
```

### 4.4 resources/js/app.jsx (React entry point)

```jsx
import { createRoot } from 'react-dom/client';
import App from './components/App';

createRoot(document.getElementById('app')).render(<App />);
```

---

## 5. Backend — Environment Files

### 5.1 .env.example

```dotenv
APP_NAME=NorthwindTriage
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=http://localhost:8000

ANTHROPIC_API_KEY=
ANTHROPIC_MODEL=claude-sonnet-4-6
ANTHROPIC_MAX_TOKENS=1024
```

---

## 6. Batch Runner — batch_run.php

### 6.1 File Location

```
scripts/batch_run.php
```

### 6.2 Full Script Definition

```php
<?php

/**
 * Northwind Triage — Batch Runner and Scorer
 *
 * Usage:
 *    *   php scripts/batch_run.php
 *
 * Output:
 *   - Summary table printed to stdout
 *   - Full results written to ../data/batch_results.json
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Services\TriageAgentService;

// Bootstrap Laravel outside of HTTP context
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// ── Load data ──────────────────────────────────────────────────────────────

$messagesPath  = __DIR__ . '/data/05_Inbound_Messages.json';
$benchmarkPath = __DIR__ . '/data/06_Benchmark.json';
$outputPath    = __DIR__ . '/data/batch_results.json';

$messages  = json_decode(file_get_contents($messagesPath),  true)['messages'];
$benchmark = json_decode(file_get_contents($benchmarkPath), true)['decisions'];

$benchmarkById = array_column($benchmark, null, 'id');

// ── Run agent ──────────────────────────────────────────────────────────────

$agent   = new TriageAgentService();
$results = [];

foreach ($messages as $i => $message) {
    $id = $message['id'];
    echo "[" . ($i + 1) . "/20] Processing {$id}...\n";

    try {
        $agentOutput = $agent->triage($message);
    } catch (Throwable $e) {
        echo "  ERROR: " . $e->getMessage() . "\n";
        continue;
    }

    $bench  = $benchmarkById[$id] ?? null;
    $scores = $bench ? scoreMessage($agentOutput, $bench) : null;

    $results[] = [
        'id'        => $id,
        'agent'     => $agentOutput,
        'benchmark' => $bench ? [
            'category'           => $bench['category'],
            'priority'           => $bench['priority'],
            'route_to'           => $bench['route_to'],
            'needs_human_review' => $bench['needs_human_review'],
        ] : null,
        'scores'    => $scores,
    ];

    // Avoid rate limiting
    usleep(500000); // 0.5s between calls
}

// ── Score ──────────────────────────────────────────────────────────────────

$summary = calculateSummary($results);

// ── Output ─────────────────────────────────────────────────────────────────

printSummary($summary);

file_put_contents($outputPath, json_encode([
    'summary' => $summary,
    'results' => $results,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "\nFull results written to: {$outputPath}\n";

// ── Functions ──────────────────────────────────────────────────────────────

/**
 * Score one message against its benchmark entry.
 *
 * @return array{category: int, priority: int, route_to: float, needs_human_review: int, strict_match: bool}
 */
function scoreMessage(array $agent, array $bench): array
{
    $category  = (int) ($agent['category']           === $bench['category']);
    $priority  = (int) ($agent['priority']            === $bench['priority']);
    $flag      = (int) ($agent['needs_human_review']  === $bench['needs_human_review']);
    $route     = scoreRoute($agent['route_to'], $bench['route_to']);

    return [
        'category'           => $category,
        'priority'           => $priority,
        'route_to'           => $route,
        'needs_human_review' => $flag,
        'strict_match'       => ($category === 1 && $priority === 1 && $route === 1.0 && $flag === 1),
    ];
}

/**
 * Score route_to:
 *   1.0  — exact match
 *   0.5  — primary team correct but cc team missed
 *   0.0  — no match
 */
function scoreRoute(string $agent, string $bench): float
{
    if ($agent === $bench) {
        return 1.0;
    }

    // Extract primary team (before " + ")
    $agentPrimary = explode(' + ', $agent)[0];
    $benchPrimary = explode(' + ', $bench)[0];

    if ($agentPrimary === $benchPrimary) {
        return 0.5; // Primary correct, cc missed
    }

    return 0.0;
}

/**
 * Calculate aggregate accuracy scores across all results.
 */
function calculateSummary(array $results): array
{
    $scored = array_filter($results, fn($r) => $r['scores'] !== null);
    $n      = count($scored);

    if ($n === 0) {
        return ['error' => 'No scored results'];
    }

    $strict   = array_sum(array_column(array_column($scored, 'scores'), 'strict_match'));
    $category = array_sum(array_column(array_column($scored, 'scores'), 'category'));
    $priority = array_sum(array_column(array_column($scored, 'scores'), 'priority'));
    $route    = array_sum(array_column(array_column($scored, 'scores'), 'route_to'));
    $flag     = array_sum(array_column(array_column($scored, 'scores'), 'needs_human_review'));

    return [
        'total'             => $n,
        'strict_accuracy'   => round($strict   / $n * 100, 1),
        'category_accuracy' => round($category / $n * 100, 1),
        'priority_accuracy' => round($priority / $n * 100, 1),
        'route_accuracy'    => round($route    / $n * 100, 1),
        'flag_accuracy'     => round($flag     / $n * 100, 1),
    ];
}

/**
 * Print a formatted summary table to stdout.
 */
function printSummary(array $summary): void
{
    echo "\n";
    echo "════════════════════════════════════════\n";
    echo "  TRIAGE AGENT — BENCHMARK RESULTS\n";
    echo "════════════════════════════════════════\n";
    echo sprintf("  Strict accuracy   : %5.1f%%  (%d/20)\n",
        $summary['strict_accuracy'],
        round($summary['strict_accuracy'] / 100 * ($summary['total'] ?? 20))
    );
    echo "────────────────────────────────────────\n";
    echo sprintf("  Category          : %5.1f%%\n", $summary['category_accuracy']);
    echo sprintf("  Priority          : %5.1f%%\n", $summary['priority_accuracy']);
    echo sprintf("  Route to          : %5.1f%%\n", $summary['route_accuracy']);
    echo sprintf("  Human review flag : %5.1f%%\n", $summary['flag_accuracy']);
    echo "════════════════════════════════════════\n";
}
```

---

## 7. Frontend — Component Details

### 7.1 File Locations

React source files live inside the Laravel project at `resources/js/`.

```
resources/js/app.jsx                    ← React entry point
resources/js/components/App.jsx
resources/js/components/MessageForm.jsx
resources/js/components/TriageResult.jsx
resources/js/components/CategoryBadge.jsx
resources/js/components/PriorityBadge.jsx
```

### 7.2 App.jsx

```jsx
import { useState } from 'react';
import MessageForm  from './MessageForm';
import TriageResult from './TriageResult';

export default function App() {
  const [loading, setLoading] = useState(false);
  const [result,  setResult]  = useState(null);
  const [error,   setError]   = useState(null);

  async function handleSubmit(formData) {
    setLoading(true);
    setResult(null);
    setError(null);

    try {
      const res = await fetch('/api/triage', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify(formData),
      });

      const data = await res.json();

      if (!res.ok) {
        throw new Error(data.error || `HTTP ${res.status}`);
      }

      setResult(data);
    } catch (e) {
      setError(e.message);
    } finally {
      setLoading(false);
    }
  }

  return (
    <div className="min-h-screen bg-gray-50 p-6">
      <div className="max-w-2xl mx-auto space-y-6">
        <h1 className="text-2xl font-bold text-gray-800">
          Northwind — Triage Agent
        </h1>
        <MessageForm onSubmit={handleSubmit} loading={loading} />
        {error  && <p className="text-red-600 text-sm">{error}</p>}
        {result && <TriageResult result={result} />}
      </div>
    </div>
  );
}
```

### 7.3 MessageForm.jsx

```jsx
import { useState } from 'react';

export default function MessageForm({ onSubmit, loading }) {
  const [form, setForm] = useState({
    body:        '',
    channel:     'email',
    sender_name: '',
    subject:     '',
  });

  function handleChange(e) {
    setForm(prev => ({ ...prev, [e.target.name]: e.target.value }));
  }

  function handleSubmit(e) {
    e.preventDefault();
    if (!form.body.trim()) return;
    onSubmit(form);
  }

  return (
    <form onSubmit={handleSubmit} className="bg-white rounded-lg shadow p-6 space-y-4">

      <div>
        <label className="block text-sm font-medium text-gray-700 mb-1">
          Channel
        </label>
        <select
          name="channel"
          value={form.channel}
          onChange={handleChange}
          className="w-full border border-gray-300 rounded px-3 py-2 text-sm"
        >
          <option value="email">Email</option>
          <option value="webform">Web Form</option>
          <option value="sms">SMS</option>
        </select>
      </div>

      <div className="grid grid-cols-2 gap-4">
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">
            Sender name <span className="text-gray-400">(optional)</span>
          </label>
          <input
            name="sender_name"
            value={form.sender_name}
            onChange={handleChange}
            placeholder="e.g. Sarah Patel"
            className="w-full border border-gray-300 rounded px-3 py-2 text-sm"
          />
        </div>
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">
            Subject <span className="text-gray-400">(optional)</span>
          </label>
          <input
            name="subject"
            value={form.subject}
            onChange={handleChange}
            placeholder="e.g. Dripping tap"
            className="w-full border border-gray-300 rounded px-3 py-2 text-sm"
          />
        </div>
      </div>

      <div>
        <label className="block text-sm font-medium text-gray-700 mb-1">
          Message body <span className="text-red-500">*</span>
        </label>
        <textarea
          name="body"
          value={form.body}
          onChange={handleChange}
          rows={6}
          required
          placeholder="Paste the customer message here..."
          className="w-full border border-gray-300 rounded px-3 py-2 text-sm resize-none"
        />
      </div>

      <button
        type="submit"
        disabled={loading || !form.body.trim()}
        className="w-full bg-blue-600 hover:bg-blue-700 disabled:bg-gray-300
                   text-white font-medium py-2 rounded text-sm transition-colors"
      >
        {loading ? 'Analysing...' : 'Triage Message'}
      </button>

    </form>
  );
}
```

### 7.4 TriageResult.jsx

```jsx
import CategoryBadge from './CategoryBadge';
import PriorityBadge from './PriorityBadge';

export default function TriageResult({ result }) {
  return (
    <div className="bg-white rounded-lg shadow p-6 space-y-4">

      {/* Human review warning */}
      {result.needs_human_review && (
        <div className="bg-red-50 border border-red-300 text-red-700 rounded px-4 py-3 text-sm font-medium">
          Requires human review before sending
        </div>
      )}

      {/* Category + Priority + Route */}
      <div className="flex flex-wrap gap-3 items-center">
        <CategoryBadge category={result.category} />
        <PriorityBadge priority={result.priority} />
        <span className="text-sm text-gray-600">
          Route to: <span className="font-medium text-gray-800">{result.route_to}</span>
        </span>
      </div>

      {/* Draft reply */}
      <div>
        <p className="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">
          Draft Reply
        </p>
        <div className="bg-gray-50 border border-gray-200 rounded px-4 py-3 text-sm text-gray-800 whitespace-pre-wrap">
          {result.draft_reply || <span className="text-gray-400 italic">No reply drafted (garbled/spam)</span>}
        </div>
      </div>

      {/* Reasoning */}
      <div>
        <p className="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">
          Reasoning
        </p>
        <p className="text-sm text-gray-600">{result.reasoning}</p>
      </div>

    </div>
  );
}
```

### 7.5 CategoryBadge.jsx

```jsx
const COLOURS = {
  EMERGENCY:   'bg-red-100    text-red-700    border-red-300',
  COMPLAINT:   'bg-orange-100 text-orange-700 border-orange-300',
  BOOKING:     'bg-blue-100   text-blue-700   border-blue-300',
  QUOTE:       'bg-purple-100 text-purple-700 border-purple-300',
  BILLING:     'bg-yellow-100 text-yellow-700 border-yellow-300',
  OUT_OF_SCOPE:'bg-gray-100   text-gray-600   border-gray-300',
};

export default function CategoryBadge({ category }) {
  const colour = COLOURS[category] ?? COLOURS.OUT_OF_SCOPE;
  return (
    <span className={`border rounded px-2 py-0.5 text-xs font-semibold ${colour}`}>
      {category}
    </span>
  );
}
```

### 7.6 PriorityBadge.jsx

```jsx
const COLOURS = {
  P1: 'bg-red-600   text-white',
  P2: 'bg-amber-500 text-white',
  P3: 'bg-green-600 text-white',
};

export default function PriorityBadge({ priority }) {
  const colour = COLOURS[priority] ?? 'bg-gray-400 text-white';
  return (
    <span className={`rounded px-2 py-0.5 text-xs font-bold ${colour}`}>
      {priority}
    </span>
  );
}
```

---

## 8. Frontend — Configuration Files

### 8.1 package.json (scripts section)

```json
{
  "scripts": {
    "dev":    "vite",
    "build":  "vite build",
    "lint":   "eslint resources/js/",
    "format": "prettier --write resources/js/"
  }
}
```

### 8.2 .prettierrc

```json
{
  "semi": true,
  "singleQuote": true,
  "tabWidth": 2,
  "trailingComma": "es5"
}
```

---

## 9. Static Analysis Configuration

### 9.1 .php-cs-fixer.php

```php
<?php

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__ . '/app');

return (new PhpCsFixer\Config())
    ->setRules([
        '@PSR12'       => true,
        'strict_param' => true,
        'array_syntax' => ['syntax' => 'short'],
    ])
    ->setFinder($finder);
```

### 9.2 phpstan.neon

```yaml
parameters:
    level: 5
    paths:
        - app
    ignoreErrors: []
```

---

## 10. Error Handling — Detail

### 10.1 Backend Error Flow

```
Request arrives at TriageController::triage()
    │
    ├── Validation fails
    │     → 400 { "error": "The body field is required." }
    │
    ├── ANTHROPIC_API_KEY not set
    │     → RuntimeException thrown in TriageAgentService::__construct()
    │     → Caught in TriageController
    │     → 500 { "error": "ANTHROPIC_API_KEY is not set." }
    │
    ├── Anthropic API returns non-2xx
    │     → RuntimeException: "Anthropic API call failed: 429 ..."
    │     → 500 { "error": "Anthropic API call failed: ..." }
    │
    ├── Model returns malformed JSON
    │     → RuntimeException: "Failed to parse agent response as JSON: ..."
    │     → 500 { "error": "Failed to parse agent response as JSON: ..." }
    │
    └── All errors logged via Log::error() with message body context
```

### 10.2 Frontend Error Flow

```
fetch() called
    │
    ├── Network error (backend not running)
    │     → catch(e) → setError(e.message)
    │     → Display: "Failed to fetch"
    │
    ├── HTTP 400
    │     → data.error → setError(data.error)
    │     → Display: "The body field is required."
    │
    ├── HTTP 500
    │     → data.error → setError(data.error)
    │     → Display: "Anthropic API call failed: ..."
    │
    └── User can correct input and retry
```

---

## 11. Sequence Diagram — Single Triage Request

```
User          Frontend         Laravel          Anthropic API
 │                │                │                  │
 │  Submit form   │                │                  │
 │──────────────>│                │                  │
 │                │ POST /api/triage                  │
 │                │──────────────>│                  │
 │                │                │ POST /v1/messages│
 │                │                │─────────────────>│
 │                │                │                  │
 │                │                │   JSON response  │
 │                │                │<─────────────────│
 │                │                │                  │
 │                │  JSON 200      │                  │
 │                │<──────────────│                  │
 │  Show result   │                │                  │
 │<──────────────│                │                  │
```


---

## 12. Routing Logic Reference

This section documents the routing values that the agent must return in `route_to`, as defined in the SOP.

| Category | route_to value | Notes |
|---|---|---|
| BOOKING | `Dispatch` | |
| EMERGENCY | `Dispatch` | On-call tradies for plumbing and electrical |
| QUOTE | `Sales` | |
| BILLING | `Accounts` | |
| COMPLAINT | `Customer Care` | |
| OUT_OF_SCOPE | `Customer Care` | Sends polite decline |
| COMPLAINT with billing dispute > $500 | `Customer Care + Accounts` | CC both teams |

After-hours HVAC fault: `BOOKING` → `Dispatch`, priority `P2`, draft reply must include "we'll be in touch first thing tomorrow".

---

## 13. Local Development Quick Reference

### Install and start (single command)
```bash
composer install
npm install
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

### Run batch scorer
```bash
php scripts/batch_run.php
```

### Run static analysis
```bash
# PHP
./vendor/bin/php-cs-fixer fix --dry-run --diff
./vendor/bin/phpstan analyse app --level=5

# JS
npm run lint
npx prettier --check resources/js/
```
