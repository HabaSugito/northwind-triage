# Requirements Document

**Project:** Northwind Home Services — Automated Customer Enquiry Triage System
**Date:** May 2025
**Stack:** Laravel (PHP) + React

---

## 1. Project Overview

### 1.1 Background

Northwind Home Services receives customer enquiries via email, web form, and SMS. These are currently triaged manually by operations staff against an internal SOP. As volume increases, an automated first-pass triage tool is required.

### 1.2 Objective

Build an AI-powered triage system that automatically classifies inbound messages, assigns priority, routes to the correct team, and drafts a first response — reducing manual workload for the dispatch team.

### 1.3 Scope

- AI triage agent (Anthropic Claude API)
- Backend API (Laravel / PHP)
- Frontend UI (React)
- Batch runner and self-scoring script

---

## 2. System Architecture

### 2.1 Overview

| Layer | Stack | Role |
|---|---|---|
| Frontend | React 18 + Vite | Message input and result display UI |
| Backend | Laravel 11 (PHP 8.2) | API endpoint and business logic |
| AI Agent | Anthropic Claude API (claude-sonnet-4) | Message classification and draft reply generation |
| Batch Processing | PHP script | Batch run across 20 messages and benchmark scoring |

### 2.2 Directory Structure

```
northwind-triage/
├── backend/                          # Laravel
│   ├── app/
│   │   ├── Http/Controllers/
│   │   │   └── TriageController.php
│   │   └── Services/
│   │       └── TriageAgentService.php
│   ├── routes/
│   │   └── api.php
│   └── .env
├── frontend/                         # React
│   ├── src/
│   │   ├── App.jsx
│   │   └── components/
│   └── package.json
├── data/
│   ├── messages.json                 # 20 inbound messages
│   └── benchmark.json                # Gold-standard triage decisions
├── scripts/
│   └── batch_run.php                 # Batch runner and scorer
└── README.md
```

---

## 3. Functional Requirements

### 3.1 Agent Output Specification

For each inbound message, the agent must output the following six fields:

| Field | Type | Description |
|---|---|---|
| `category` | string | One of: BOOKING / QUOTE / COMPLAINT / EMERGENCY / BILLING / OUT_OF_SCOPE |
| `priority` | string | P1 (same-day) / P2 (within 4 business hours) / P3 (within 1 business day) |
| `route_to` | string | Dispatch / Sales / Accounts / Customer Care |
| `draft_reply` | string | Short customer-facing reply (2–4 sentences), matching the Tone & Style Guide |
| `needs_human_review` | boolean | Flag for anything ambiguous or outside policy |
| `reasoning` | string | 1–3 sentences explaining the classification decisions |

---

## 4. Triage Rules (SOP)

### 4.1 Category Classification

| Category | When to use |
|---|---|
| `BOOKING` | Customer wants to schedule a service already agreed to, or reschedule an existing booking |
| `QUOTE` | Customer is asking for a price or estimate for work not yet agreed |
| `COMPLAINT` | Customer is unhappy with completed work, a tradesperson's conduct, or billing accuracy |
| `EMERGENCY` | Active risk to property or safety: water leak in progress, no hot water in winter, electrical sparking, burning smell, gas smell |
| `BILLING` | Customer is asking about an invoice, payment, refund, or account statement |
| `OUT_OF_SCOPE` | Request is for a service not offered, or is not actionable (spam, garbled, wrong number) |

**Classification notes (important):**

- If a message contains both a complaint and a new request → classify as `COMPLAINT`, note the secondary request in `reasoning`
- A reschedule request → `BOOKING`, not `COMPLAINT`, unless the customer explicitly expresses dissatisfaction
- If unsure between `QUOTE` and `BOOKING` → default to `QUOTE`
- "No hot water in winter" → explicitly `EMERGENCY` P1 (named in SOP)
- "Heater not working" → not explicitly listed in SOP → treat as `BOOKING` P2 (no HVAC on-call)
- Appliance repair (dishwasher, washing machine, oven) → `OUT_OF_SCOPE` (Northwind installs but does not repair)
- Spam or garbled messages → `OUT_OF_SCOPE` + `needs_human_review = true`

### 4.2 Priority Levels

| Priority | Definition | First Response SLA |
|---|---|---|
| `P1` | Active safety or property risk. EMERGENCY is always P1 — never downgrade | Within 1 hour, 24/7 |
| `P2` | Loss of essential function (heating, hot water, toilet) with no immediate damage / complaint involving charge over $1,000 / after-hours HVAC fault | Within 4 business hours |
| `P3` | Standard enquiry, quote request, or non-urgent booking | Within 1 business day |

**Priority notes:**

