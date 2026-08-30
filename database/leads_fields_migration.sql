-- Adds structured tyre size and vehicle registration fields (previously
-- only captured by concatenating into service_requested free text — see
-- bmt-crm-connector.php's old receive_lead() behaviour), plus a remarks
-- field for manual notes staff add while working a lead.
--
-- Safe to re-run.
ALTER TABLE leads
    ADD COLUMN IF NOT EXISTS tyre_size VARCHAR(30) NULL AFTER service_requested,
    ADD COLUMN IF NOT EXISTS vehicle_registration VARCHAR(20) NULL AFTER tyre_size,
    ADD COLUMN IF NOT EXISTS remarks TEXT NULL AFTER outcome_reason;
