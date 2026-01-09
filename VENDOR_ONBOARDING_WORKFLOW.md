# Vendor Onboarding & Communication Workflow

This document illustrates the complete vendor onboarding and communication workflows implemented in the system.

---

## 1. Complete Vendor Onboarding Flow

```
┌─────────────────────────────────────────────────────────────────────────┐
│                        VENDOR ONBOARDING WORKFLOW                        │
└─────────────────────────────────────────────────────────────────────────┘

Step 1: INVITATION
┌──────────────┐
│ Procurement  │  POST /api/vendors/invite
│   Manager    │  { email, company_name }
└──────┬───────┘
       │
       ▼
┌──────────────┐
│ EmailService │  → Sends invitation email
└──────┬───────┘
       │
       ▼
┌──────────────┐
│   Vendor     │  ✉️ Receives invitation with registration link
└──────────────┘


Step 2: REGISTRATION
┌──────────────┐
│   Vendor     │  POST /api/vendors/register
│              │  { company_name, email, phone, documents, etc. }
└──────┬───────┘
       │
       ▼
┌──────────────┐
│   Database   │  → Saves VendorRegistration (status: Pending)
└──────┬───────┘
       │
       ▼
┌──────────────┐
│ Procurement  │  📋 Registration appears in pending queue
│   Manager    │  GET /api/vendors/registrations?status=Pending
└──────────────┘


Step 3: REVIEW & APPROVAL
┌──────────────┐
│ Procurement  │  Reviews registration and documents
│   Manager    │  GET /api/vendors/registrations/{id}
└──────┬───────┘
       │
       │ Decision: APPROVE
       ▼
┌──────────────┐
│   System     │  POST /api/vendors/registrations/{id}/approve
└──────┬───────┘
       │
       ├─────────────────────────────────────────────────┐
       │                                                 │
       ▼                                                 ▼
┌──────────────┐                                 ┌──────────────┐
│   Creates    │                                 │  Generates   │
│ Vendor (V001)│                                 │  User + Temp │
│              │                                 │   Password   │
└──────┬───────┘                                 └──────┬───────┘
       │                                                 │
       └───────────────────┬─────────────────────────────┘
                           ▼
                    ┌──────────────┐
                    │ EmailService │  → Sends approval email
                    └──────┬───────┘    with login credentials
                           │
                           ▼
                    ┌──────────────┐
                    │   Vendor     │  ✉️ Receives credentials
                    └──────────────┘


Step 4: FIRST LOGIN
┌──────────────┐
│   Vendor     │  POST /api/vendors/auth/login
│              │  { email, temporary_password }
└──────┬───────┘
       │
       ▼
┌──────────────┐
│   System     │  → Detects must_change_password = true
└──────┬───────┘
       │
       ▼
┌──────────────┐
│   Vendor     │  POST /api/vendors/auth/change-password
│              │  { current_password, new_password }
└──────┬───────┘
       │
       ▼
┌──────────────┐
│   System     │  ✅ Password changed, full access granted
└──────────────┘


Step 5: PROFILE MANAGEMENT
┌──────────────┐
│   Vendor     │  PUT /api/vendors/auth/profile
│              │  { phone, address, contact_person }
└──────┬───────┘
       │
       ▼
┌──────────────┐
│   System     │  ✅ Profile updated
└──────────────┘


✅ VENDOR FULLY ONBOARDED - Ready to receive RFQs and submit quotations
```

---

## 2. Password Reset Flow

```
┌─────────────────────────────────────────────────────────────────────────┐
│                         PASSWORD RESET WORKFLOW                          │
└─────────────────────────────────────────────────────────────────────────┘

Step 1: REQUEST RESET
┌──────────────┐
│   Vendor     │  POST /api/vendors/auth/password-reset
│              │  { email }
└──────┬───────┘
       │
       ▼
┌──────────────┐
│   System     │  → Validates email belongs to vendor
└──────┬───────┘
       │
       ├─── Email found ────────────┐
       │                            │
       ▼                            ▼
┌──────────────┐           ┌──────────────┐
│  Generates   │           │ EmailService │
│ Temp Password│           │              │
└──────┬───────┘           └──────┬───────┘
       │                           │
       └──────────┬────────────────┘
                  │
                  ▼
           ┌──────────────┐
           │   Vendor     │  ✉️ Receives temporary password
           └──────┬───────┘
                  │
                  ▼
           ┌──────────────┐
           │   Vendor     │  POST /api/vendors/auth/login
           │              │  (with temporary password)
           └──────┬───────┘
                  │
                  ▼
           ┌──────────────┐
           │   Vendor     │  POST /api/vendors/auth/change-password
           │              │  (forced to change)
           └──────┬───────┘
                  │
                  ▼
           ┌──────────────┐
           │   System     │  ✅ Password reset complete
           └──────────────┘

Note: Endpoint always returns success (security measure)
      to prevent email enumeration attacks
```