- EMERGENCY messages are always P1, even if the customer's tone is calm — do not downgrade
- Customer threatening an online review or legal action → consider escalating to P2
- After-hours HVAC fault → P2, next-business-day allocation via Dispatch (no HVAC on-call)

> **Note: The candidate brief lists P2 as "within 48h" and P3 as "within 5 business days" — these conflict with the SOP. The SOP takes precedence.**

### 4.3 Routing Rules

| Team | Handles | Special Rules |
|---|---|---|
| Dispatch | All BOOKING and EMERGENCY | On-call tradies for emergency plumbing and electrical. After-hours HVAC → next-business-day |
| Sales | All QUOTE | All estimates and pricing enquiries |
| Accounts | All BILLING | Invoices, payments, refunds |
| Customer Care | All COMPLAINT and OUT_OF_SCOPE | COMPLAINT with billing dispute over $500 → cc both Customer Care and Accounts |

**Routing notes:**

- `COMPLAINT` involving a billing dispute over $500 → route to `Customer Care + Accounts`
- MSG-004 edge case: billing dispute of $150 (under $500) but benchmark routes to both teams — this is a known ambiguity where the benchmark conflicts with the SOP $500 rule
- `OUT_OF_SCOPE` → Customer Care sends a polite decline

### 4.4 Human Review Flag Conditions

Set `needs_human_review = true` if any of the following apply:

- Customer is angry, distressed, or threatens legal action / online review
- Request involves a quote over $5,000, or a refund over $500
- Message is in a language other than English, or appears garbled / spam
- Cannot confidently classify the message
- Customer mentions a previous complaint or escalation
- Request is for a service that is borderline outside the catalogue
- Customer may be outside the service area (more than 40km from Sydney CBD)

> **Policy: Flagging is cheap. Missing an escalation is expensive. When in doubt, flag.**

### 4.5 Out-of-Hours Handling

- Business hours: weekdays 7:00–18:00 (Sydney time)
- Outside business hours: only P1 EMERGENCY messages are actioned live
- P2 and P3 messages are queued for the next business morning
- Draft replies for queued messages must acknowledge the wait ("we'll be in touch first thing tomorrow") — do not promise same-day action
- **No HVAC on-call** → after-hours HVAC faults are P2, allocated by Dispatch next business day

---

## 5. Service Catalogue Constraints

### 5.1 Fixed Prices (may be quoted in draft replies)

| Service | Price | Notes |
|---|---|---|
| Tap washer replacement | $120 fixed | **Single tap only.** Multiple taps quoted on site |
| Blocked drain clearing | $280 fixed | **Standard drain only.** Sewer mains quoted separately |
| Power point installation | $190 fixed | Per outlet, standard locations. Surcharge for upper floors |
| Light fitting installation | $150 fixed | **Customer supplies fitting.** Northwind supplies standard wiring only |
| Safety switch installation | $320 fixed | Per circuit |
| Split-system service / clean | $220 fixed | Per unit. Annual maintenance recommended |
| Gas heater service | $280 fixed | Includes safety / carbon monoxide check |
| Evaporative cooler service | $240 fixed | Pre-summer service recommended |

### 5.2 "From" Prices (must NOT be quoted in draft replies)

The following require site assessment — do not quote specific figures in first responses.

| Service | Price | Notes |
|---|---|---|
| Hot water system repair | from $180/hr | Gas, electric, and heat-pump systems |
| Hot water system replacement | from $1,800 | Includes removal of old unit. Site assessment required |
| Burst pipe repair | from $220/hr | Always P1 if water is actively flowing |
| Toilet repair / replacement | from $150 | Replacement units from $450 |
| Bathroom renovation plumbing | from $4,500 | **Site visit + written quote always required.** Cannot quote by phone or email |
| Switchboard upgrade | from $2,200 | Site assessment required. **Council approval may apply** |
| Electrical fault diagnosis | from $180/hr | Sparking, tripping, burning smell → P1 |
| EV charger installation | from $1,400 | **Single-phase only. Three-phase quoted on site.** Min. service age 12 months |
| Split-system installation | from $1,600 | Includes standard install up to 5m pipe run |
| Ducted system service | from $380 | Filter replacement included. Major repairs quoted separately |
| Ducted system installation | from $9,500 | Site assessment required. Lead time 2–4 weeks |

### 5.3 Services Not Offered (OUT_OF_SCOPE)

| Service | Referral |
|---|---|
| Roofing / gutter cleaning | — |
| Solar panel installation or repair | SunPath Energy |
| Pool plumbing or pool equipment | AquaCorp Pools |
| Appliance repair (dishwasher, washing machine, oven) | — (installation only, no repair) |
| Commercial premises larger than 200m² | — (residential only) |
| Locksmithing, glazing, pest control | — |

### 5.4 Service Area

