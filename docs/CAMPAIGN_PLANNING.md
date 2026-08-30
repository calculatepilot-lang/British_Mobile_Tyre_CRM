# Campaign Planning

## Current mode

The planner itself (`CampaignPlanner`) is still **plan_only** — it never calls a Google Ads mutate service, only proposes campaigns via the approval workflow.

Execution is now possible, but scoped: `ChangeExecutor::createCampaignSkeleton()` (used from `/changes` or the `execute-conversion-actions`-style cron pattern) creates the campaign **skeleton** once a proposal is approved — a budget, the campaign itself (always created **PAUSED**), one empty ad group, and 15km proximity location targeting around the city's planning centre. It does not add keywords, ads, or negative keywords, and it never switches a campaign to ENABLED — a human finishes those steps in the Google Ads UI and enables it only when satisfied. This mirrors the same approval-then-execute pattern already used for conversion actions, with an extra safety margin: even after execution, the campaign cannot spend anything until a human takes one more manual action.

## Markets

The initial service configuration contains 40 enabled cities supplied by the owner. Five are marked high priority: London, Birmingham, Manchester, Leeds and Liverpool.

## Vehicle eligibility

Campaign planning is restricted to:

- Car
- Van
- Caravan
- Bus
- Truck
- Trailer

Two-wheelers and three-wheelers are excluded from qualified lead optimisation and are represented as negative-keyword candidates. Automatic negative-keyword application remains disabled.

## Road and service-area coverage

Road coverage may be planned around:

- motorways
- major A-roads
- city approaches
- junction areas
- verified service areas

Every road/service-area coordinate must be verified and approved. The system must not invent POIs, motorway geometry or service-station coordinates.

## Activation gate

Before any Google Ads creation or mutation:

1. Resolve supported Google geo targets.
2. Review campaign plan.
3. Review budget limits.
4. Review conversion configuration.
5. Approve each major change through the CRM approval workflow.
6. Record before-state for reversible changes.
