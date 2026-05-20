# Basic Design Document

**Project:** Northwind Home Services — Automated Customer Enquiry Triage System
**Date:** May 2025
**Stack:** Laravel 11 (PHP 8.2) + React 18 + Vite (Laravel-integrated) + Anthropic Claude API

---

## 1. System Overview

### 1.1 Data Flow

```
Customer Message
      │
      ▼
[React Frontend]
  - User pastes message
  - Selects channel (email/webform/sms)
  - Optionally enters sender name, subject
      │
      │ POST /api/triage
      ▼
[Laravel Backend]
  TriageController
  - Validates request
  - Passes to TriageAgentService
      │
      ▼
  TriageAgentService
  - Builds user message context
  - Calls Anthropic Claude API
      │
      │ System Prompt + User Message
      ▼
[Anthropic Claude API]
  - Applies SOP rules
  - Classifies category, priority, route
  - Drafts reply
  - Returns JSON
      │
      ▼
  TriageAgentService
  - Parses JSON response
  - Returns TriageResult
      │
      ▼
  TriageController
  - Returns JSON response
      │
      │ JSON response
      ▼
[React Frontend]
  - Displays triage result
  - Shows human review flag
```

### 1.2 Batch Run Flow

```
data/messages.json (20 messages)
      │
      ▼
[batch_run.php]
  - Loops through all 20 messages
  - Calls TriageAgentService for each
  - Collects agent outputs
      │
      ▼
[Scorer]
  - Loads data/benchmark.json
  - Compares agent output vs benchmark
  - Calculates strict accuracy + per-field scores
      │
      ▼
batch_results.json
  - Per-message comparison
  - Aggregate scores
```

---

## 2. Backend Design

### 2.1 Class Diagram

```
TriageController
├── __construct(TriageAgentService $agent)
├── triage(Request $request): JsonResponse
│     - Validates: body (required), channel, sender_name, subject, received_at
│     - Calls: $this->agent->triage($message)
│     - Returns: JSON with 6 fields
└── health(): JsonResponse

TriageAgentService
├── __construct()
│     - Initialises Anthropic HTTP client
│     - Loads SYSTEM_PROMPT constant
├── triage(array $message): TriageResult
│     - Builds user message string from $message fields
│     - Calls Anthropic API
│     - Strips markdown fences if present
│     - Parses JSON
│     - Returns TriageResult
└── buildUserMessage(array $message): string
      - Formats channel, sender, subject, body, received_at
      - Adds channel context hint
```

### 2.2 TriageResult Data Structure

```php
class TriageResult
{
    public string $category;        // BOOKING|QUOTE|COMPLAINT|EMERGENCY|BILLING|OUT_OF_SCOPE
    public string $priority;        // P1|P2|P3
    public string $route_to;        // Dispatch|Sales|Accounts|Customer Care|Customer Care + Accounts
    public bool   $needs_human_review;
    public string $draft_reply;     // Empty string for spam/garbled
    public string $reasoning;
}
```

### 2.3 API Specification

#### POST /api/triage

**Request body:**
```json
{
  "body":        "string (required)",
  "channel":     "email | webform | sms (optional, default: email)",
  "sender_name": "string (optional)",
  "subject":     "string | null (optional)",
  "received_at": "ISO8601 string (optional)"
}
```

**Response 200:**
```json
{
  "category":           "EMERGENCY",
  "priority":           "P1",
  "route_to":           "Dispatch",
  "needs_human_review": false,
  "draft_reply":        "Hi Kevin — no hot water with kids in the house is a priority. Someone from dispatch will call you within the hour. — The Northwind team",
  "reasoning":          "SOP explicitly lists 'no hot water in winter' as P1 EMERGENCY. Family with two children. Hornsby is within 40km of CBD."
}
```

**Response 400 (validation error):**
```json
{
  "error": "The body field is required."
}
```

**Response 500 (API error):**
```json
{
  "error": "Anthropic API call failed: ..."
}
```

#### GET /api/health

**Response 200:**
```json
{
  "status": "ok"
}
```

### 2.4 Routing (routes/api.php)

```php
Route::post('/triage', [TriageController::class, 'triage']);
Route::get('/health',  [TriageController::class, 'health']);
```

### 2.5 Environment Variables

```
ANTHROPIC_API_KEY=sk-ant-...
ANTHROPIC_MODEL=claude-sonnet-4-20250514
ANTHROPIC_MAX_TOKENS=1024
```

---

## 3. System Prompt Design

### 3.1 Structure

The system prompt is the core of the agent. It encodes all SOP rules, catalogue constraints, tone rules, and output format in a single static string held in `TriageAgentService`.