---

## 3. Email Service Architecture

```
┌─────────────────────────────────────────────────────────────────────────┐
│                      EMAIL SERVICE ARCHITECTURE                          │
└─────────────────────────────────────────────────────────────────────────┘

                    ┌────────────────────────────┐
                    │    EmailService.php        │
                    │  (Central Email Manager)   │
                    └─────────────┬──────────────┘
                                  │
                ┌─────────────────┼─────────────────┐
                │                 │                 │
                ▼                 ▼                 ▼
    ┌─────────────────┐ ┌─────────────────┐ ┌─────────────────┐
    │  Vendor         │ │  Password       │ │  Document       │
    │  Invitation     │ │  Reset          │ │  Expiry         │
    └────────┬────────┘ └────────┬────────┘ └────────┬────────┘
             │                   │                   │
             │                   │                   │
             ▼                   ▼                   ▼
    ┌─────────────────┐ ┌─────────────────┐ ┌─────────────────┐
    │  RFQ            │ │  Quotation      │ │  Vendor         │
    │  Notification   │ │  Status         │ │  Approval       │
    └────────┬────────┘ └────────┬────────┘ └────────┬────────┘
             │                   │                   │
             └───────────────────┼───────────────────┘
                                 │
                                 ▼
                    ┌────────────────────────────┐
                    │   Email Templates          │
                    │   (Blade Views)            │
                    ├────────────────────────────┤
                    │ • vendor-invitation.blade  │
                    │ • vendor-approval.blade    │
                    │ • password-reset.blade     │
                    │ • document-expiry.blade    │
                    │ • rfq-notification.blade   │
                    │ • quotation-status.blade   │
                    └─────────────┬──────────────┘
                                  │
                ┌─────────────────┼─────────────────┐
                │                 │                 │
                ▼                 ▼                 ▼
    ┌─────────────────┐ ┌─────────────────┐ ┌─────────────────┐
    │   SendGrid      │ │   Resend        │ │   Amazon SES    │
    │   SMTP          │ │   SMTP          │ │   API           │
    └────────┬────────┘ └────────┬────────┘ └────────┬────────┘
             │                   │                   │
             └───────────────────┼───────────────────┘
                                 │
                                 ▼
                        ┌────────────────┐
                        │  Vendor Email  │
                        │   📧 Inbox     │
                        └────────────────┘

Logging:
  ✅ Success → storage/logs/laravel.log (INFO)
  ❌ Failure → storage/logs/laravel.log (ERROR)
```

---

## 4. API Endpoint Structure

```
┌─────────────────────────────────────────────────────────────────────────┐
│                         API ENDPOINT STRUCTURE                           │
└─────────────────────────────────────────────────────────────────────────┘

PUBLIC ENDPOINTS (No Authentication)
├── POST /api/vendors/register
│   └─→ Vendor self-registration
│
├── POST /api/vendors/auth/login
│   └─→ Vendor login
│
└── POST /api/vendors/auth/password-reset
    └─→ Password reset request


AUTHENTICATED ENDPOINTS (Bearer Token Required)

ADMIN/STAFF ENDPOINTS
├── POST /api/vendors/invite
│   └─→ Send vendor invitation
│       Roles: procurement_manager, supply_chain_director, admin
│
├── GET /api/vendors/registrations
│   └─→ View pending registrations
│       Roles: procurement_manager, supply_chain_director, admin
│
├── POST /api/vendors/registrations/{id}/approve
│   └─→ Approve vendor registration
│       Roles: procurement_manager, supply_chain_director, admin
│
└── POST /api/vendors/registrations/{id}/reject
    └─→ Reject vendor registration
        Roles: procurement_manager, supply_chain_director, admin


VENDOR ENDPOINTS (Vendor Role Only)
├── GET /api/vendors/auth/profile
│   └─→ Get own profile
│
├── PUT /api/vendors/auth/profile
│   └─→ Update own profile
│       Fields: contact_person, phone, address, email
│
└── POST /api/vendors/auth/change-password
    └─→ Change password
        Requires: current_password, new_password
```

