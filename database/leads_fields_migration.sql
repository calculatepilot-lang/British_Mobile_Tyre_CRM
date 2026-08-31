-- Adds structured tyre size and vehicle registration fields (previously
-- only captured by concatenating into service_requested free text — see
-- bmt-crm-connector.php's old receive_lead() behaviour), plus a remarks
-- field for manual notes staff add while working a lead.
--
-- Safe to re-run.
ALTER TABLE leads
    ADD COLUMN IF NOT EXISTS tyre_size VARCHAR(30) NULL AFTER service_requested,
    ADD COLUMN IF NOT EXISTS vehicle_registration VARCHAR(20) NULL AFTER tyre_size,
    ADD COLUMN IF NOT EXISTS remarks TEXT NULL AFTER outcome_reason,
    ADD COLUMN IF NOT EXISTS source_page_url VARCHAR(500) NULL AFTER remarks,
    ADD COLUMN IF NOT EXISTS source_page_label VARCHAR(160) NULL AFTER source_page_url;

-- Locking nut and vehicle type — captured from the enquiry form.
ALTER TABLE leads
    ADD COLUMN IF NOT EXISTS locking_nut ENUM('yes','no') NULL AFTER vehicle_registration,
    ADD COLUMN IF NOT EXISTS vehicle_type ENUM('Car','Van','Caravan','Bus','Lorry','Trailer') NULL AFTER locking_nut;
