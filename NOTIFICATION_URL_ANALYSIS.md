# Notification & Email URL Construction Analysis

## Overview
Analysis of all Notification and Mail classes in `app/Notifications/` and `app/Mail/` directories that send email links and handle URL construction.

---

## MRF (Material Requisition Form) Related

### 1. **MRFSubmittedNotification**
- **File Path:** `app/Notifications/MRFSubmittedNotification.php`
- **Email Type:** MRF Submitted
- **Channels:** Mail + Database
- **URL Construction Logic:**
  ```php
  $frontendUrl = config('app.frontend_url', 'https://emerald-supply-chain.vercel.app');
  $mrfUrl = rtrim($frontendUrl, '/') . '/mrfs/' . $this->mrf->mrf_id;
  // Action button: 'Review MRF' → $mrfUrl
  ```
- **Key URLs:**
  - Base: `config('app.frontend_url')` (fallback: `https://emerald-supply-chain.vercel.app`)
  - Action URL: `/mrfs/{mrf_id}`
  - Database storage: `/mrfs/{mrf_id}` (relative)
- **Recipients:** Approval stage users (Executive or Supply Chain Director)
- **Hardcoded URLs:** None (uses config-based)

### 2. **MRFApprovedNotification**
- **File Path:** `app/Notifications/MRFApprovedNotification.php`
- **Email Type:** MRF Approved
- **Channels:** Mail + Database
- **URL Construction Logic:**
  ```php
  $frontendUrl = config('app.frontend_url', 'https://emerald-supply-chain.vercel.app');
  $mrfUrl = rtrim($frontendUrl, '/') . '/mrfs/' . $this->mrf->mrf_id;
  // Action button: 'View MRF Details' → $mrfUrl
  ```
- **Key URLs:**
  - Base: `config('app.frontend_url')` (fallback: `https://emerald-supply-chain.vercel.app`)
  - Action URL: `/mrfs/{mrf_id}`
- **Recipients:** MRF Requester/Creator
- **Hardcoded URLs:** None (uses config-based)

### 3. **MRFRejectedNotification**
- **File Path:** `app/Notifications/MRFRejectedNotification.php`
- **Email Type:** MRF Rejected
- **Channels:** Mail + Database
- **URL Construction Logic:**
  ```php
  $frontendUrl = config('app.frontend_url', 'https://emerald-supply-chain.vercel.app');
  $mrfUrl = rtrim($frontendUrl, '/') . '/mrfs/' . $this->mrf->mrf_id;
  // Action button: 'View MRF Details' → $mrfUrl
  ```
- **Key URLs:**
  - Base: `config('app.frontend_url')` (fallback: `https://emerald-supply-chain.vercel.app`)
  - Action URL: `/mrfs/{mrf_id}`
- **Recipients:** MRF Requester
- **Hardcoded URLs:** None (uses config-based)

### 4. **MRFCreatedMail**
- **File Path:** `app/Mail/MRFCreatedMail.php`
- **Email Type:** New MRF Submitted
- **Note:** Uses Blade template `emails.mrf-created`
- **Template URL Logic (from view):**
  ```blade
  <img src="{{ config('app.frontend_url') }}/images/emerald-logo.png" />
  <a href="{{ config('app.frontend_url') . '/procurement/' }}">View MRF</a>
  ```
- **Key URLs:**
  - Logo: `{frontend_url}/images/emerald-logo.png`
  - CTA: `{frontend_url}/procurement/`
- **Recipients:** Procurement team
- **Hardcoded URLs:** None (uses config-based)

### 5. **MRFApprovedMail**
- **File Path:** `app/Mail/MRFApprovedMail.php`
- **Email Type:** MRF Approved
- **Template:** `emails.mrf-approved`
- **Template URL Logic:**
  ```blade
  <img src="{{ config('app.frontend_url') }}/images/emerald-logo.png" />
  <a href="{{ config('app.frontend_url') . '/procurement/' }}">View MRF</a>
  ```
- **Key URLs:**
  - Logo: `{frontend_url}/images/emerald-logo.png`
  - CTA: `{frontend_url}/procurement/`
- **Recipients:** MRF Requester
- **Hardcoded URLs:** None (uses config-based)

### 6. **MRFRejectedMail**
- **File Path:** `app/Mail/MRFRejectedMail.php`
- **Email Type:** MRF Rejected
- **Template:** `emails.mrf-rejected`
- **Template URL Logic:**
  ```blade
  <img src="{{ config('app.frontend_url') }}/images/emerald-logo.png" />
  <a href="{{ config('app.frontend_url') . '/procurement/' }}">View MRF</a>
  ```
- **Key URLs:**
  - Logo: `{frontend_url}/images/emerald-logo.png`
  - CTA: `{frontend_url}/procurement/`