---

## 5. Data Flow Diagram

```
┌─────────────────────────────────────────────────────────────────────────┐
│                            DATA FLOW DIAGRAM                             │
└─────────────────────────────────────────────────────────────────────────┘

Frontend (Admin)                Backend                      Database
─────────────────              ──────────                   ──────────

     │                              │                            │
     │ POST /vendors/invite         │                            │
     ├──────────────────────────────>                            │
     │                              │                            │
     │                        [VendorController]                 │
     │                              │                            │
     │                              │ Check for duplicates       │
     │                              ├───────────────────────────>│
     │                              │<───────────────────────────┤
     │                              │                            │
     │                        [EmailService]                     │
     │                              │                            │
     │                         Send Email                        │
     │                              │─────────> 📧               │
     │                              │                            │
     │<─────────────────────────────┤                            │
     │ Success Response             │                            │
     │                              │                            │


Frontend (Vendor)              Backend                      Database
─────────────────              ──────────                   ──────────

     │                              │                            │
     │ PUT /vendors/auth/profile    │                            │
     ├──────────────────────────────>                            │
     │                              │                            │
     │                   [VendorAuthController]                  │
     │                              │                            │
     │                              │ Verify vendor ownership    │
     │                              ├───────────────────────────>│
     │                              │<───────────────────────────┤
     │                              │                            │
     │                              │ Update vendor record       │
     │                              ├───────────────────────────>│
     │                              │                            │
     │                              │ Update user record         │
     │                              ├───────────────────────────>│
     │                              │<───────────────────────────┤
     │<─────────────────────────────┤                            │
     │ Updated Profile              │                            │
     │                              │                            │
```

---

## 6. Security Flow

```
┌─────────────────────────────────────────────────────────────────────────┐
│                          SECURITY & AUTHORIZATION                        │
└─────────────────────────────────────────────────────────────────────────┘

Request Flow with Security Checks:

    ┌─────────────┐
    │   Request   │
    └──────┬──────┘
           │
           ▼
    ┌─────────────┐
    │   Sanctum   │  ← Check Bearer Token
    │ Middleware  │
    └──────┬──────┘
           │
           ├── Invalid Token ──> 401 Unauthorized
           │
           ▼ Valid Token
    ┌─────────────┐
    │   Load      │  ← Get authenticated user
    │    User     │
    └──────┬──────┘
           │
           ▼
    ┌─────────────┐
    │   Role      │  ← Check user role
    │   Check     │
    └──────┬──────┘
           │
           ├── Insufficient Role ──> 403 Forbidden
           │
           ▼ Authorized
    ┌─────────────┐
    │ Controller  │  ← Process request
    │   Logic     │
    └──────┬──────┘
           │
           ▼
    ┌─────────────┐
    │  Response   │
    └─────────────┘


Role-Based Access Matrix:

┌──────────────────────┬───────┬────────────────┬────────────────────┐
│ Endpoint             │ Admin │ Procurement Mgr│ Vendor             │
├──────────────────────┼───────┼────────────────┼────────────────────┤
│ POST /vendors/invite │  ✅   │      ✅        │        ❌          │
│ GET  /registrations  │  ✅   │      ✅        │        ❌          │
│ POST /{id}/approve   │  ✅   │      ✅        │        ❌          │
│ PUT  /auth/profile   │  ❌   │      ❌        │        ✅          │
│ GET  /auth/profile   │  ❌   │      ❌        │        ✅          │
└──────────────────────┴───────┴────────────────┴────────────────────┘
```

---

## 7. Email Delivery Flow

