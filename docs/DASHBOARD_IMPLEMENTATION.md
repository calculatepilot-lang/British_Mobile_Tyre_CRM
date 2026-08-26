# Dashboard Implementation

The CRM dashboard is the human control plane.

## Required screens

1. Overview: total leads, qualified leads, bookings, completed jobs, revenue and pending approvals.
2. Leads: filterable by city, allowed vehicle type and pipeline status.
3. Quality: compare lead outcomes by city, vehicle type and campaign.
4. Campaign Plans: read-only review of generated plans.
5. Approvals: approve or reject pending automation decisions.
6. Change History: inspect decisions, reasons, before state and proposed state.

## Safety invariant

Dashboard approval does not itself execute a Google Ads mutation. Execution remains disabled while `AUTOMATION_MODE=audit_only`.

## Next implementation

Wire these services into authenticated PHP routes and add CSRF-protected approve/reject forms.
