# Clear Test MRFs - Instructions

## Overview

This document explains how to clear test MRFs from the system, specifically "supply of stock" MRF requests that were created for testing.

## Command Usage

### Clear all "supply of stock" MRFs (Default)

```bash
php artisan mrfs:clear-test
```

This will:
- Find all MRFs with "supply of stock" in the title or description
- Show a list of MRFs to be deleted
- Ask for confirmation before deleting
- Delete all related data (approval history, RFQs, quotations, items, files)

### Clear MRFs by specific title

```bash
php artisan mrfs:clear-test --title="supply of stock" --title="test mrf"
```

### Clear MRFs by category

```bash
php artisan mrfs:clear-test --category="Inventory" --category="Stock"
```

### Force delete without confirmation

```bash
php artisan mrfs:clear-test --force
```

## What Gets Deleted

When an MRF is deleted, the following related data is also deleted:

1. **MRF Approval History** - All approval records
2. **Related RFQs** - All RFQs created from the MRF
3. **Quotations** - All quotations submitted for those RFQs
4. **RFQ Vendors** - Vendor associations
5. **RFQ Items** - Items in RFQs
6. **MRF Items** - Items in the MRF
7. **Files** - PO files, GRN files, PFI files (if stored locally)
8. **The MRF itself**

## Safety

- The command uses database transactions - if anything fails, all changes are rolled back
- By default, confirmation is required before deletion
- The command shows what will be deleted before proceeding
- Related data is cleaned up to prevent orphaned records

## Example Output

```
$ php artisan mrfs:clear-test

Found 3 MRF(s) to delete:
  - MRF-2025-001: Supply of Stock for Testing (Inventory)
  - MRF-2025-002: Supply of Stock Equipment (Inventory)
  - MRF-2025-003: Test MRF - Supply of Stock (Stock)

Do you want to delete these MRFs? (yes/no) [yes]:
> yes

Deleting MRF: MRF-2025-001...
  - Deleted approval history
  - Deleted related RFQs and quotations
  - Deleted MRF items
  ✓ MRF MRF-2025-001 deleted successfully

[... continues for other MRFs ...]

✓ Successfully deleted 3 MRF(s) and all related data.
```