- **Coverage:** Residential addresses within 40km of the Sydney CBD
- **Outside area:** Set `needs_human_review = true` — Sales will assess case-by-case
- **Strata properties:** Body corporate approval is the customer's responsibility — mention this in the reply

### 5.5 Payment and Billing Rules

- Payment due within 14 days of invoice
- Jobs over $2,000 require a 30% deposit before work commences
- Accepted payment methods: card, bank transfer, PayID (no cheques since January 2024)
- Refunds for completed work are handled case-by-case through Customer Care

---

## 6. Draft Reply Rules (Tone & Style Guide)

### 6.1 Core Rules

- **Length:** 2–4 sentences. Anything longer reads like a corporate form letter
- **Opening:** Use the customer's first name if known (e.g. `Hi Sarah —`). If no name, go straight in — no greeting
- **Sign-off:** `— The Northwind team` (first responses only)
- **Tone:** Sound like a competent neighbour, not a call centre

### 6.2 Three Principles

- **Plain, not formal** — say "we'll send someone out", not "we will dispatch a service representative"
- **Specific, not generic** — reference what the customer actually said. Never open with "Thank you for contacting Northwind"
- **Honest, not performative** — if we can't help, say so plainly. Don't apologise four times

### 6.3 Hard Rules

- Never quote a price unless the catalogue lists it as fixed for that exact service
- Never name a specific tradesperson in a first response
- Never promise an exact time — give a window or SLA
- Never use exclamation marks
- Never use emoji
- Never state next steps and a time window — always include both
- For spam or garbled messages — do not draft a reply (return empty string)

### 6.4 Banned Words and Phrases

| Never say | Say instead |
|---|---|
| At your earliest convenience | Today / tomorrow / by Friday |
| We will endeavour to | We'll |
| Apologies for any inconvenience caused | Sorry about that — here's what we'll do |
| Please rest assured | (delete it) |
| Service representative | Tradie / plumber / electrician / someone from dispatch |
| Kindly | Please (or nothing) |
| Reach out | Get in touch / call / email |
| Thank you for contacting Northwind | (skip it — acknowledge the actual issue) |
| Dear | (never use) |
| Kind regards | (never use) |
| Yours sincerely | (never use) |

### 6.5 Situation-Specific Notes

- **EMERGENCY:** Include practical interim advice (e.g. "shut off the mains at the meter")
- **COMPLAINT:** Acknowledge the issue directly in the first sentence. Don't bury it under pleasantries. A complaint reply should never read like a booking reply with "sorry" added
- **OUT_OF_SCOPE:** Be brief and plain. Suggest an alternative if one is known
- **After-hours:** Do not promise same-day action. State "we'll be in touch first thing tomorrow"
- **Angry customer:** Acknowledge directly in the first sentence. Set `needs_human_review = true`
- **Non-English message:** Translate the content to classify it, then set `needs_human_review = true`. Reply in English — do not promise multilingual support

---

## 7. Backend Requirements

### 7.1 Endpoints

| Method | Path | Description |
|---|---|---|
| POST | `/api/triage` | Accepts a raw customer message, returns a structured triage decision as JSON |
| GET | `/api/health` | Health check |

### 7.2 Request Specification (POST /api/triage)

```json
{
  "body": "Customer message body (required)",
  "channel": "email | webform | sms",
  "sender_name": "Sender name (optional)",
  "subject": "Subject line (optional)",
  "received_at": "2024-06-12T09:14:00+10:00 (optional)"
}
```

### 7.3 Response Specification

```json
{
  "category": "EMERGENCY",
  "priority": "P1",
  "route_to": "Dispatch",
  "needs_human_review": false,
  "draft_reply": "Hi Kevin — no hot water with kids in the house is a priority. Someone from dispatch will call you within the hour. In the meantime, check whether the pilot light is still on. — The Northwind team",
  "reasoning": "SOP explicitly lists 'no hot water in winter' as P1 EMERGENCY. Family with two children. Hornsby is within 40km of CBD."
}
```

### 7.4 Key Classes

| Class | File | Responsibility |
|---|---|---|
| `TriageController` | `app/Http/Controllers/TriageController.php` | Accept request, validate input, return response |
| `TriageAgentService` | `app/Services/TriageAgentService.php` | Hold system prompt, call Anthropic API, parse JSON output |

---

## 8. Frontend Requirements

### 8.1 UI Components

- Message input area (textarea)
- Channel selector (email / webform / sms)
- Optional sender name and subject fields
- Submit button
- Result display (all six fields)
- Visual flag for `needs_human_review`

### 8.2 Technical Specification

| Item | Detail |
|---|---|
| Framework | React 18 + Vite |
| Styling | Tailwind CSS |
| HTTP client | fetch API |
| Backend communication | `POST http://localhost:8000/api/triage` |

---

## 9. Non-Functional Requirements