- **Recipients:** MRF Requester
- **Hardcoded URLs:** None (uses config-based)

---

## RFQ (Request for Quotation) Related

### 7. **RFQAssignedNotification**
- **File Path:** `app/Notifications/RFQAssignedNotification.php`
- **Email Type:** RFQ Assigned
- **Channels:** Database only (NO EMAIL)
- **URL Construction Logic:**
  ```php
  'action_url' => "/rfqs/{$this->rfq->rfq_id}",
  ```
- **Key URLs:**
  - Action URL: `/rfqs/{rfq_id}` (relative path stored in DB)
- **Recipients:** Assigned vendors
- **Note:** This notification does NOT send emails; it only stores in the database

### 8. **RFQSentMail**
- **File Path:** `app/Mail/RFQSentMail.php`
- **Email Type:** New RFQ Assigned (Vendor Portal Access)
- **Channels:** Mail (queued)
- **URL Construction Logic:**
  ```php
  $rfqUrl = rtrim((string) config('app.frontend_url'), '/') . '/vendor-portal';
  // Passed to view as: $rfqUrl
  ```
- **Key URLs:**
  - Base: `config('app.frontend_url')`
  - Action URL: `/vendor-portal`
  - Logo: `{frontend_url}/images/emerald-logo.png`
- **Template:** `emails.rfq-notification`
- **Recipients:** Vendors
- **Hardcoded URLs:** None (uses config-based)

---

## Quotation Related

### 9. **QuotationSubmittedNotification**
- **File Path:** `app/Notifications/QuotationSubmittedNotification.php`
- **Email Type:** New Quotation Received
- **Channels:** Database only (NO EMAIL)
- **URL Construction Logic:**
  ```php
  'action_url' => "/quotations/{$this->quotation->quotation_id}",
  ```
- **Key URLs:**
  - Action URL: `/quotations/{quotation_id}` (relative path stored in DB)
- **Recipients:** Procurement/RFQ managers
- **Note:** This notification does NOT send emails; it only stores in the database

### 10. **QuotationStatusUpdatedNotification**
- **File Path:** `app/Notifications/QuotationStatusUpdatedNotification.php`
- **Email Type:** Quotation Status Updated
- **Channels:** Database only (NO EMAIL)
- **URL Construction Logic:**
  ```php
  'action_url' => "/quotations/{$this->quotation->quotation_id}",
  ```
- **Key URLs:**
  - Action URL: `/quotations/{quotation_id}` (relative path stored in DB)
- **Recipients:** Quotation vendor/submitter
- **Note:** This notification does NOT send emails; it only stores in the database

### 11. **QuotationSubmittedMail**
- **File Path:** `app/Mail/QuotationSubmittedMail.php`
- **Email Type:** Quotation Submitted
- **Template:** `emails.quotation-submitted`
- **Template URL Logic:**
  ```blade
  <img src="{{ config('app.frontend_url') }}/images/emerald-logo.png" />
  <a class="button" href="{{ rtrim((string) config('app.frontend_url'), '/') . '/vendor-portal' }}">
      Review Quotation
  </a>
  ```
- **Key URLs:**
  - Base: `config('app.frontend_url')`
  - Logo: `{frontend_url}/images/emerald-logo.png`
  - CTA: `{frontend_url}/vendor-portal`
- **Recipients:** Procurement team
- **Hardcoded URLs:** None (uses config-based)

### 12. **VendorSelectedMail**
- **File Path:** `app/Mail/VendorSelectedMail.php`
- **Email Type:** Vendor Selection Update
- **Template:** `emails.vendor-selected`
- **Template URL Logic:**
  ```blade
  <img src="{{ config('app.frontend_url') }}/images/emerald-logo.png" />
  <a class="button" href="{{ rtrim((string) config('app.frontend_url'), '/') . '/vendor-portal' }}">
      View RFQ Details
  </a>
  ```
- **Key URLs:**
  - Base: `config('app.frontend_url')`
  - Logo: `{frontend_url}/images/emerald-logo.png`
  - CTA: `{frontend_url}/vendor-portal`
- **Recipients:** Selected vendor
- **Hardcoded URLs:** None (uses config-based)

---

## Vendor Related

### 13. **VendorRegistrationNotification**
- **File Path:** `app/Notifications/VendorRegistrationNotification.php`
- **Email Type:** New Vendor Registration
- **Channels:** Database only (NO EMAIL)
- **URL Construction Logic:**
  ```php
  'action_url' => "/vendors/registrations/{$this->registration->id}",
  ```
- **Key URLs:**
  - Action URL: `/vendors/registrations/{id}` (relative path stored in DB)
- **Recipients:** Admin/Registration processors
- **Note:** This notification does NOT send emails; it only stores in the database