```
[ROLE]
  Who the agent is and what it does

[CATEGORIES]
  Definitions of all 6 categories
  Classification notes (edge cases)

[PRIORITY RULES]
  P1 / P2 / P3 definitions
  Never-downgrade rule for EMERGENCY

[ROUTING RULES]
  4 teams and their responsibilities
  Special routing rules ($500 cc, HVAC after-hours)

[HUMAN REVIEW FLAG CONDITIONS]
  All 7 conditions
  "When in doubt, flag" policy

[OUT-OF-HOURS RULES]
  Business hours definition
  Queue behaviour for P2/P3
  HVAC on-call exception

[SERVICE CATALOGUE]
  Fixed prices (quotable)
  "From" prices (not quotable)
  Services not offered + referrals
  Service area (40km rule)

[DRAFT REPLY RULES]
  Length (2-4 sentences)
  Opening / sign-off format
  3 principles (plain, specific, honest)
  Hard rules (no price unless fixed, no tradesperson name, no exact time)
  Banned words list
  Situation-specific notes (EMERGENCY, COMPLAINT, OUT_OF_SCOPE, after-hours, non-English)

[OUTPUT FORMAT]
  Strict JSON only — no preamble, no markdown fences
  Field names and types
```

### 3.2 Output Format Instruction

The final section of the system prompt enforces structured output:

```
Respond ONLY with a JSON object. No preamble, no explanation, no markdown fences.
Exactly this structure:

{
  "category": "BOOKING|QUOTE|COMPLAINT|EMERGENCY|BILLING|OUT_OF_SCOPE",
  "priority": "P1|P2|P3",
  "route_to": "Dispatch|Sales|Accounts|Customer Care|Customer Care + Accounts",
  "needs_human_review": true|false,
  "draft_reply": "Reply text, or empty string if spam/garbled",
  "reasoning": "1-3 sentences explaining the key decisions"
}
```

### 3.3 User Message Format

Each inbound message is formatted as follows before being sent to the API:

```
Channel: email
Received: 2024-06-12T09:14:00+10:00
From: Sarah Patel
Subject: Dripping tap in ensuite
Body: Hi there, the cold tap in our upstairs ensuite has been dripping...

[Channel context: This message came in via email (support@northwind.com.au).]
```

### 3.4 JSON Parse Safety

The model may occasionally wrap output in markdown fences despite instructions. The service strips these before parsing:

```php
$raw = $response->content[0]->text;
if (str_starts_with($raw, '```')) {
    $raw = preg_replace('/^```json?\n?/', '', $raw);
    $raw = preg_replace('/\n?```$/', '', $raw);
}
$data = json_decode(trim($raw), true);
```

---

## 4. Frontend Design

### 4.1 Component Structure

```
App
├── MessageForm
│   ├── textarea        (message body)
│   ├── select          (channel: email / webform / sms)
│   ├── input           (sender_name, optional)
│   ├── input           (subject, optional)
│   └── button          (Submit)
│
└── TriageResult
    ├── CategoryBadge   (colour-coded by category)
    ├── PriorityBadge   (P1=red, P2=amber, P3=green)
    ├── RouteTag        (team name)
    ├── HumanReviewFlag (warning banner if true)
    ├── DraftReply      (copyable text block)
    └── Reasoning       (expandable section)
```

### 4.2 State Management

```javascript
// App.jsx
const [loading, setLoading]   = useState(false);
const [result,  setResult]    = useState(null);
const [error,   setError]     = useState(null);

async function handleSubmit(formData) {
  setLoading(true);
  setError(null);
  try {
    const res = await fetch('/api/triage', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(formData),
    });
    if (!res.ok) throw new Error(await res.text());
    setResult(await res.json());
  } catch (e) {
    setError(e.message);
  } finally {
    setLoading(false);
  }
}
```

### 4.3 Category and Priority Colour Mapping

| Value | Colour |
|---|---|
| EMERGENCY | Red |
| COMPLAINT | Orange |
| BOOKING | Blue |
| QUOTE | Purple |
| BILLING | Yellow |
| OUT_OF_SCOPE | Grey |
| P1 | Red |
| P2 | Amber |
| P3 | Green |

---

## 5. Batch Runner Design

### 5.1 Script: scripts/batch_run.php

```
Load data/messages.json
Load data/benchmark.json

For each message:
  1. Call TriageAgentService::triage($message)
  2. Compare output against benchmark on 4 quantitative fields:
       - category:           exact match = 1, no match = 0
       - priority:           exact match = 1, no match = 0
       - route_to:           exact match = 1, partial (primary team correct, cc missed) = 0.5, no match = 0
       - needs_human_review: exact match = 1, no match = 0
  3. Record strict_match = true if all 4 fields score 1
  4. Store per-message result

Aggregate:
  - strict_accuracy     = strict_match count / 20 * 100
  - category_accuracy   = sum of category scores / 20 * 100
  - priority_accuracy   = sum of priority scores / 20 * 100
  - route_accuracy      = sum of route_to scores / 20 * 100
  - flag_accuracy       = sum of needs_human_review scores / 20 * 100

Output:
  - Print summary table to stdout
  - Write full results to batch_results.json
