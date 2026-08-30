# Claude-based daily optimisation

## What this is

A second source of daily optimisation proposals, alongside the existing
fixed-threshold rules in `OptimiserService`. It does not replace the rule
engine — both run and both queue into the same `automation_changes`
approval table on `/changes`.

The rule engine can only ever apply the same fixed thresholds (N clicks, 0
conversions → cut budget). Claude is given the day's campaign efficiency
figures AND lead-quality figures (by city, by vehicle type) together, and
can reason about them and explain its recommendation in plain language —
that explanation becomes the proposal's `reason`, visible to whoever
reviews it.

## Safety model — read this before enabling

- **Claude never touches Google Ads.** `ClaudeDailyOptimiser` has no
  access to `ChangeExecutor` and cannot execute anything. It only writes
  rows to `automation_changes`, exactly like the rule engine and the
  campaign/conversion planners.
- **Every recommendation is validated against real data first.** A
  campaign name is only accepted if it appears verbatim in that day's
  actual `campaign_efficiency()` results — a hallucinated or malformed
  name is dropped, never queued.
- **Percent changes are capped at the same limit as the rule engine**
  (`MAX_AUTO_BUDGET_CHANGE_PERCENT`) — Claude cannot propose a larger
  change than your existing rules already allow, regardless of what it
  suggests.
- **Every Claude-sourced proposal requires human approval, with no
  exceptions.** Proposals are always queued at `risk_level >= medium`,
  which forces `pending_approval` status — never the `risk_level='low'`
  auto-approved path some rule-based proposals can take. An AI-authored
  proposal always needs a person to click Approve.
- **A failed or malformed API response queues nothing.** If the Claude
  API call fails, times out, or doesn't return valid JSON, the job logs
  the failure to `error_logs` and returns zero recommendations — it never
  falls back to guessing.

## Enabling it

```env
CLAUDE_OPTIMISATION_ENABLED=true
ANTHROPIC_API_KEY=sk-ant-...
CLAUDE_MODEL=claude-haiku-4-5-20251001
```

Haiku is the default — this runs once a day on a small, structured
dataset, which doesn't need a larger model. Set `CLAUDE_MODEL=claude-sonnet-5`
if the reasoning quality needs to go up later; check
[Anthropic's model docs](https://docs.claude.com/en/docs/about-claude/models/overview)
for the current model lineup before changing this, since model names and
availability change over time.

Run manually to test:

```bash
php cron/claude_daily_optimisation.php
```

Or via Composer:

```bash
composer run claude-optimisation
```

It analyses **yesterday's** data (matching `daily_audit.php`'s convention)
and prints a JSON summary: how many recommendations Claude returned, how
many were validated and queued, how many were skipped (already had an
open proposal), and any error.

Suggested cron schedule: once daily, after `daily_audit.php` has run and
collected the previous day's `daily_metrics`.

## Requirements

- Outbound HTTPS to `api.anthropic.com` from the Hostinger server. Most
  shared hosting allows this by default, but if the job fails with a
  connection error, check with Hostinger support whether outbound
  requests to that host are blocked.
- An Anthropic API key with available credit — this is billed separately
  from anything else in this CRM.

## What it does NOT do

- Does not create, pause, or modify anything in Google Ads directly.
- Does not see or reason about anything outside campaign efficiency and
  lead-quality figures — no PII, no customer contact details are sent to
  the API.
- Does not run more than once per invocation — there's no retry loop or
  backoff; a failed run just waits for the next scheduled cron tick.