| Item | Requirement |
|---|---|
| Local startup | Must run with a single command (`php artisan serve`) |
| Environment variables | Managed via `.env`. Not committed to repo. `.env.example` provided |
| Error handling | API failures and JSON parse errors handled gracefully with clear error messages |
| CORS | Allow requests from frontend dev server (localhost:5173) |
| Response time | Typically 5–10 seconds per request (includes LLM call) |
| Deployment | Not required — local execution only |

---

## 10. Scoring and Evaluation

### 10.1 Score Calculation (Six Fields)

The benchmark evaluates six fields per message:

| Field | Scoring | Type |
|---|---|---|
| `category` | Exact match = 1, no match = 0 | Quantitative |
| `priority` | Exact match = 1, no match = 0 | Quantitative |
| `route_to` | Exact match = 1. Partial credit (0.5) if primary team correct but cc missed | Quantitative |
| `needs_human_review` | Exact match = 1, no match = 0 | Quantitative |
| `draft_reply` | Qualitative: tone guide compliance, must-include points, must-not-include checks | Qualitative |
| `reasoning` | Qualitative: evidence of correct rule application, no bluffing past ambiguity | Qualitative |

**Strict accuracy = messages where all four quantitative fields match ÷ 20 × 100%**

### 10.2 Known Ambiguous Cases

The following six messages are acknowledged by the benchmark as containing at least one judgement call. The goal is not a perfect score — it is to document your reasoning clearly.

| ID | Ambiguity |
|---|---|
| MSG-007 | Dishwasher repair → OUT_OF_SCOPE or BOOKING? |
| MSG-008 | Bathroom reno → flag for human review given likely >$5,000, or not? |
| MSG-010 | Strata block of 8 units → residential or commercial scope? |
| MSG-016 | Two quote items in one message → does the agent catch both? |
| MSG-017 | Tradesperson conduct complaint → P2 or P3? |
| MSG-020 | Brick wall complication mentioned → flag or not? (over-flagging is a negative signal) |

### 10.3 Known Contradictions in Source Materials

The evaluator awards credit for spotting contradictions. The following exist in the provided documents:

| Contradiction | Detail |
|---|---|
| Candidate brief vs SOP (priority SLAs) | Brief lists P2 "within 48h" and P3 "within 5 business days". SOP lists P2 "within 4 business hours" and P3 "within 1 business day". SOP takes precedence |
| MSG-004 benchmark vs SOP ($500 cc rule) | Billing dispute is $150 (under the $500 threshold), yet the benchmark routes to both Customer Care and Accounts. The benchmark conflicts with the SOP routing rule |
| MSG-009 benchmark vs SOP (heater in winter) | SOP explicitly lists "no hot water in winter" as P1, but does not mention "heater not working". Benchmark classifies as BOOKING P2 — defensible either way |

### 10.4 Write-Up Requirements

The write-up must include:

- Strict accuracy score and per-field breakdown
- At least 3 cases where you disagreed with the benchmark, with reasoning
- At least 2 cases where the agent failed and why (prompt issue? missing context? model limitation?)
- A note on tone — was the draft voice consistent with the tone guide, or did it drift?
- One specific thing you would build next (with a concrete reason — "I'd add evals" is not sufficient)

### 10.5 Evaluation Criteria

> A candidate scoring 75% with sharp self-critique is more interesting than one scoring 95% with no reflection.

**Positive signals:**
- Clear separation between rule-following (SOP) and judgement (escalation, tone)
- Agent handles all 20 messages with the same prompts — no per-message hacks
- Spots and argues back against benchmark decisions
- Identifies contradictions between the SOP, catalogue, and tone guide
- "What would you build next" answer is grounded and specific

**Negative signals:**
- Hand-tuning prompts to pass specific benchmark cases
- Draft replies that are obviously generic LLM voice (apologising five times, never referencing the actual problem)
- Quoting "from" prices from the catalogue
- Architectural complexity for its own sake (five agents and a router for a problem that fits in one prompt)

---

## 11. Development Steps

| Step | Task | Estimated Time |
|---|---|---|
| 1 | System prompt design and agent core logic | 60–70 min |
| 2 | Batch run, benchmark scoring, prompt refinement | 20–30 min |
| 3 | Laravel backend API | 20–30 min |
| 4 | React frontend | 20–30 min |
| 5 | README, write-up, push to GitHub | 10–15 min |

---

## 12. Submission Checklist

- [ ] GitHub repository (cloneable)
- [ ] Backend code (Laravel)
- [ ] Frontend code (React)
- [ ] `.env.example` (list of required environment variables)
- [ ] `README.md` (local setup instructions, environment variables, agent design overview)
- [ ] Write-up (accuracy score, benchmark disagreements, failure analysis, contradiction notes, next steps)
