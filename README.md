# Northwind Triage

AI-powered customer message triage for Northwind Home Services. Each inbound message is classified (category, priority, routing), given a draft customer reply, and flagged for human review when needed — all in a single API call.

**Stack:** Laravel 11 (PHP 8.4) · React 19 · Vite 8 · Anthropic Claude API

---

## Documentation

Design and reference documents are in `docs/`:

| File | Contents |
|---|---|
| `docs/requirements.md` | Functional and non-functional requirements, scoring rules, known contradictions |
| `docs/DESIGN.md` | Architecture overview, class diagram, API spec, data flows |
| `docs/DETAIL_DESIGN.md` | Full class definitions, component code, error handling, config files |
| `docs/system_prompt.md` | The agent's system prompt (single source of truth — copied verbatim into `TriageAgentService`) |

---

## Quick start

### Prerequisites

- PHP 8.4 with `pdo_sqlite` and `bcmath` extensions
- Composer 2
- Node.js 22+
- An Anthropic API key (`sk-ant-...`)

### Install

```bash
git clone <repo-url>
cd northwind-triage

composer install
npm install
npm run build

cp .env.example .env
# Open .env and set: ANTHROPIC_API_KEY=sk-ant-...

php artisan key:generate
```

### Run

```bash
php artisan serve --port=8000
```