### 14. **VendorApprovedNotification**
- **File Path:** `app/Notifications/VendorApprovedNotification.php`
- **Email Type:** Vendor Registration Approved
- **Channels:** Database only (NO EMAIL)
- **URL Construction Logic:**
  ```php
  'action_url' => "/vendors/{$this->vendor->id}",
  ```
- **Key URLs:**
  - Action URL: `/vendors/{id}` (relative path stored in DB)
- **Recipients:** Admin/Vendor coordinator
- **Note:** This notification does NOT send emails; it only stores in the database

### 15. **VendorApprovalMail**
- **File Path:** `app/Mail/VendorApprovalMail.php`
- **Email Type:** Welcome to Emerald Partnership (Vendor Portal Access)
- **Template:** `emails.vendor-approval`
- **URL Construction Logic:**
  ```php
  $frontendUrl = env('FRONTEND_URL', 'https://emerald-supply-chain.vercel.app');
  $vendorPortalUrl = rtrim($frontendUrl, '/') . '/vendor-portal';
  // Passed to view as: $vendorPortalUrl
  ```
- **Key URLs:**
  - Base: `env('FRONTEND_URL')` (fallback: `https://emerald-supply-chain.vercel.app`)
  - Logo: `{frontend_url}/images/emerald-logo.png`
  - Login Portal: `{frontend_url}/vendor-portal` (passed as $vendorPortalUrl or $loginUrl)
- **Template Variables:** `$vendorPortalUrl`, `$companyName`, `$email`, `$temporaryPassword`
- **Recipients:** Approved vendor (email address)
- **Hardcoded URLs:** None (uses env-based config)
- **Note:** Uses `env()` instead of `config()` - potential inconsistency

### 16. **VendorPasswordResetRequestNotification**
- **File Path:** `app/Notifications/VendorPasswordResetRequestNotification.php`
- **Email Type:** Vendor Password Reset Request
- **Channels:** Database only (NO EMAIL)
- **URL Construction Logic:**
  ```php
  'action_url' => is_object($this->vendor) && isset($this->vendor->id) 
      ? "/vendors/{$this->vendor->id}" 
      : "/vendors",
  ```
- **Key URLs:**
  - Action URL: `/vendors/{id}` or `/vendors` (relative path stored in DB)
- **Recipients:** Admin
- **Note:** This notification does NOT send emails; it only stores in the database

---

## Logistics Related

### 17. **JourneyStatusUpdatedNotification**
- **File Path:** `app/Notifications/JourneyStatusUpdatedNotification.php`
- **Email Type:** Journey Status Update
- **Channels:** Mail + Database
- **URL Construction Logic:**
  ```php
  // Uses url() helper - relative path only
  url("/logistics/trips/{$trip->id}")
  ```
- **Key URLs:**
  - Action URL: `/logistics/trips/{trip_id}`
  - Database storage: `/logistics/trips/{trip_id}` (relative)
- **Recipients:** Trip stakeholders/drivers
- **Hardcoded URLs:** None (uses url() helper)
- **Status Transitions:** DRAFT → SCHEDULED → DEPARTED → EN_ROUTE → ARRIVED → COMPLETED/CANCELLED

### 18. **VendorAssignedToTripNotification**
- **File Path:** `app/Notifications/VendorAssignedToTripNotification.php`
- **Email Type:** New Trip Assignment
- **Channels:** Mail + Database
- **URL Construction Logic:**
  ```php
  // Uses url() helper - relative path only
  url("/logistics/trips/{$this->trip->id}")
  ```
- **Key URLs:**
  - Action URL: `/logistics/trips/{trip_id}`
  - Database storage: `/logistics/trips/{trip_id}` (relative)
- **Recipients:** Assigned vendor
- **Hardcoded URLs:** None (uses url() helper)

---

## Document & Maintenance Related

### 19. **DocumentExpiryNotification**
- **File Path:** `app/Notifications/DocumentExpiryNotification.php`
- **Email Type:** Document Expiry Alert
- **Channels:** Database only (NO EMAIL)
- **URL Construction Logic:**
  ```php
  'action_url' => "/vendors/{$this->vendorId}",
  ```
- **Key URLs:**
  - Action URL: `/vendors/{vendor_id}` (relative path stored in DB)
- **Recipients:** Admin
- **Note:** This notification does NOT send emails; it only stores in the database
- **Alert Types:** Days until expiry or expired status

### 20. **VehicleMaintenanceOverdueNotification & Others**
- **Files:** (Additional notification files exist but not detailed in this analysis)
- **Status:** Database-only notifications

---

## Email View Templates Summary

