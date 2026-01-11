# Notification System - Complete Guide

**Implementation Date:** January 11, 2026  
**Status:** ✅ Complete and Integrated

---

## 📋 Table of Contents

1. [Overview](#overview)
2. [Features](#features)
3. [Architecture](#architecture)
4. [API Endpoints](#api-endpoints)
5. [Notification Types](#notification-types)
6. [Usage Examples](#usage-examples)
7. [Frontend Integration](#frontend-integration)
8. [Database Setup](#database-setup)
9. [Troubleshooting](#troubleshooting)

---

## Overview

A comprehensive, real-time notification system for your supply chain management platform. Users receive instant in-app notifications for important events like MRF submissions, vendor approvals, quotation updates, and more.

### Key Benefits

✅ **Real-time Alerts** - Users are notified instantly when important events occur  
✅ **Centralized Management** - All notifications managed through a single service  
✅ **Role-Based** - Notifications sent to appropriate user roles only  
✅ **Persistent Storage** - Notifications stored in database for history  
✅ **Read/Unread Tracking** - Track which notifications have been read  
✅ **Flexible API** - RESTful API for easy frontend integration  

---

## Features

### ✅ Core Features

- **In-App Notifications** - Database-backed notifications accessible via API
- **Notification Center** - Users can view all notifications in one place
- **Unread Count Badge** - Show count of unread notifications
- **Mark as Read** - Individual and bulk mark as read functionality
- **Delete Notifications** - Individual and bulk delete options
- **Notification Statistics** - Get counts by type, read status, etc.
- **System Announcements** - Admins can broadcast messages to all users or specific roles
- **Auto-Categorization** - Notifications automatically categorized by type with icons and colors

### 🔔 Notification Types

1. **MRF Submitted** - New material requisition form submitted
2. **MRF Approved** - Your MRF has been approved
3. **MRF Rejected** - Your MRF has been rejected
4. **RFQ Assigned** - New request for quotation assigned to vendor
5. **Quotation Submitted** - Vendor submitted a new quotation
6. **Quotation Status Updated** - Status of your quotation changed
7. **Vendor Registration** - New vendor registration received
8. **Vendor Approved** - Vendor registration approved
9. **Document Expiry** - Vendor document expiring soon or expired
10. **System Announcement** - Important system-wide messages

---

## Architecture

### Components

```
┌─────────────────────────────────────────────────────┐
│                    Frontend UI                       │
│  (Notification Bell, Dropdown, Center, Badges)      │
└─────────────────────────────────────────────────────┘
                          ↕
┌─────────────────────────────────────────────────────┐
│               API Layer (Routes)                     │
│  GET /api/notifications                              │
│  POST /api/notifications/{id}/read                   │
│  DELETE /api/notifications/{id}                      │
└─────────────────────────────────────────────────────┘
                          ↕
┌─────────────────────────────────────────────────────┐
│          NotificationController                      │
│  (Handles API requests, formats responses)           │
└─────────────────────────────────────────────────────┘
                          ↕
┌─────────────────────────────────────────────────────┐
│          NotificationService                         │
│  (Business logic, who gets notified, when)           │
└─────────────────────────────────────────────────────┘
                          ↕
┌─────────────────────────────────────────────────────┐
│       Notification Classes (Types)                   │
│  MRFSubmittedNotification, etc.                      │
└─────────────────────────────────────────────────────┘
                          ↕
┌─────────────────────────────────────────────────────┐
│      Laravel Notifications (Database)                │
│  Stores in 'notifications' table                     │
└─────────────────────────────────────────────────────┘
```

### File Structure

```
app/
├── Notifications/
│   ├── MRFSubmittedNotification.php
│   ├── MRFApprovedNotification.php
│   ├── MRFRejectedNotification.php
│   ├── RFQAssignedNotification.php
│   ├── QuotationSubmittedNotification.php
│   ├── QuotationStatusUpdatedNotification.php
│   ├── VendorRegistrationNotification.php
│   ├── VendorApprovedNotification.php
│   ├── DocumentExpiryNotification.php
│   └── SystemAnnouncementNotification.php
│
├── Services/
│   └── NotificationService.php
│
└── Http/Controllers/Api/
    └── NotificationController.php

database/migrations/
└── 2026_01_11_000000_create_notifications_table.php

routes/
└── api.php (notification routes)
```

---

## API Endpoints

All endpoints require authentication (`auth:sanctum` middleware).

### 1. Get All Notifications

**Endpoint:** `GET /api/notifications`

**Query Parameters:**
- `per_page` (integer, optional) - Number of results per page (default: 15)
- `unread_only` (boolean, optional) - Show only unread notifications (default: false)

**Response:**
```json
{
  "success": true,
  "notifications": [
    {
      "id": "9a7c8b2d-1234-5678-90ab-cdef12345678",
      "type": "App\\Notifications\\MRFSubmittedNotification",
      "data": {
        "type": "mrf_submitted",
        "title": "New MRF Submitted",
        "message": "A new Material Requisition Form (MRF-2026-001) has been submitted by John Doe",
        "mrf_id": 1,
        "mrf_number": "MRF-2026-001",
        "requester": "John Doe",
        "urgency": "High",
        "action_url": "/mrfs/1",
        "icon": "document",
        "color": "blue",
        "priority": "high"
      },
      "read_at": null,
      "created_at": "2026-01-11T10:30:00.000000Z"
    }
  ],
  "pagination": {
    "total": 25,
    "per_page": 15,
    "current_page": 1,
    "last_page": 2,
    "from": 1,
    "to": 15
  },
  "unread_count": 5
}
```

---

### 2. Get Unread Count

**Endpoint:** `GET /api/notifications/unread-count`

**Response:**
```json
{
  "success": true,
  "unread_count": 5
}
```

---

### 3. Get Single Notification

**Endpoint:** `GET /api/notifications/{id}`

**Response:**
```json
{
  "success": true,
  "notification": {
    "id": "9a7c8b2d-1234-5678-90ab-cdef12345678",
    "type": "App\\Notifications\\MRFApprovedNotification",
    "data": {
      "type": "mrf_approved",
      "title": "MRF Approved",
      "message": "Your Material Requisition Form (MRF-2026-001) has been approved by Sarah Manager",
      "mrf_id": 1,
      "mrf_number": "MRF-2026-001",
      "approver": "Sarah Manager",
      "remarks": "Approved for urgent procurement",
      "action_url": "/mrfs/1",
      "icon": "check-circle",
      "color": "green"
    },
    "read_at": "2026-01-11T11:00:00.000000Z",
    "created_at": "2026-01-11T10:45:00.000000Z"
  }
}
```

---

### 4. Mark Notification as Read

**Endpoint:** `POST /api/notifications/{id}/read`

**Response:**
```json
{
  "success": true,
  "message": "Notification marked as read",
  "notification": {
    "id": "9a7c8b2d-1234-5678-90ab-cdef12345678",
    "read_at": "2026-01-11T12:00:00.000000Z"
  }
}
```

---

### 5. Mark All as Read

**Endpoint:** `POST /api/notifications/read-all`

**Response:**
```json
{
  "success": true,
  "message": "All notifications marked as read"
}
```

---

### 6. Delete Notification

**Endpoint:** `DELETE /api/notifications/{id}`

**Response:**
```json
{
  "success": true,
  "message": "Notification deleted successfully"
}
```

---

### 7. Delete All Notifications

**Endpoint:** `DELETE /api/notifications`

**Response:**
```json
{
  "success": true,
  "message": "All notifications deleted successfully"
}
```

---

### 8. Send System Announcement (Admin Only)

**Endpoint:** `POST /api/notifications/announcement`

**Permissions:** Admin, Chairman, or Executive roles only

**Request Body:**
```json
{
  "title": "System Maintenance Scheduled",
  "message": "The system will be undergoing scheduled maintenance on January 15, 2026 from 2:00 AM to 4:00 AM UTC.",
  "roles": ["procurement_manager", "supply_chain_director", "admin"],
  "action_url": "/maintenance-notice",
  "priority": "high"
}
```

**Response:**
```json
{
  "success": true,
  "message": "System announcement sent successfully"
}
```

---

### 9. Get Notification Statistics

**Endpoint:** `GET /api/notifications/statistics`

**Response:**
```json
{
  "success": true,
  "statistics": {
    "total": 50,
    "unread": 5,
    "read": 45,
    "by_type": {
      "mrf_submitted": {
        "count": 15,
        "unread": 2
      },
      "mrf_approved": {
        "count": 10,
        "unread": 1
      },
      "quotation_submitted": {
        "count": 8,
        "unread": 2
      }
    }
  }
}
```

---

## Notification Types

### Data Structure

Each notification contains:

```typescript
interface NotificationData {
  type: string;              // e.g., "mrf_submitted"
  title: string;             // e.g., "New MRF Submitted"
  message: string;           // Human-readable message
  icon: string;              // Icon name (for frontend)
  color: string;             // Color theme (blue, green, red, yellow, purple, indigo)
  priority: string;          // "low" | "normal" | "high"
  action_url?: string;       // Optional URL to navigate to
  [key: string]: any;        // Type-specific additional fields
}
```

### 1. MRF Submitted

**Sent to:** Procurement managers, supply chain directors, admins  
**Triggered when:** Employee submits a new MRF

```json
{
  "type": "mrf_submitted",
  "title": "New MRF Submitted",
  "message": "A new Material Requisition Form (MRF-2026-001) has been submitted by John Doe",
  "mrf_id": 1,
  "mrf_number": "MRF-2026-001",
  "requester": "John Doe",
  "urgency": "High",
  "category": "Office Supplies",
  "estimated_cost": 1500.00,
  "action_url": "/mrfs/1",
  "icon": "document",
  "color": "blue",
  "priority": "high"
}
```

---

### 2. MRF Approved

**Sent to:** MRF requester  
**Triggered when:** MRF is approved by procurement/finance

```json
{
  "type": "mrf_approved",
  "title": "MRF Approved",
  "message": "Your Material Requisition Form (MRF-2026-001) has been approved by Sarah Manager",
  "mrf_id": 1,
  "mrf_number": "MRF-2026-001",
  "approver": "Sarah Manager",
  "remarks": "Approved for urgent procurement",
  "action_url": "/mrfs/1",
  "icon": "check-circle",
  "color": "green",
  "priority": "normal"
}
```

---

### 3. MRF Rejected

**Sent to:** MRF requester  
**Triggered when:** MRF is rejected

```json
{
  "type": "mrf_rejected",
  "title": "MRF Rejected",
  "message": "Your Material Requisition Form (MRF-2026-001) has been rejected by Sarah Manager",
  "mrf_id": 1,
  "mrf_number": "MRF-2026-001",
  "rejector": "Sarah Manager",
  "reason": "Budget constraints for this quarter",
  "action_url": "/mrfs/1",
  "icon": "x-circle",
  "color": "red",
  "priority": "high"
}
```

---

### 4. RFQ Assigned

**Sent to:** Vendor (user account associated with vendor)  
**Triggered when:** RFQ is assigned to vendor

```json
{
  "type": "rfq_assigned",
  "title": "New RFQ Assignment",
  "message": "You have been assigned a new Request for Quotation (RFQ-2026-001)",
  "rfq_id": 1,
  "rfq_number": "RFQ-2026-001",
  "description": "Office furniture procurement",
  "deadline": "2026-01-20",
  "action_url": "/rfqs/1",
  "icon": "clipboard-list",
  "color": "blue",
  "priority": "high"
}
```

---

### 5. Quotation Submitted

**Sent to:** Procurement managers, supply chain directors  
**Triggered when:** Vendor submits a quotation

```json
{
  "type": "quotation_submitted",
  "title": "New Quotation Received",
  "message": "A new quotation has been submitted by ABC Suppliers Ltd",
  "quotation_id": 1,
  "vendor_name": "ABC Suppliers Ltd",
  "total_amount": 5000.00,
  "currency": "USD",
  "rfq_id": 1,
  "action_url": "/quotations/1",
  "icon": "document-text",
  "color": "purple",
  "priority": "normal"
}
```

---

### 6. Quotation Status Updated

**Sent to:** Vendor who submitted the quotation  
**Triggered when:** Quotation status changes (approved, rejected, etc.)

```json
{
  "type": "quotation_status_updated",
  "title": "Quotation Status Updated",
  "message": "Your quotation status has been updated to: Approved",
  "quotation_id": 1,
  "old_status": "Under Review",
  "new_status": "Approved",
  "remarks": "Best pricing and delivery terms",
  "action_url": "/quotations/1",
  "icon": "refresh",
  "color": "green",
  "priority": "high"
}
```

---

### 7. Vendor Registration

**Sent to:** Procurement managers, supply chain directors, admins  
**Triggered when:** New vendor registers

```json
{
  "type": "vendor_registration",
  "title": "New Vendor Registration",
  "message": "A new vendor registration has been submitted by XYZ Corp",
  "registration_id": 1,
  "vendor_name": "XYZ Corp",
  "email": "contact@xyzcorp.com",
  "category": "IT Services",
  "action_url": "/vendors/registrations/1",
  "icon": "user-add",
  "color": "blue",
  "priority": "normal"
}
```

---

### 8. Vendor Approved

**Sent to:** Procurement managers, admins (internal notification)  
**Triggered when:** Vendor registration is approved

```json
{
  "type": "vendor_approved",
  "title": "Vendor Registration Approved",
  "message": "The vendor 'XYZ Corp' has been approved and credentials have been sent",
  "vendor_id": 1,
  "vendor_number": "V001",
  "vendor_name": "XYZ Corp",
  "action_url": "/vendors/1",
  "icon": "check-circle",
  "color": "green",
  "priority": "normal"
}
```

---

### 9. Document Expiry

**Sent to:** Procurement managers, vendor (if document belongs to vendor)  
**Triggered when:** Vendor document is expiring soon or has expired

```json
{
  "type": "document_expiry",
  "title": "Document Expiry Alert",
  "message": "The Business License for ABC Suppliers Ltd will expire in 7 days",
  "document_type": "Business License",
  "expiry_date": "2026-01-18",
  "vendor_name": "ABC Suppliers Ltd",
  "vendor_id": 1,
  "days_until_expiry": 7,
  "action_url": "/vendors/1",
  "icon": "exclamation-triangle",
  "color": "yellow",
  "priority": "high"
}
```

---

### 10. System Announcement

**Sent to:** All users or specific roles (configurable)  
**Triggered when:** Admin sends a system-wide announcement

```json
{
  "type": "system_announcement",
  "title": "System Maintenance Scheduled",
  "message": "The system will be undergoing scheduled maintenance on January 15, 2026 from 2:00 AM to 4:00 AM UTC. Please save your work before this time.",
  "action_url": "/maintenance-notice",
  "icon": "megaphone",
  "color": "indigo",
  "priority": "high"
}
```

---

## Usage Examples

### Backend - Sending Notifications

#### Example 1: Send MRF Submitted Notification

```php
use App\Services\NotificationService;
use App\Models\MRF;

// In your controller or service
public function store(Request $request, NotificationService $notificationService)
{
    // Create MRF
    $mrf = MRF::create([...]);
    
    // Send notification to procurement managers
    $notificationService->notifyMRFSubmitted($mrf);
    
    return response()->json([...]);
}
```

#### Example 2: Send Custom Notification to Specific Users

```php
use App\Services\NotificationService;
use App\Notifications\SystemAnnouncementNotification;

$notificationService = app(NotificationService::class);

// Send to specific users
$userIds = [1, 2, 3];
$notification = new SystemAnnouncementNotification(
    'Important Update',
    'Please review the new procurement policy',
    '/policies/procurement',
    'high'
);

$notificationService->notifyUsers($userIds, $notification);
```

#### Example 3: Send System Announcement to Roles

```php
$notificationService->sendSystemAnnouncement(
    'Holiday Schedule',
    'The office will be closed on January 20, 2026 for a public holiday.',
    ['procurement_manager', 'supply_chain_director'], // Target specific roles
    '/announcements/holiday',
    'normal'
);
```

---

### Frontend - Consuming Notifications

#### Example 1: Fetch Unread Count (for badge)

```javascript
// Fetch unread count for notification bell badge
async function fetchUnreadCount() {
  const response = await fetch('/api/notifications/unread-count', {
    headers: {
      'Authorization': `Bearer ${token}`,
      'Content-Type': 'application/json'
    }
  });
  
  const data = await response.json();
  updateBadge(data.unread_count); // Update UI badge
}

// Call every 30 seconds for real-time updates
setInterval(fetchUnreadCount, 30000);
```

#### Example 2: Fetch and Display Notifications

```javascript
async function fetchNotifications(page = 1, unreadOnly = false) {
  const url = `/api/notifications?per_page=15&unread_only=${unreadOnly}&page=${page}`;
  
  const response = await fetch(url, {
    headers: {
      'Authorization': `Bearer ${token}`,
      'Content-Type': 'application/json'
    }
  });
  
  const data = await response.json();
  
  // Render notifications
  data.notifications.forEach(notification => {
    renderNotification(notification);
  });
}

function renderNotification(notification) {
  const { data, read_at, created_at } = notification;
  
  return `
    <div class="notification ${!read_at ? 'unread' : ''}" 
         onclick="markAsRead('${notification.id}')">
      <div class="icon ${data.color}">
        <i class="${data.icon}"></i>
      </div>
      <div class="content">
        <h4>${data.title}</h4>
        <p>${data.message}</p>
        <span class="time">${formatTime(created_at)}</span>
      </div>
    </div>
  `;
}
```

#### Example 3: Mark as Read

```javascript
async function markAsRead(notificationId) {
  await fetch(`/api/notifications/${notificationId}/read`, {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${token}`,
      'Content-Type': 'application/json'
    }
  });
  
  // Update UI
  updateNotificationUI(notificationId);
  fetchUnreadCount(); // Refresh badge
}
```

#### Example 4: Mark All as Read

```javascript
async function markAllAsRead() {
  await fetch('/api/notifications/read-all', {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${token}`,
      'Content-Type': 'application/json'
    }
  });
  
  // Refresh notifications list
  fetchNotifications();
}
```

#### Example 5: Delete Notification

```javascript
async function deleteNotification(notificationId) {
  await fetch(`/api/notifications/${notificationId}`, {
    method: 'DELETE',
    headers: {
      'Authorization': `Bearer ${token}`,
      'Content-Type': 'application/json'
    }
  });
  
  // Remove from UI
  removeNotificationFromUI(notificationId);
}
```

---

## Frontend Integration

### Recommended UI Components

#### 1. Notification Bell Icon (Header)

```html
<button class="notification-bell" onclick="toggleNotificationDropdown()">
  <i class="bell-icon"></i>
  <span class="badge" id="unread-count">0</span>
</button>
```

#### 2. Notification Dropdown

```html
<div class="notification-dropdown" id="notification-dropdown" style="display: none;">
  <div class="dropdown-header">
    <h3>Notifications</h3>
    <button onclick="markAllAsRead()">Mark all as read</button>
  </div>
  
  <div class="dropdown-tabs">
    <button class="active" onclick="showAll()">All</button>
    <button onclick="showUnread()">Unread</button>
  </div>
  
  <div class="notification-list" id="notification-list">
    <!-- Notifications rendered here -->
  </div>
  
  <div class="dropdown-footer">
    <a href="/notifications">View all notifications</a>
  </div>
</div>
```

#### 3. Notification Center Page

```html
<div class="notification-center">
  <div class="center-header">
    <h1>Notification Center</h1>
    <div class="actions">
      <button onclick="markAllAsRead()">Mark all as read</button>
      <button onclick="deleteAll()">Clear all</button>
    </div>
  </div>
  
  <div class="filters">
    <select id="type-filter" onchange="filterByType()">
      <option value="all">All Types</option>
      <option value="mrf_submitted">MRF Submitted</option>
      <option value="mrf_approved">MRF Approved</option>
      <!-- More options -->
    </select>
    
    <select id="status-filter" onchange="filterByStatus()">
      <option value="all">All</option>
      <option value="unread">Unread</option>
      <option value="read">Read</option>
    </select>
  </div>
  
  <div class="notification-grid" id="notification-grid">
    <!-- Notifications rendered here -->
  </div>
  
  <div class="pagination" id="pagination">
    <!-- Pagination buttons -->
  </div>
</div>
```

### CSS Styling (Example)

```css
/* Notification Bell */
.notification-bell {
  position: relative;
  background: none;
  border: none;
  cursor: pointer;
  padding: 8px;
}

.notification-bell .badge {
  position: absolute;
  top: 0;
  right: 0;
  background: #ef4444;
  color: white;
  border-radius: 999px;
  min-width: 18px;
  height: 18px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 10px;
  font-weight: 600;
}

/* Notification Item */
.notification {
  display: flex;
  align-items: flex-start;
  padding: 12px;
  border-bottom: 1px solid #e5e7eb;
  cursor: pointer;
  transition: background-color 0.2s;
}

.notification:hover {
  background-color: #f9fafb;
}

.notification.unread {
  background-color: #eff6ff;
}

.notification .icon {
  width: 40px;
  height: 40px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  margin-right: 12px;
  flex-shrink: 0;
}

.notification .icon.blue { background-color: #dbeafe; color: #3b82f6; }
.notification .icon.green { background-color: #dcfce7; color: #22c55e; }
.notification .icon.red { background-color: #fee2e2; color: #ef4444; }
.notification .icon.yellow { background-color: #fef9c3; color: #eab308; }
.notification .icon.purple { background-color: #f3e8ff; color: #a855f7; }
.notification .icon.indigo { background-color: #e0e7ff; color: #6366f1; }

.notification .content h4 {
  font-size: 14px;
  font-weight: 600;
  margin: 0 0 4px 0;
  color: #111827;
}

.notification .content p {
  font-size: 13px;
  color: #6b7280;
  margin: 0 0 4px 0;
}

.notification .content .time {
  font-size: 12px;
  color: #9ca3af;
}
```

---

## Database Setup

### Run Migration

```bash
php artisan migrate
```

This creates the `notifications` table with the following structure:

```sql
CREATE TABLE notifications (
    id CHAR(36) PRIMARY KEY,
    type VARCHAR(255) NOT NULL,
    notifiable_type VARCHAR(255) NOT NULL,
    notifiable_id BIGINT UNSIGNED NOT NULL,
    data TEXT NOT NULL,
    read_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    
    INDEX(notifiable_type, notifiable_id),
    INDEX(read_at)
);
```

### Database Fields

- **id** - UUID primary key
- **type** - Notification class name (e.g., `App\Notifications\MRFSubmittedNotification`)
- **notifiable_type** - Model type that receives notification (e.g., `App\Models\User`)
- **notifiable_id** - ID of the model receiving notification
- **data** - JSON data containing notification details
- **read_at** - Timestamp when notification was read (NULL if unread)
- **created_at** - When notification was created
- **updated_at** - When notification was last updated

---

## Troubleshooting

### Issue 1: Notifications Not Appearing

**Symptoms:** API returns empty array or no notifications

**Possible Causes & Solutions:**

1. **User has no notifications**
   - Check if events are being triggered
   - Verify `NotificationService` is being called
   - Check logs: `tail -f storage/logs/laravel.log | grep "notification"`

2. **Database not set up**
   ```bash
   php artisan migrate
   ```

3. **Queue not running** (if using queues)
   ```bash
   php artisan queue:work
   ```

---

### Issue 2: High Unread Count

**Symptoms:** Unread count keeps growing, users not seeing new notifications

**Solutions:**

1. **Implement auto-refresh**
   ```javascript
   setInterval(fetchNotifications, 30000); // Every 30 seconds
   ```

2. **Add polling for unread count**
   ```javascript
   setInterval(fetchUnreadCount, 15000); // Every 15 seconds
   ```

3. **Consider WebSocket integration** (advanced)
   - Use Laravel Broadcasting with Pusher/Socket.io
   - Real-time notification delivery

---

### Issue 3: Notifications Sent to Wrong Users

**Symptoms:** Users receiving notifications they shouldn't

**Solutions:**

1. **Check role logic in NotificationService**
   ```php
   // Verify roles in notificationService methods
   $notifiables = User::whereIn('role', [
       'procurement_manager',
       'supply_chain_director',
       // ...
   ])->get();
   ```

2. **Check user roles in database**
   ```sql
   SELECT id, name, email, role FROM users WHERE id = ?;
   ```

---

### Issue 4: Performance Issues

**Symptoms:** Slow API responses, high database load

**Solutions:**

1. **Add pagination**
   ```javascript
   // Frontend: Implement lazy loading
   fetchNotifications(page, perPage);
   ```

2. **Add database indexes** (already included in migration)
   ```sql
   CREATE INDEX idx_notifiable ON notifications(notifiable_type, notifiable_id);
   CREATE INDEX idx_read_at ON notifications(read_at);
   ```

3. **Use queues for sending notifications**
   ```php
   // All notification classes already implement ShouldQueue
   // Just ensure queue worker is running:
   php artisan queue:work
   ```

4. **Clean up old notifications**
   ```php
   // Create a scheduled task to delete old read notifications
   // In app/Console/Kernel.php
   $schedule->call(function () {
       DB::table('notifications')
           ->whereNotNull('read_at')
           ->where('read_at', '<', now()->subDays(30))
           ->delete();
   })->daily();
   ```

---

## Best Practices

### 1. **Notification Frequency**

Don't overwhelm users with notifications:
- Group similar notifications (e.g., "5 new MRFs" instead of 5 separate notifications)
- Allow users to configure notification preferences
- Implement "Do Not Disturb" mode

### 2. **Notification Content**

- Keep messages concise and actionable
- Include relevant context (IDs, names, dates)
- Always provide an `action_url` when applicable
- Use appropriate priority levels

### 3. **Performance**

- Use queues for all notifications (`implements ShouldQueue`)
- Paginate notification lists
- Implement database cleanup for old notifications
- Add caching for unread counts if needed

### 4. **User Experience**

- Auto-refresh unread count every 15-30 seconds
- Show loading states when fetching notifications
- Implement optimistic UI updates (mark as read immediately)
- Group notifications by date (Today, Yesterday, This Week, etc.)

### 5. **Testing**

```php
// In your tests
public function test_mrf_submission_sends_notification()
{
    Notification::fake();
    
    $mrf = MRF::factory()->create();
    $notificationService = app(NotificationService::class);
    $notificationService->notifyMRFSubmitted($mrf);
    
    $procurementManagers = User::where('role', 'procurement_manager')->get();
    
    Notification::assertSentTo(
        $procurementManagers,
        MRFSubmittedNotification::class
    );
}
```

---

## Future Enhancements

### Potential Additions

1. **Real-time Push Notifications**
   - WebSocket integration with Laravel Broadcasting
   - Browser push notifications (Web Push API)
   - Mobile push notifications (FCM/APNs)

2. **Email Digests**
   - Daily/weekly summary of notifications
   - Configurable per user

3. **Notification Preferences**
   - Users can choose which notifications to receive
   - Channel preferences (in-app, email, push)
   - Do Not Disturb schedules

4. **Notification Templates**
   - Customizable notification templates
   - Internationalization (i18n) support

5. **Advanced Filtering**
   - Filter by date range
   - Filter by priority
   - Search within notifications

6. **Analytics**
   - Track notification open rates
   - Most engaged notification types
   - User notification activity

---

## Support

### Logs

Check logs for notification-related issues:

```bash
# All notification logs
tail -f storage/logs/laravel.log | grep "notification"

# Specific notification type
tail -f storage/logs/laravel.log | grep "MRF submitted notification"
```

### Debug Mode

Enable detailed logging in `.env`:

```env
APP_DEBUG=true
LOG_LEVEL=debug
```

### Common Log Messages

```
# Success
[info] MRF submitted notification sent {"mrf_id":"MRF-2026-001"}

# Warning
[warning] Failed to send approval email, but vendor was approved

# Error
[error] Failed to send MRF submitted notification {"error":"Connection refused"}
```

---

## Summary

✅ **Notifications Implemented** - 10 notification types covering all major workflows  
✅ **API Complete** - 9 RESTful endpoints for notification management  
✅ **Auto-Triggered** - Notifications sent automatically on important events  
✅ **Role-Based** - Only relevant users receive notifications  
✅ **Production-Ready** - Queued, indexed, and optimized  

**Next Steps:**
1. Run migration: `php artisan migrate`
2. Start queue worker: `php artisan queue:work`
3. Test notification endpoints
4. Integrate frontend UI
5. Monitor logs and performance

---

**Last Updated:** January 11, 2026  
**Version:** 1.0  
**Status:** ✅ Complete
