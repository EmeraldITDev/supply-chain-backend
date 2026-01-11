# Notifications System - Quick Start Guide

## 🚀 Get Started in 5 Minutes

### Step 1: Run Migration (Required)

```bash
php artisan migrate
```

This creates the `notifications` table in your database.

---

### Step 2: Start Queue Worker (Recommended)

```bash
php artisan queue:work
```

This processes notifications in the background for better performance.

> **Tip:** Use a process manager like Supervisor to keep the queue worker running in production.

---

### Step 3: Test the API

#### Get Unread Count

```bash
curl -X GET "http://localhost:8000/api/notifications/unread-count" \
  -H "Authorization: Bearer YOUR_AUTH_TOKEN"
```

**Expected Response:**
```json
{
  "success": true,
  "unread_count": 0
}
```

#### Get All Notifications

```bash
curl -X GET "http://localhost:8000/api/notifications" \
  -H "Authorization: Bearer YOUR_AUTH_TOKEN"
```

**Expected Response:**
```json
{
  "success": true,
  "notifications": [],
  "pagination": { ... },
  "unread_count": 0
}
```

---

### Step 4: Create a Test Notification

```bash
php artisan tinker
```

```php
// Get a user
$user = App\Models\User::first();

// Send a test notification
$user->notify(new App\Notifications\SystemAnnouncementNotification(
    'Welcome!',
    'Your notification system is working!',
    null,
    'normal'
));

// Check the notification
$user->notifications;
```

Exit tinker: `exit`

---

### Step 5: Verify It Works

```bash
curl -X GET "http://localhost:8000/api/notifications" \
  -H "Authorization: Bearer YOUR_AUTH_TOKEN"
```

You should now see your test notification! 🎉

---

## 📱 Frontend Integration (5 Minutes)

### 1. Fetch Unread Count

```javascript
async function getUnreadCount() {
  const response = await fetch('/api/notifications/unread-count', {
    headers: {
      'Authorization': `Bearer ${yourAuthToken}`,
      'Content-Type': 'application/json'
    }
  });
  const data = await response.json();
  return data.unread_count;
}

// Update badge every 30 seconds
setInterval(async () => {
  const count = await getUnreadCount();
  document.getElementById('notification-badge').textContent = count;
}, 30000);
```

### 2. Display Notifications

```javascript
async function fetchNotifications() {
  const response = await fetch('/api/notifications?per_page=15', {
    headers: {
      'Authorization': `Bearer ${yourAuthToken}`,
      'Content-Type': 'application/json'
    }
  });
  const data = await response.json();
  
  const list = document.getElementById('notification-list');
  list.innerHTML = data.notifications.map(n => `
    <div class="notification ${n.read_at ? '' : 'unread'}" 
         onclick="markAsRead('${n.id}')">
      <h4>${n.data.title}</h4>
      <p>${n.data.message}</p>
      <small>${new Date(n.created_at).toLocaleString()}</small>
    </div>
  `).join('');
}
```

### 3. Mark as Read

```javascript
async function markAsRead(notificationId) {
  await fetch(`/api/notifications/${notificationId}/read`, {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${yourAuthToken}`,
      'Content-Type': 'application/json'
    }
  });
  
  // Refresh the list
  fetchNotifications();
}
```

### 4. Simple HTML Structure

```html
<!-- Notification Bell -->
<button onclick="toggleNotifications()">
  🔔
  <span id="notification-badge">0</span>
</button>

<!-- Notification Dropdown -->
<div id="notification-dropdown" style="display: none;">
  <div id="notification-list">
    <!-- Notifications will appear here -->
  </div>
</div>

<script>
  // Show/hide dropdown
  function toggleNotifications() {
    const dropdown = document.getElementById('notification-dropdown');
    dropdown.style.display = dropdown.style.display === 'none' ? 'block' : 'none';
    fetchNotifications();
  }
  
  // Initialize
  getUnreadCount();
  setInterval(getUnreadCount, 30000);
</script>
```

---

## 🎯 What's Already Working

### Automatic Notifications

These notifications are **already integrated** and will be sent automatically:

✅ **MRF Submitted** → Procurement managers notified when employee submits MRF  
✅ **MRF Approved** → Employee notified when their MRF is approved  
✅ **MRF Rejected** → Employee notified when their MRF is rejected  
✅ **Vendor Registration** → Procurement managers notified of new vendor registrations  
✅ **Vendor Approved** → Procurement team notified when vendor is approved  

### Ready to Use (Just Call the Service)

These are ready but need to be integrated into your workflows:

- RFQ Assignment → Vendor notified
- Quotation Submitted → Procurement notified
- Quotation Status Changed → Vendor notified
- Document Expiry → Procurement & vendor notified
- System Announcements → All users or specific roles

---

## 🔧 Common Tasks

### Send System Announcement (Admin)

```bash
curl -X POST "http://localhost:8000/api/notifications/announcement" \
  -H "Authorization: Bearer ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "System Maintenance",
    "message": "Scheduled maintenance tonight at 10 PM",
    "roles": ["procurement_manager", "admin"],
    "priority": "high"
  }'
```

### Get Unread Notifications Only

```bash
curl -X GET "http://localhost:8000/api/notifications?unread_only=true" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

### Mark All as Read

```bash
curl -X POST "http://localhost:8000/api/notifications/read-all" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

### Delete a Notification

```bash
curl -X DELETE "http://localhost:8000/api/notifications/{notification-id}" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

### Get Statistics

```bash
curl -X GET "http://localhost:8000/api/notifications/statistics" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

---

## 🐛 Troubleshooting

### "No notifications appearing"

1. Check if migration ran: `php artisan migrate:status`
2. Check if queue is running: `php artisan queue:work`
3. Check logs: `tail -f storage/logs/laravel.log | grep notification`

### "Queue not working"

```bash
# Make sure queue driver is set in .env
QUEUE_CONNECTION=database

# Run queue migrations
php artisan queue:table
php artisan migrate

# Start worker
php artisan queue:work
```

### "401 Unauthorized"

Make sure you're sending the correct auth token in the `Authorization` header:

```
Authorization: Bearer YOUR_TOKEN_HERE
```

---

## 📚 Full Documentation

For complete details, see:

- **`NOTIFICATIONS_SYSTEM_GUIDE.md`** - Complete API reference, all notification types, troubleshooting
- **`NOTIFICATIONS_IMPLEMENTATION_SUMMARY.md`** - Technical implementation details

---

## ✅ Checklist

- [ ] Run migration: `php artisan migrate`
- [ ] Start queue worker: `php artisan queue:work`
- [ ] Test API endpoint: GET `/api/notifications/unread-count`
- [ ] Create test notification in tinker
- [ ] Verify notification appears via API
- [ ] Implement frontend notification bell
- [ ] Implement notification dropdown
- [ ] Set up auto-refresh polling (30s interval)
- [ ] Style notifications with colors/icons
- [ ] Test mark as read functionality

---

## 🎉 You're All Set!

Your notification system is **ready to use**. Notifications will be sent automatically when:

- ✅ Employees submit MRFs
- ✅ MRFs are approved/rejected
- ✅ Vendors register
- ✅ Vendors are approved

**Next:** Build your frontend UI to display these notifications!

---

**Need Help?** Check `NOTIFICATIONS_SYSTEM_GUIDE.md` for detailed documentation.