```
┌─────────────────────────────────────────────────────────────────────────┐
│                        EMAIL DELIVERY WORKFLOW                           │
└─────────────────────────────────────────────────────────────────────────┘

Application Layer              Email Provider            Recipient
─────────────────             ──────────────           ───────────

     │                              │                       │
     │ EmailService->send()         │                       │
     ├──────────────────────────────>                       │
     │                              │                       │
     │                        Load Template                 │
     │                        (Blade View)                  │
     │                              │                       │
     │                        Compile HTML                  │
     │                              │                       │
     │                        SMTP/API Call                 │
     │                              ├──────────────────────>│
     │                              │                       │
     │                              │   Deliver Email       │
     │                              │   to Inbox            │
     │                              │                       │
     │<─────────────────────────────┤                       │
     │ Delivery Status              │                       │
     │                              │                       │
     ▼                              │                       │
Log Success/Failure                 │                       │
(storage/logs/laravel.log)          │                       │
                                    │                       │


Error Handling:
  ❌ SMTP Connection Failed → Log error, return false
  ❌ Invalid Credentials    → Log error, return false
  ❌ Rate Limit Exceeded    → Log error, return false
  ✅ Email Sent             → Log success, return true
```

---

## 8. Database Schema Relationships

```
┌─────────────────────────────────────────────────────────────────────────┐
│                      DATABASE RELATIONSHIPS                              │
└─────────────────────────────────────────────────────────────────────────┘

┌────────────────────┐
│       Users        │
│────────────────────│
│ id (PK)            │
│ name               │
│ email              │
│ password           │
│ role               │
│ vendor_id (FK)     │───────┐
│ must_change_pwd    │       │
│ password_changed_at│       │
└────────────────────┘       │
                             │
                             │
┌────────────────────┐       │      ┌────────────────────┐
│ VendorRegistrations│       │      │    Vendors         │
│────────────────────│       │      │────────────────────│
│ id (PK)            │       │      │ id (PK)            │
│ company_name       │       │      │ vendor_id (UK)     │
│ email              │       └─────>│ name               │
│ phone              │              │ email              │
│ status             │───approval──>│ phone              │
│ vendor_id (FK)     │──────────────│ address            │
│ approved_by (FK)   │              │ contact_person     │
│ approved_at        │              │ category           │
└────────────────────┘              │ status             │
                                    │ rating             │
                                    │ total_orders       │
                                    └─────────┬──────────┘
                                              │
                                              │ has many
                                              ▼
                                    ┌────────────────────┐
                                    │  VendorRatings     │
                                    │────────────────────│
                                    │ id (PK)            │
                                    │ vendor_id (FK)     │
                                    │ user_id (FK)       │
                                    │ rating             │
                                    │ comment            │
                                    │ created_at         │
                                    └────────────────────┘

Relationships:
  • User belongs to Vendor (vendor_id)
  • VendorRegistration belongs to Vendor (vendor_id)
  • VendorRegistration approved_by User (approved_by)
  • Vendor has many VendorRatings
  • VendorRating belongs to Vendor and User
```

---

## 9. Complete System Integration

```
┌─────────────────────────────────────────────────────────────────────────┐
│                    COMPLETE SYSTEM INTEGRATION MAP                       │
└─────────────────────────────────────────────────────────────────────────┘

Frontend Application
┌─────────────────────────────────────────────────────────────────────────┐
│                                                                          │
│  Admin Portal                         Vendor Portal                     │
│  ─────────────                        ──────────────                    │
│                                                                          │
│  • Invite Vendors                     • View Profile                    │
│  • Review Registrations               • Update Profile                  │
│  • Approve/Reject                     • Change Password                 │
│  • View Vendor List                   • View RFQs                       │
│  • Rate Vendors                       • Submit Quotations               │
│                                                                          │
└───────────────────────────┬──────────────────────────────────────────────┘
                            │
                            │ REST API Calls
                            ▼
Backend API (Laravel)
┌─────────────────────────────────────────────────────────────────────────┐
│                                                                          │
│  Controllers                Services                    Models           │
│  ───────────                ────────                    ──────           │
│                                                                          │
│  VendorController           EmailService                Vendor           │
│  VendorAuthController       VendorApprovalService       User             │
│  AuthController             VendorDocumentService       VendorReg        │
│  MRFController                                          VendorRating     │
│  RFQController                                                           │
│  QuotationController                                                     │
│                                                                          │
└───────────────┬──────────────────────────────┬──────────────────────────┘
                │                              │
                ▼                              ▼
        ┌───────────────┐            ┌─────────────────┐
        │   Database    │            │  Email Service  │
        │   (MySQL)     │            │  (SendGrid/SES) │
        └───────────────┘            └─────────────────┘


Integration Points:
  1. Admin invites vendor → EmailService sends invitation
  2. Vendor registers → VendorRegistration created
  3. Admin approves → Vendor + User created + EmailService sends credentials
  4. Vendor logs in → Must change password
  5. Vendor updates profile → Vendor + User records synced
  6. Password reset → EmailService sends temp password
  7. RFQ assigned → EmailService notifies vendor (future)
  8. Quotation status → EmailService notifies vendor (future)
```

