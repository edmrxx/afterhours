-- =====================================================================
-- REPAIR: free court slots stranded by deleted bookings
-- =====================================================================
--
-- WHAT WENT WRONG
-- ---------------
-- Admin > Bookings > Delete used to hand a slot back to the market only
-- when the booking was still on hold (`awaiting_payment` /
-- `pending_verification`). But confirming a booking promotes its slots to
-- `booked`, and completing one deliberately leaves them there — so
-- deleting a CONFIRMED or COMPLETED booking soft-deleted the booking row
-- and left every one of its slots sitting at `booked`, still pointing at
-- a booking that no longer appears anywhere in the admin.
--
-- The result is an hour that is invisible in Bookings, still renders as
-- "Booked" on the public grid, and never comes back on sale on its own.
-- Silent lost revenue, one delete at a time.
--
-- The code path is fixed (BookingService::releaseSlotsOnDeletion, called
-- from BookingController::destroy, covered by
-- tests/Feature/Booking/BookingDeletionReleasesSlotsTest.php). This script
-- repairs the rows the old behaviour already stranded.
--
-- WHAT THIS SCRIPT DOES
-- ---------------------
-- Sets `available` on every slot that is `held` or `booked` while the
-- booking behind it is soft-deleted, hard-deleted, or missing entirely,
-- and clears the dangling `held_booking_id` pointer.
--
-- WHAT IT DELIBERATELY DOES NOT TOUCH
-- -----------------------------------
--  * `blocked` slots — staff took those hours off the market on purpose.
--    A broken delete elsewhere is no reason to start selling them again.
--  * Slots held by a LIVE booking — those reservations are real.
--  * The `bookings` rows themselves, soft-deleted or not. They stay as
--    history. This script only reopens inventory.
--  * `booking_slots`. Those rows are write-once history by design and stay
--    readable for every booking that ever held a slot.
--
-- SAFE TO RUN TWICE. The second run matches nothing and updates 0 rows.
--
-- HOW TO RUN IT (Hostinger / phpMyAdmin)
-- --------------------------------------
--  1. Export a backup of the database first.
--  2. Select the database in the left sidebar (e.g. u499582132_db_afterhours)
--     — this script has no USE statement on purpose, so it runs against
--     whichever database you pick, online or local.
--  3. Import tab > choose this file > Go.
--  4. Read the two reports it prints: STEP 1 lists what it is about to
--     free, STEP 4 must come back empty.
--
-- =====================================================================


-- ---------------------------------------------------------------------
-- STEP 1 — BEFORE: exactly which slots are stranded right now
-- ---------------------------------------------------------------------
-- Read this list. These are the hours about to go back on sale, with the
-- dead booking behind each one named so you can sanity-check every row.

SELECT
    cs.id                AS slot_id,
    c.name               AS court,
    cs.slot_date,
    cs.start_time,
    cs.end_time,
    cs.status            AS slot_status,
    cs.held_booking_id,
    b.code               AS booking_code,
    b.status             AS booking_status,
    b.deleted_at         AS booking_deleted_at
FROM court_slots cs
LEFT JOIN bookings b ON b.id = cs.held_booking_id
LEFT JOIN courts   c ON c.id = cs.court_id
WHERE cs.status IN ('held', 'booked')
  AND (cs.held_booking_id IS NULL OR b.id IS NULL OR b.deleted_at IS NOT NULL)
ORDER BY cs.slot_date, cs.start_time, cs.court_id;


-- ---------------------------------------------------------------------
-- STEP 2 — THE REPAIR
-- ---------------------------------------------------------------------

START TRANSACTION;

UPDATE court_slots cs
LEFT JOIN bookings b ON b.id = cs.held_booking_id
SET cs.status          = 'available',
    cs.held_booking_id = NULL,
    cs.updated_at      = NOW()
WHERE cs.status IN ('held', 'booked')
  AND (cs.held_booking_id IS NULL OR b.id IS NULL OR b.deleted_at IS NOT NULL);


-- ---------------------------------------------------------------------
-- STEP 3 — leave a trace in the audit trail
-- ---------------------------------------------------------------------
-- Hours reappearing on the calendar with nothing in Audit Trail to explain
-- them reads as a second bug. Recorded as a system action, not as any
-- staff member, because no one clicked anything to cause it.
--
-- ROW_COUNT() is the number of slots the UPDATE above just freed. It must
-- be read in the very next statement — anything in between resets it.
--
-- The WHERE guard makes a repeat run silent: re-running this file to prove
-- the repair held is a normal thing to do, and it should not leave a trail
-- of "0 slot(s) freed" entries behind every time.

INSERT INTO audit_trails
    (user_id, user_name, role_name, module, action, description,
     auditable_type, auditable_id, old_values, new_values,
     ip_address, user_agent, browser, platform, url, method, created_at)
SELECT
    NULL,
    'System',
    NULL,
    'Slots',
    'update',
    CONCAT(
        'Data repair: ', ROW_COUNT(), ' court slot(s) stranded by deleted ',
        'bookings were returned to available. Blocked slots and slots held ',
        'by live bookings were left untouched.'
    ),
    NULL,
    NULL,
    JSON_OBJECT('slot_status', 'booked', 'held_booking_id', 'dangling'),
    JSON_OBJECT('slot_status', 'available', 'slots_freed', ROW_COUNT()),
    NULL, NULL, NULL, NULL, NULL, NULL,
    NOW()
FROM DUAL
WHERE ROW_COUNT() > 0;

COMMIT;


-- ---------------------------------------------------------------------
-- STEP 4 — AFTER: this MUST come back empty
-- ---------------------------------------------------------------------
-- Same predicate as STEP 1. Any row still listed here means the repair did
-- not take — do not ignore it.

SELECT
    cs.id                AS still_stranded_slot_id,
    cs.slot_date,
    cs.start_time,
    cs.status            AS slot_status,
    cs.held_booking_id
FROM court_slots cs
LEFT JOIN bookings b ON b.id = cs.held_booking_id
WHERE cs.status IN ('held', 'booked')
  AND (cs.held_booking_id IS NULL OR b.id IS NULL OR b.deleted_at IS NOT NULL)
ORDER BY cs.slot_date, cs.start_time;


-- ---------------------------------------------------------------------
-- STEP 5 — the resulting picture
-- ---------------------------------------------------------------------
-- `blocked` should still show whatever it showed before the repair.

SELECT status, COUNT(*) AS slots
FROM court_slots
GROUP BY status
ORDER BY status;
