# Campaign Planning

## Current mode

The campaign planner is **plan_only**. It does not call a Google Ads mutate service.

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