---

## 10. Deployment Checklist Workflow

```
┌─────────────────────────────────────────────────────────────────────────┐
│                        DEPLOYMENT WORKFLOW                               │
└─────────────────────────────────────────────────────────────────────────┘

DEVELOPMENT ENVIRONMENT
┌────────────────────────────────────────────────────────────────────────┐
│                                                                         │
│  1. [ ] Set up Mailtrap account                                        │
│  2. [ ] Configure .env with Mailtrap credentials                       │
│  3. [ ] Run: php artisan config:clear                                  │
│  4. [ ] Test email sending with tinker                                 │
│  5. [ ] Test vendor invitation API                                     │
│  6. [ ] Test vendor registration                                       │
│  7. [ ] Test vendor approval                                           │
│  8. [ ] Test vendor profile update                                     │
│  9. [ ] Test password reset                                            │
│ 10. [ ] Verify emails in Mailtrap inbox                                │
│                                                                         │
└────────────────────────────────────────────────────────────────────────┘
                              │
                              │ After successful testing
                              ▼
STAGING ENVIRONMENT
┌────────────────────────────────────────────────────────────────────────┐
│                                                                         │
│  1. [ ] Set up SendGrid account (or chosen provider)                   │
│  2. [ ] Verify sender domain                                           │
│  3. [ ] Create API key                                                 │
│  4. [ ] Configure .env with production email settings                  │
│  5. [ ] Set FRONTEND_URL to staging URL                                │
│  6. [ ] Run: php artisan config:clear                                  │
│  7. [ ] Test all email types with real delivery                        │
│  8. [ ] Check emails not going to spam                                 │
│  9. [ ] Verify email links work correctly                              │
│ 10. [ ] Load test with multiple concurrent invitations                 │
│                                                                         │
└────────────────────────────────────────────────────────────────────────┘
                              │
                              │ After successful staging tests
                              ▼
PRODUCTION ENVIRONMENT
┌────────────────────────────────────────────────────────────────────────┐
│                                                                         │
│  1. [ ] Add SPF record to DNS                                          │
│  2. [ ] Add DKIM record to DNS                                         │
│  3. [ ] Add DMARC record to DNS                                        │
│  4. [ ] Update .env with production credentials                        │
│  5. [ ] Set FRONTEND_URL to production URL                             │
│  6. [ ] Set MAIL_FROM_ADDRESS to verified domain                       │
│  7. [ ] Run: php artisan config:clear                                  │
│  8. [ ] Set up queue workers (recommended)                             │
│  9. [ ] Configure monitoring/alerts                                    │
│ 10. [ ] Test with real vendor invitation                               │
│ 11. [ ] Monitor logs for first 24 hours                                │
│ 12. [ ] Check email provider dashboard                                 │
│                                                                         │
│  ✅ PRODUCTION READY                                                   │
│                                                                         │
└────────────────────────────────────────────────────────────────────────┘
```

---

## Summary

This workflow documentation provides a comprehensive visual guide to:

1. ✅ **Complete vendor onboarding process** - From invitation to active vendor
2. ✅ **Password reset workflow** - Secure password recovery process
3. ✅ **Email service architecture** - Centralized email management
4. ✅ **API endpoint structure** - Organized endpoint hierarchy
5. ✅ **Data flow diagrams** - Request/response patterns
6. ✅ **Security flows** - Authentication and authorization
7. ✅ **Email delivery process** - From code to inbox
8. ✅ **Database relationships** - Data model structure
9. ✅ **System integration map** - How all components connect
10. ✅ **Deployment workflow** - Step-by-step deployment guide

For detailed API documentation, see:
- `VENDOR_ONBOARDING_API.md`
- `EMAIL_SETUP_GUIDE.md`
- `API_QUICK_REFERENCE.md`
- `IMPLEMENTATION_SUMMARY.md`

---

**Last Updated:** January 9, 2026  
**Version:** 1.0