```

### 5.2 Output Format (batch_results.json)

```json
{
  "summary": {
    "strict_accuracy":   85.0,
    "category_accuracy": 95.0,
    "priority_accuracy": 90.0,
    "route_accuracy":    92.5,
    "flag_accuracy":     85.0
  },
  "results": [
    {
      "id": "MSG-001",
      "agent": {
        "category": "BOOKING",
        "priority": "P3",
        "route_to": "Dispatch",
        "needs_human_review": false,
        "draft_reply": "...",
        "reasoning": "..."
      },
      "benchmark": {
        "category": "BOOKING",
        "priority": "P3",
        "route_to": "Dispatch",
        "needs_human_review": false
      },
      "scores": {
        "category": 1,
        "priority": 1,
        "route_to": 1,
        "needs_human_review": 1,
        "strict_match": true
      }
    }
  ]
}
```

---

## 6. Error Handling

| Scenario | Behaviour |
|---|---|
| Empty message body | 400 response with validation error |
| ANTHROPIC_API_KEY not set | 500 response with clear error message |
| Anthropic API timeout or error | 500 response, log error |
| Model returns malformed JSON | Strip markdown fences and retry parse once; 500 if still fails |
| Frontend fetch fails | Display error message in UI, allow retry |

---

## 7. Local Development Setup

### 7.1 Prerequisites

- PHP 8.2+
- Composer
- Node.js 18+
- npm
- Anthropic API key

### 7.2 Install and Start

React lives inside Laravel at `resources/js/`. A single command starts the full application.

```bash
composer install
npm install
cp .env.example .env
# Add ANTHROPIC_API_KEY to .env
php artisan key:generate
php artisan serve --port=8000
# Visit http://localhost:8000
```

For hot module replacement during development (optional, second terminal):

```bash
npm run dev
```

### 7.3 Batch Runner

```bash
php scripts/batch_run.php
# Outputs results to ../data/batch_results.json
```

### 7.4 Static Analysis and Formatting

#### Backend (PHP)

| Tool | Purpose | Config file |
|---|---|---|
| PHP CS Fixer | Code formatting (PSR-12) | `.php-cs-fixer.php` |
| PHPStan | Static analysis (level 5) | `phpstan.neon` |

```bash

# Install tools
composer require --dev friendsofphp/php-cs-fixer phpstan/phpstan

# Format check (dry run)
./vendor/bin/php-cs-fixer fix --dry-run --diff

# Apply formatting
./vendor/bin/php-cs-fixer fix

# Static analysis
./vendor/bin/phpstan analyse app --level=5
```

**.php-cs-fixer.php:**
```php
<?php
$finder = PhpCsFixer\Finder::create()->in(__DIR__.'/app');
return (new PhpCsFixer\Config())
    ->setRules([
        '@PSR12' => true,
        'strict_param' => true,
    ])
    ->setFinder($finder);
```

**phpstan.neon:**
```yaml
parameters:
    level: 5
    paths:
        - app
```

#### Frontend (React)

React source files live at `resources/js/`. All JS tooling runs from the project root.

| Tool | Purpose | Config file |
|---|---|---|
| ESLint | Static analysis | `eslint.config.js` (Vite default) |
| Prettier | Code formatting | `.prettierrc` |

```bash

# Install Prettier (ESLint is included with Vite)
npm install --save-dev prettier eslint-config-prettier

# Lint check
npm run lint

# Format check (dry run)
npx prettier --check resources/js/

# Apply formatting
npx prettier --write resources/js/
```

**.prettierrc:**
```json
{
  "semi": true,
  "singleQuote": true,
  "tabWidth": 2,
  "trailingComma": "es5"
}
```

**package.json scripts:**
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

---

## 8. Design Decisions and Rationale

### 8.1 Single Agent, Single Prompt

The task fits cleanly in a single well-prompted LLM call. A multi-agent architecture (e.g. separate classifier, router, and drafter) would add latency and failure points with no accuracy benefit at this scale. All 20 messages are handled by the same prompt with no per-message adjustments.

### 8.2 Laravel over Node.js

Laravel is explicitly named in the job description and listed as a bonus skill. It also provides clean separation of concerns (Controller / Service), built-in validation, and a familiar MVC structure that makes the codebase easy to clone and run.

### 8.3 Laravel-integrated Vite over Separate Frontend Directory

The candidate brief requires the system to start with a single command. By using Laravel's built-in Vite integration (`laravel-vite-plugin`), React is served from the same origin as the API (`localhost:8000`), eliminating the need for CORS configuration and reducing startup to a single `php artisan serve` command. A separate `frontend/` directory would require two terminal sessions and CORS setup.

### 8.4 Structured JSON Output

Instructing the model to return only JSON (with a strict schema) avoids the need for any natural language parsing. Markdown fence stripping is included as a safety measure since some models add fences despite instructions.

### 8.5 System Prompt as a Constant

The system prompt is held as a constant in `TriageAgentService` rather than loaded from a file or database. This keeps the agent self-contained, easy to version-control, and simple to run locally without additional configuration.

### 8.6 What I Would Build Next

The highest-value next step would be an **evaluation harness** — a structured way to run the agent against the benchmark, log results, and compare prompt versions side-by-side. Currently, iterating on the system prompt requires manually re-running the batch script and eyeballing diffs. A lightweight eval framework (even a simple PHP script that outputs a markdown diff table) would make prompt iteration measurably faster and reduce the risk of regressions when refining edge cases.

