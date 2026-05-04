-- ============================================================================
-- QUOTATION ITEM NAME BUG FIX: SQL BACKFILL SCRIPTS
-- ============================================================================
-- Issue: quotation_items rows were created with literal "Item" instead of
-- actual item names from parent RFQ or MRF line items.
-- ============================================================================


-- ============================================================================
-- STEP 1: INSPECT EXISTING BAD DATA - Find all contaminated rows
-- ============================================================================
-- Run this first to identify the full scope of the problem across the system.
-- Returns all quotation_item rows where item_name is a generic placeholder.

SELECT
    qi.id                  AS quotation_item_id,
    qi.quotation_id,
    qi.rfq_item_id,
    qi.item_name           AS current_incorrect_name,
    qi.description,
    qi.unit,
    qi.quantity,
    qi.unit_price,
    qi.total_price,
    q.quotation_id         AS quote_number,
    r.rfq_id               AS rfq_number,
    v.vendor_id            AS vendor_number,
    v.name                 AS vendor_name,
    m.mrf_id               AS mrf_number,
    m.title                AS mrf_title,
    qi.created_at
FROM quotation_items qi
JOIN quotations q ON q.id = qi.quotation_id
LEFT JOIN rfqs r ON r.id = q.rfq_id
LEFT JOIN vendors v ON v.id = q.vendor_id
LEFT JOIN m_r_f_s m ON m.id = r.mrf_id
WHERE LOWER(TRIM(qi.item_name)) IN ('item', 'product', 'unnamed')
   OR LOWER(TRIM(COALESCE(qi.name, ''))) IN ('item', 'product', 'unnamed')
ORDER BY qi.created_at DESC, qi.id;


-- ============================================================================
-- STEP 2A: BACKFILL SPECIFIC ROWS (17 & 18) FROM MRF ITEMS
-- ============================================================================
-- Target: QUO-2026-001 / RFQ-2026-001 / MRF-EMERALD-2026-001
-- These rows should pull item names from the corresponding MRF line items.

-- INSPECT FIRST: See what MRF items are available for this MRF
SELECT
    mi.id              AS mrf_item_id,
    mi.item_name,
    mi.description,
    mi.unit,
    mi.quantity        AS mrf_quantity,
    ROW_NUMBER() OVER (PARTITION BY mi.mrf_id ORDER BY mi.id) AS seq
FROM mrf_items mi
WHERE mi.mrf_id = (
    SELECT m.id
    FROM m_r_f_s m
    WHERE m.mrf_id = 'MRF-EMERALD-2026-001'
    LIMIT 1
)
ORDER BY mi.id;


-- BACKFILL: Update rows 17 & 18 with the correct item names from MRF
-- This uses ROW_NUMBER to match quotation_item row 17 to the 1st MRF item,
-- and quotation_item row 18 to the 2nd MRF item, etc.
UPDATE quotation_items qi
SET
    item_name = COALESCE(mi.item_name, qi.item_name),
    name = COALESCE(mi.item_name, qi.name),
    description = COALESCE(mi.description, qi.description),
    unit = COALESCE(mi.unit, qi.unit),
    updated_at = NOW()
FROM (
    SELECT
        mi.id,
        mi.item_name,
        mi.description,
        mi.unit,
        mi.mrf_id,
        ROW_NUMBER() OVER (PARTITION BY mi.mrf_id ORDER BY mi.id) AS mrf_seq
    FROM mrf_items mi
) mi
JOIN quotations q ON q.id = qi.quotation_id
JOIN rfqs r ON r.id = q.rfq_id
WHERE
    r.mrf_id = mi.mrf_id
    AND qi.id IN (17, 18)
    -- Match quotation_item row number offset to MRF item sequence
    -- Row 17 = 1st MRF item, Row 18 = 2nd MRF item, etc.
    AND mi.mrf_seq = (qi.id - 16);


-- Verify the backfill
SELECT id, quotation_id, item_name, description, unit FROM quotation_items WHERE id IN (17, 18);


-- ============================================================================
-- STEP 2B: ALTERNATIVE - BACKFILL FROM RFQ ITEMS (if RFQ items are linked)
-- ============================================================================
-- Use this if quotation_items are linked to rfq_items via rfq_item_id.

-- INSPECT: See what RFQ items are available
SELECT
    ri.id              AS rfq_item_id,
    ri.item_name,
    ri.description,
    ri.unit,
    ri.quantity        AS rfq_quantity
FROM rfq_items ri
WHERE ri.rfq_id = (
    SELECT r.id
    FROM r_f_q_s r
    WHERE r.rfq_id = 'RFQ-2026-001'
    LIMIT 1
)
ORDER BY ri.id;


-- BACKFILL from RFQ items (if rfq_item_id is populated)
UPDATE quotation_items qi
SET
    item_name = COALESCE(ri.item_name, qi.item_name),
    name = COALESCE(ri.item_name, qi.name),
    description = COALESCE(ri.description, qi.description),
    unit = COALESCE(ri.unit, qi.unit),
    updated_at = NOW()
FROM rfq_items ri
WHERE
    qi.rfq_item_id = ri.id
    AND qi.id IN (17, 18)
    AND LOWER(TRIM(qi.item_name)) = 'item';


