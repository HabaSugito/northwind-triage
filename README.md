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

Two accuracy figures are reported: benchmark alignment and SOP-grounded accuracy. They differ because the benchmark itself assigns P2 in three cases where the SOP's P2 conditions are not met.

### Benchmark alignment

| Field | Accuracy |
|---|---|
| Category | 100% (20/20) |
| Priority | 100% (20/20) |
| Route | 100% (20/20) |
| needs_human_review | 100% (20/20) |
| **Strict (all 4 correct)** | **100% (20/20)** |

### SOP-grounded accuracy

The SOP defines three P2 conditions exhaustively: loss of essential function, complaint involving a charge over $1,000, or an after-hours HVAC fault. In three cases (MSG-004, MSG-017, MSG-019) the benchmark assigns P2 for situations that meet none of these — a conduct complaint for $280, a billing dispute of $150/$720, and a $720 refund request. The agent matched the benchmark on all three, but the underlying priority calls are not supportable by the SOP.

| Field | Accuracy |
|---|---|
| Category | 100% (20/20) |
| Priority | 85% (17/20) |
| Route | 95% (19/20) |
| needs_human_review | 100% (20/20) |
| **Strict (all 4 correct)** | **85% (17/20)** |

The three failures share a common failure mode: in all cases the agent cites the `needs_human_review` trigger (customer dissatisfaction, refund over $500) as justification for P2 — conflating the flag threshold with the priority threshold. These are separate SOP rules. The prompt states them clearly, but the model generalises across them in borderline cases.

The three initial prompt corrections (MSG-005 flag over-triggered, MSG-007 flag not triggered, MSG-017 flag not triggered) and the benchmark analysis below focus on cases where the agent's output diverged from the benchmark.

---

## Benchmark analysis

### MSG-005 — EV charger QUOTE, flag over-triggered

**Agent:** `needs_human_review = true` &nbsp; **Benchmark:** `false`

The catalogue lists EV charger installation with a 12-month minimum service age constraint. The customer's property is in Chatswood, built 2019 — clearly satisfying the constraint. The agent incorrectly applied SOP flag condition 6 ("borderline outside catalogue") to a constraint that was demonstrably met. A known, satisfied constraint does not make a service borderline. **Prompt fix applied:** added a note that the service age constraint only triggers a flag when the age is unknown or stated to be under 12 months.

### MSG-007 — Dishwasher repair, flag not triggered

**Agent:** `needs_human_review = false` &nbsp; **Benchmark:** `true`

The classification as OUT_OF_SCOPE is correct (Northwind installs but does not repair appliances). However, customers routinely expect a trades company to repair appliances — the install/repair distinction is not obvious and warrants a human-written explanation. SOP condition 6 ("borderline outside catalogue") applies here. **Prompt fix applied:** explicitly mark appliance repair OUT_OF_SCOPE cases as `needs_human_review = true`.

### MSG-017 — Conduct complaint, flag miss fixed; priority right answer, wrong reason

**Initial agent:** P3, `needs_human_review = false` &nbsp; **Benchmark:** P2, `true` &nbsp; **Final agent:** P2, `true`

The flag miss was a prompt gap. The SOP is explicit that an angry or distressed customer triggers `needs_human_review = true` (condition 1). The customer wrote "We are not happy" — clear dissatisfaction the agent failed to detect. **Prompt fix applied:** made flag condition 1 more explicit about dissatisfaction phrases.

For priority, the final agent returns P2 and matches the benchmark — but its stated reasoning is wrong: it cites "customer expresses clear dissatisfaction" as the P2 trigger. The SOP's P2 conditions are exhaustive (essential function loss, charge over $1,000, after-hours HVAC fault) and none of them apply to a $280 conduct complaint. The agent reached the correct benchmark answer via a rule that exists only for `needs_human_review`, not for priority — conflating the flag trigger with the priority trigger. This is a model generalisation beyond the explicit prompt. **No prompt change applied for priority.** The benchmark's own notes acknowledge that P3 is also defensible. Strict SOP reading: P3, `Customer Care`.

### MSG-004 — $150 billing dispute: benchmark, agent, and SOP all diverge

**Benchmark:** P2, `Customer Care + Accounts` &nbsp; **Agent:** P2, `Customer Care + Accounts` &nbsp; **SOP-grounded:** P3, `Customer Care`

The agent matches the benchmark, but both are questionable against the SOP.

**Priority:** The SOP's P2 condition for complaints is "a charge over $1,000." The contested amount is $150, well below that threshold. The benchmark assigns P2 citing the customer's online-review threat — but the SOP classifies that threat as a `needs_human_review` trigger (condition 1), not a priority escalation trigger. These are separate rules. The agent's own reasoning contradicts itself: it states "the disputed amount ($150) is under $1,000" while still assigning P2.

**Route:** The dual-routing rule activates for "a billing dispute over $500." The contested portion is $150, not the invoice total of $720. The SOP says "dispute," not "invoice total." The agent over-routes by reading the $720 invoice as the dispute amount. No prompt fix applied — the routing rule in the prompt is correct; the agent misapplies it. Strict SOP reading: P3, `Customer Care`.

### MSG-019 — $720 refund: P2 assigned without SOP basis

**Benchmark:** P2, `Accounts` &nbsp; **Agent:** P2, `Accounts` &nbsp; **SOP-grounded:** P3, `Accounts`

The agent matches the benchmark, but both assign P2 for a BILLING refund request of $720. The SOP's $500 threshold is a `needs_human_review` trigger (condition 2), not a P2 condition. The SOP's P2 conditions — loss of essential function, complaint involving a charge over $1,000, after-hours HVAC fault — none of which apply to a refund request. The agent's reasoning cites "refund amount exceeds the $500 threshold" as the P2 basis, conflating the flag trigger with the priority trigger. The flag is correctly set to true; the priority should be P3 by the SOP. The benchmark's own notes acknowledge that P3 is also defensible. Strict SOP reading: P3, `Accounts`.

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
