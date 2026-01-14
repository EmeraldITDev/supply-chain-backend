-- Script to clear all PO numbers and related PO data from MRF requests
-- This will allow regeneration of POs without conflicts
-- Run this script in your PostgreSQL database

-- Clear PO numbers and related fields
UPDATE m_r_f_s 
SET 
    po_number = NULL,
    unsigned_po_url = NULL,
    unsigned_po_share_url = NULL,
    signed_po_url = NULL,
    signed_po_share_url = NULL,
    po_generated_at = NULL,
    po_signed_at = NULL,
    po_version = 1,
    status = CASE 
        WHEN status = 'supply_chain' THEN 'procurement'
        WHEN status = 'PO Rejected' THEN 'procurement'
        ELSE status
    END,
    current_stage = CASE 
        WHEN current_stage = 'supply_chain' THEN 'procurement'
        ELSE current_stage
    END
WHERE po_number IS NOT NULL;

-- Verify the update
SELECT 
    COUNT(*) as total_mrfs,
    COUNT(po_number) as mrfs_with_po,
    COUNT(*) FILTER (WHERE po_number IS NULL) as mrfs_without_po
FROM m_r_f_s;

-- Show MRFs that were affected
SELECT 
    mrf_id,
    title,
    status,
    current_stage,
    po_number,
    po_generated_at
FROM m_r_f_s
WHERE po_number IS NULL
ORDER BY created_at DESC
LIMIT 20;