-- Verify the backfill
SELECT id, quotation_id, rfq_item_id, item_name, description, unit FROM quotation_items WHERE id IN (17, 18);


-- ============================================================================
-- STEP 3: SWEEP QUERY - Find ALL contaminated rows across the entire system
-- ============================================================================
-- Run this to get the complete list of affected rows before/after bulk fixes.

SELECT
    COUNT(*)                    AS total_contaminated_rows,
    COUNT(DISTINCT qi.quotation_id) AS affected_quotations,
    COUNT(DISTINCT q.rfq_id)    AS affected_rfqs,
    COUNT(DISTINCT v.id)        AS affected_vendors
FROM quotation_items qi
JOIN quotations q ON q.id = qi.quotation_id
LEFT JOIN vendors v ON v.id = q.vendor_id
WHERE LOWER(TRIM(qi.item_name)) IN ('item', 'product', 'unnamed')
   OR LOWER(TRIM(COALESCE(qi.name, ''))) IN ('item', 'product', 'unnamed');


-- ============================================================================
-- STEP 4: BULK BACKFILL - Fix all contaminated rows using MRF items
-- ============================================================================
-- Fixes all rows where:
-- - quotation_item has generic item_name, AND
-- - the parent RFQ is linked to an MRF
-- Matches items by position (1st quotation_item → 1st MRF item, etc.)

UPDATE quotation_items qi
SET
    item_name = COALESCE(mi.item_name, qi.item_name),
    name = COALESCE(mi.item_name, qi.name),
    description = COALESCE(mi.description, qi.description),
    unit = COALESCE(mi.unit, qi.unit),
    updated_at = NOW()
FROM (
    SELECT
        mi.id,
        mi.item_name,
        mi.description,
        mi.unit,
        mi.mrf_id,
        ROW_NUMBER() OVER (PARTITION BY mi.mrf_id ORDER BY mi.id) AS mrf_seq
    FROM mrf_items mi
) mi
JOIN quotations q ON q.id = qi.quotation_id
JOIN rfqs r ON r.id = q.rfq_id
WHERE
    r.mrf_id = mi.mrf_id
    AND (LOWER(TRIM(qi.item_name)) IN ('item', 'product', 'unnamed')
         OR LOWER(TRIM(COALESCE(qi.name, ''))) IN ('item', 'product', 'unnamed'))
    -- Match by position within the MRF
    AND mi.mrf_seq = (
        SELECT ROW_NUMBER() OVER (PARTITION BY q.rfq_id ORDER BY qi.id)
        FROM quotation_items qi2
        WHERE qi2.quotation_id = q.id AND qi2.id = qi.id
    );


-- ============================================================================
-- STEP 5: FALLBACK BULK BACKFILL - Use RFQ items if available
-- ============================================================================
-- For rows that couldn't be fixed by MRF lookup, try matching via rfq_item_id.

UPDATE quotation_items qi
SET
    item_name = COALESCE(ri.item_name, qi.item_name),
    name = COALESCE(ri.item_name, qi.name),
    description = COALESCE(ri.description, qi.description),
    unit = COALESCE(ri.unit, qi.unit),
    updated_at = NOW()
FROM rfq_items ri
WHERE
    qi.rfq_item_id = ri.id
    AND (LOWER(TRIM(qi.item_name)) IN ('item', 'product', 'unnamed')
         OR LOWER(TRIM(COALESCE(qi.name, ''))) IN ('item', 'product', 'unnamed'));


-- ============================================================================
-- FINAL VERIFICATION
-- ============================================================================
-- After running the backfills above, this query should return 0 rows.
-- If it returns rows, those items could not be auto-recovered and need manual review.

SELECT
    qi.id,
    qi.quotation_id,
    qi.rfq_item_id,
    qi.item_name,
    q.quotation_id      AS quote_number,
    v.vendor_id         AS vendor_id,
    r.rfq_id            AS rfq_id
FROM quotation_items qi
JOIN quotations q ON q.id = qi.quotation_id
LEFT JOIN vendors v ON v.id = q.vendor_id
LEFT JOIN rfqs r ON r.id = q.rfq_id
WHERE LOWER(TRIM(qi.item_name)) IN ('item', 'product', 'unnamed')
   OR LOWER(TRIM(COALESCE(qi.name, ''))) IN ('item', 'product', 'unnamed')
ORDER BY qi.id;


-- ============================================================================
-- SUMMARY REPORT - After Fix
-- ============================================================================
-- Run this to confirm all item names are now meaningful (not generic placeholders).

SELECT
    'Total quotation items' AS metric,
    COUNT(*) AS count
FROM quotation_items

UNION ALL

SELECT
    'Items with placeholder names (BUG STATE)',
    COUNT(*)
FROM quotation_items
WHERE LOWER(TRIM(item_name)) IN ('item', 'product', 'unnamed')
   OR LOWER(TRIM(COALESCE(name, ''))) IN ('item', 'product', 'unnamed')

UNION ALL

SELECT
    'Items with meaningful names (CORRECT STATE)',
    COUNT(*)
FROM quotation_items
WHERE LOWER(TRIM(item_name)) NOT IN ('item', 'product', 'unnamed')
   AND LOWER(TRIM(COALESCE(name, ''))) NOT IN ('item', 'product', 'unnamed')
   AND LENGTH(TRIM(item_name)) > 0;