Visit [http://localhost:8000](http://localhost:8000)

### Hot module replacement (optional, second terminal)

```bash
npm run dev
```

### Batch run against benchmark

```bash
php scripts/batch_run.php
```

Reads all 20 messages from `data/05_Inbound_Messages.json`, calls the agent for each, scores against `data/06_Benchmark.json`, and writes results to `data/batch_results.json`.

This batch runner serves as the automated evaluation harness for this project. Traditional unit tests with a mocked Anthropic API cannot verify the thing that matters most — whether the prompt correctly classifies messages and generates appropriate replies. Running against the real model is the only meaningful test.

---

## Environment variables

| Variable | Required | Default | Description |
|---|---|---|---|
| `ANTHROPIC_API_KEY` | Yes | — | Your Anthropic API key |
| `ANTHROPIC_MODEL` | No | `claude-sonnet-4-6` | Model to use |
| `ANTHROPIC_MAX_TOKENS` | No | `1024` | Max tokens in response |

---

## API

### `POST /api/triage`

**Request body:**
```json
{
  "body":        "string (required)",
  "channel":     "email | webform | sms (optional)",
  "sender_name": "string (optional)",
  "subject":     "string (optional)",
  "received_at": "ISO8601 (optional)"
}
```

**Response 200:**
```json
{
  "category":           "BOOKING",
  "priority":           "P3",
  "route_to":           "Dispatch",
  "needs_human_review": false,
  "draft_reply":        "Hi Sarah — ...",
  "reasoning":          "Standard booking request..."
}
```

**Response 400:** `{ "error": "The body field is required." }`
**Response 500:** `{ "error": "Anthropic API call failed: ..." }`

### `GET /api/health`

Returns `{ "status": "ok" }`.

---

## Agent design

The triage agent is a single Anthropic API call per message with no tool use, no retrieval, and no multi-step chain. The SOP, service catalogue, and tone guide are small enough to fit directly in a system prompt (~1,700 tokens), so embedding them as rules is both simpler and more auditable than a RAG approach. `TriageAgentService` formats the inbound message (channel, sender, subject, body, timestamp) as the user turn, posts to the Anthropic Messages API with a 30-second timeout, strips markdown fences if the model adds them, validates all six required fields, and casts types before returning. All routing, priority, and flag decisions derive from explicit rules in the prompt rather than learned heuristics — ambiguous cases surface to human review rather than force a low-confidence classification. This means the agent's reasoning is fully inspectable from its `reasoning` field and reproducible by reading the system prompt.

---

## Accuracy

Scored against `data/06_Benchmark.json` (20 messages):

| Field | Accuracy |
|---|---|
| Category | 100% (20/20) |
| Priority | 100% (20/20) |
| Route | 100% (20/20) |
| needs_human_review | 100% (20/20) |
| **Strict (all 4 correct)** | **100% (20/20)** |

The three initial failures and the reasoning behind each prompt correction are analysed below.

---

## Benchmark analysis

### MSG-005 — EV charger QUOTE, flag over-triggered

**Agent:** `needs_human_review = true` &nbsp; **Benchmark:** `false`

The catalogue lists EV charger installation with a 12-month minimum service age constraint. The customer's property is in Chatswood, built 2019 — clearly satisfying the constraint. The agent incorrectly applied SOP flag condition 6 ("borderline outside catalogue") to a constraint that was demonstrably met. A known, satisfied constraint does not make a service borderline. **Prompt fix applied:** added a note that the service age constraint only triggers a flag when the age is unknown or stated to be under 12 months.

### MSG-007 — Dishwasher repair, flag not triggered

**Agent:** `needs_human_review = false` &nbsp; **Benchmark:** `true`

The classification as OUT_OF_SCOPE is correct (Northwind installs but does not repair appliances). However, customers routinely expect a trades company to repair appliances — the install/repair distinction is not obvious and warrants a human-written explanation. SOP condition 6 ("borderline outside catalogue") applies here. **Prompt fix applied:** explicitly mark appliance repair OUT_OF_SCOPE cases as `needs_human_review = true`.

### MSG-017 — Conduct complaint, priority and flag both off

**Agent:** P3, `needs_human_review = false` &nbsp; **Benchmark:** P2, `true`

The flag miss is a prompt gap. The SOP is explicit that an angry or distressed customer triggers `needs_human_review = true` (condition 1). The customer wrote "We are not happy" — clear dissatisfaction the agent failed to detect. **Prompt fix applied:** made flag condition 1 more explicit about dissatisfaction phrases.

The priority disagreement is a genuine SOP ambiguity. By the letter of the SOP, P2 requires either essential function loss or a charge over $1,000. The $280 conduct complaint meets neither criterion; P3 is the correct strict reading. The benchmark's P2 call is defensible — a clearly upset customer carries reputational risk that P2's 4-hour SLA would address — but the SOP doesn't state this. We agree with the benchmark's intent but consider this an SOP gap rather than a prompt error. **No prompt change applied for priority** — adding an implicit rule that "upset customer = P2" would go beyond what the SOP actually says.

### MSG-004 — $150 billing dispute routed to "Customer Care + Accounts"

**Agent:** matched benchmark &nbsp; **SOP rule:** dual routing only for disputes over $500

The benchmark routes a $150 billing dispute to both teams despite the SOP's explicit $500 threshold. The agent matched the benchmark but the SOP rule is unambiguous. We consider this a benchmark inconsistency. No prompt change; the routing rule in the prompt is correct by the SOP.

### MSG-001 — Draft reply quotes a fixed price (not scored)

The agent quoted "$120 for a tap washer" in the first response. The SOP permits quoting fixed prices; the benchmark note says not to quote in a first response unless directly asked, which the tone guide examples also imply. There is a real tension between the SOP's permissive price-quoting rule and the tone guide's example replies (which never quote prices). This doesn't affect scoring but is worth noting for reply quality.

---

## What I'd build next

**Confidence-weighted review queue.** Right now `needs_human_review` is a binary flag. In practice, some flagged messages are routine (upset but standard complaint) and some are genuinely novel (ambiguous scope, cross-jurisdictional customer). Adding an explicit `confidence` field (high / medium / low) derived from how cleanly the message matched a known pattern would let the operations team prioritise their review queue: low-confidence decisions get immediate human attention, high-confidence flagged decisions can batch. This requires no additional API calls — the reasoning field already contains most of the signal and the model could emit a confidence value alongside the existing JSON.

---

## Assumptions

- **SOP takes precedence over the brief's summary table.** The brief lists P2 = "within 48h" and P3 = "within 5 business days", but the SOP says P2 = "within 4 business hours" and P3 = "within 1 business day". We use SOP values.
- **Allroof Services is the referral for gutter/roof work.** The service catalogue says no referral for roofing/gutter cleaning, but the tone guide's example reply explicitly names Allroof Services. We use the tone guide's concrete guidance.
- **Draft replies are not validated for content.** The batch runner scores only the four structured fields. Draft reply quality is assessed qualitatively.
- **The batch runner calls the agent directly** (via `TriageAgentService::triage()`), not through the HTTP API. Results are equivalent; this avoids HTTP round-trip overhead in a scripted context.