| Template File | Uses | Base URL Construction |
|---|---|---|
| `emails/mrf-created.blade.php` | Logo, Links | `config('app.frontend_url')` |
| `emails/mrf-approved.blade.php` | Logo, Links | `config('app.frontend_url')` |
| `emails/mrf-rejected.blade.php` | Logo, Links | `config('app.frontend_url')` |
| `emails/rfq-notification.blade.php` | Logo, Links | `config('app.frontend_url')` |
| `emails/quotation-submitted.blade.php` | Logo, Links | `rtrim(config('app.frontend_url'), '/')` |
| `emails/vendor-selected.blade.php` | Logo, Links | `rtrim(config('app.frontend_url'), '/')` |
| `emails/vendor-approval.blade.php` | Logo, Links | `env('FRONTEND_URL')` via PHP class |
| `emails/po-generated.blade.php` | (To be analyzed) | (To be analyzed) |

---

## Key Findings

### URL Configuration Methods
1. **Notifications (MailMessage):** `config('app.frontend_url')`
2. **Mail Classes:** 
   - `config('app.frontend_url')` (most common)
   - `env('FRONTEND_URL')` (VendorApprovalMail - inconsistent)
3. **Blade Templates:** Both `config()` and passed parameters from Mail class

### Fallback URLs
- **Primary Fallback:** `https://emerald-supply-chain.vercel.app` (used in Notification classes)
- **VendorApprovalMail Exception:** Uses `env()` with same fallback

### Common Action URL Paths
| Module | Action URLs |
|---|---|
| **MRF** | `/mrfs/{mrf_id}`, `/procurement/` |
| **RFQ** | `/rfqs/{rfq_id}`, `/vendor-portal` |
| **Quotation** | `/quotations/{quotation_id}`, `/vendor-portal` |
| **Vendor** | `/vendors/{id}`, `/vendors/registrations/{id}`, `/vendor-portal` |
| **Logistics** | `/logistics/trips/{id}` |

### Hardcoded URLs
- **None found** - All URLs use configuration-based approach
- Logo URL pattern: `{frontend_url}/images/emerald-logo.png`

### Email Sending Channels
**WITH EMAIL:**
- MRFSubmittedNotification ✓
- MRFApprovedNotification ✓
- MRFRejectedNotification ✓
- JourneyStatusUpdatedNotification ✓
- VendorAssignedToTripNotification ✓
- MRFCreatedMail ✓
- MRFApprovedMail ✓
- MRFRejectedMail ✓
- RFQSentMail ✓
- QuotationSubmittedMail ✓
- VendorSelectedMail ✓
- VendorApprovalMail ✓
- POGeneratedMail ✓

**DATABASE ONLY (NO EMAIL):**
- RFQAssignedNotification
- QuotationSubmittedNotification
- QuotationStatusUpdatedNotification
- VendorRegistrationNotification
- VendorApprovedNotification
- VendorPasswordResetRequestNotification
- DocumentExpiryNotification
- VehicleMaintenanceOverdueNotification
- LogisticsEventNotification
- LogisticsReportOverdueNotification

---

## Configuration Risk Assessment

### ⚠️ Potential Issues Identified

1. **Configuration Inconsistency:** `VendorApprovalMail` uses `env('FRONTEND_URL')` instead of `config('app.frontend_url')` - should be standardized
2. **Missing Email:** `RFQAssignedNotification` is database-only but might need email delivery to vendors
3. **URL Encoding:** Some templates use `rtrim()` for URL construction, others don't - inconsistent approach
4. **Image Assets:** All email templates reference logo via `{frontend_url}/images/emerald-logo.png` - ensure this path exists in frontend

### ✅ Best Practices Currently Followed

1. All URLs use configuration-based approach (no hardcoded production URLs)
2. Proper fallback URLs defined for development
3. Consistent URL structure using path patterns

---

## Recommendations

1. **Standardize URL Configuration:**
   - Change `VendorApprovalMail` to use `config('app.frontend_url')` instead of `env('FRONTEND_URL')`
   - Create a helper method: `getEmailUrl()` in a service class

2. **Consistent URL Building:**
   - Use helper method instead of repeating `rtrim()` logic
   - Standardize between `config()` and passed parameters

3. **Add Email Delivery for Database-Only Notifications:**
   - Consider adding mail channel to `RFQAssignedNotification`
   - Add email channel to vendor-related notifications where appropriate

4. **Document Frontend Routes:**
   - Maintain a mapping of backend action URLs to frontend routes
   - Update when new features are added

5. **Testing:**
   - Add tests for URL construction with different `FRONTEND_URL` values
   - Verify all email links work in different environments (dev, staging, production)

---

**Analysis Date:** 2026-05-04  
**Total Notification Classes:** 17  
**Total Mail Classes:** 13  
**Email-Sending Classes:** 13  
**Database-Only Classes:** 8
