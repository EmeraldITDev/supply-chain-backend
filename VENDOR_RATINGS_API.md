# Vendor Ratings & Comments API

## ✅ Features Implemented

1. **Add Rating/Comment** - POST endpoint to rate vendors with comments
2. **Get Comments** - GET endpoint to retrieve all vendor ratings/comments
3. **Automatic Average Calculation** - Vendor rating auto-updates with each new rating

---

## 📊 Database Schema

### vendor_ratings Table

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint | Primary key |
| `vendor_id` | bigint | Foreign key to vendors table |
| `user_id` | bigint | Foreign key to users table (who rated) |
| `rating` | decimal(2,1) | Rating from 1.0 to 5.0 |
| `comment` | text | Optional comment |
| `created_at` | timestamp | When rating was created |
| `updated_at` | timestamp | When rating was updated |

**Indexes:**
- `vendor_id` - Fast lookups by vendor
- `user_id` - Fast lookups by user
- `created_at` - Ordered retrieval

---

## 📡 API Documentation

### 1. Add Rating/Comment

**Endpoint:** `POST /api/vendors/{id}/rating`

**Authorization:** Required (any authenticated user)

**Request Body:**
```json
{
  "rating": 4.5,
  "comment": "Excellent service and on-time delivery. Very professional vendor."
}
```

**Validation Rules:**
- `rating`: Required, numeric, between 1 and 5
- `comment`: Optional, string, max 1000 characters

**Success Response (200 OK):**
```json
{
  "success": true,
  "message": "Rating added successfully",
  "rating": 4.3,
  "comments": [
    {
      "id": 1,
      "comment": "Excellent service and on-time delivery. Very professional vendor.",
      "rating": 4.5,
      "createdAt": "2026-01-09T10:30:00Z",
      "createdBy": {
        "id": 123,
        "name": "John Manager",
        "email": "john@company.com"
      }
    },
    {
      "id": 2,
      "comment": "Good quality products",
      "rating": 4.0,
      "createdAt": "2026-01-08T15:20:00Z",
      "createdBy": {
        "id": 124,
        "name": "Jane Procurement",
        "email": "jane@company.com"
      }
    }
  ]
}
```

**Features:**
- ✅ Automatically updates vendor average rating
- ✅ Returns updated average rating
- ✅ Returns all comments ordered by newest first
- ✅ Includes user info for each comment

---

### 2. Get Vendor Comments

**Endpoint:** `GET /api/vendors/{id}/comments`

**Authorization:** Required (any authenticated user)

**Success Response (200 OK):**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "comment": "Excellent service and on-time delivery. Very professional vendor.",
      "rating": 4.5,
      "createdAt": "2026-01-09T10:30:00Z",
      "createdBy": {
        "id": 123,
        "name": "John Manager",
        "email": "john@company.com"
      }
    },
    {
      "id": 2,
      "comment": "Good quality products",
      "rating": 4.0,
      "createdAt": "2026-01-08T15:20:00Z",
      "createdBy": {
        "id": 124,
        "name": "Jane Procurement",
        "email": "jane@company.com"
      }
    }
  ],
  "vendorRating": 4.25,
  "totalComments": 2
}
```

**Features:**
- ✅ Returns all ratings/comments for vendor
- ✅ Ordered by newest first
- ✅ Includes vendor's current average rating
- ✅ Includes total comment count
- ✅ Full user details for each rating

---

## 🧪 Testing Guide

### Test 1: Add Rating with Comment

```bash
curl -X POST http://localhost:8000/api/vendors/V001/rating \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "rating": 4.5,
    "comment": "Excellent service and timely delivery"
  }'
```

**Expected:**
- Status: 200 OK
- Returns updated average rating
- Returns all comments including new one

### Test 2: Add Rating Without Comment

```bash
curl -X POST http://localhost:8000/api/vendors/5/rating \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "rating": 5
  }'
```

**Expected:**
- Status: 200 OK
- Comment field is null
- Rating recorded successfully

### Test 3: Invalid Rating (Out of Range)

```bash
curl -X POST http://localhost:8000/api/vendors/V001/rating \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "rating": 6,
    "comment": "Too high rating"
  }'
```

**Expected Response (422):**
```json
{
  "success": false,
  "error": "Validation failed",
  "errors": {
    "rating": ["The rating field must be between 1 and 5."]
  },
  "code": "VALIDATION_ERROR"
}
```

### Test 4: Get All Comments

```bash
curl -X GET http://localhost:8000/api/vendors/V001/comments \
  -H "Authorization: Bearer YOUR_TOKEN"
```

**Expected:**
- Status: 200 OK
- Returns array of all comments
- Includes vendor average rating
- Includes total count

### Test 5: Get Comments for Vendor with No Ratings

```bash
curl -X GET http://localhost:8000/api/vendors/V999/comments \
  -H "Authorization: Bearer YOUR_TOKEN"
```

**Expected Response:**
```json
{
  "success": true,
  "data": [],
  "vendorRating": 0,
  "totalComments": 0
}
```

---

## 🔄 Rating Calculation

### How Average is Calculated

```sql
-- Automatically calculated when rating is added
AVG(rating) FROM vendor_ratings WHERE vendor_id = ?

-- Example:
-- Rating 1: 4.5
-- Rating 2: 4.0
-- Rating 3: 5.0
-- Average: (4.5 + 4.0 + 5.0) / 3 = 4.5
```

### Vendor Rating Update

```php
// After each new rating
$avgRating = VendorRating::where('vendor_id', $vendor->id)->avg('rating');
$vendor->update(['rating' => round($avgRating, 2)]);
```

**Result:** Vendor's `rating` column is always up-to-date.

---

## 🎯 Frontend Integration

### Add Rating Component

```javascript
async function addVendorRating(vendorId, rating, comment) {
  try {
    const response = await fetch(`/api/vendors/${vendorId}/rating`, {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${token}`,
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({ rating, comment })
    });
    
    const result = await response.json();
    
    if (result.success) {
      // Show success message
      showNotification(`Rating added! New average: ${result.rating}`);
      
      // Update UI with new comments
      updateCommentsList(result.comments);
      
      // Update vendor rating display
      updateVendorRating(result.rating);
    }
  } catch (error) {
    showError('Failed to add rating');
  }
}
```

### Rating Form Example (React)

```jsx
function VendorRatingForm({ vendorId }) {
  const [rating, setRating] = useState(5);
  const [comment, setComment] = useState('');
  
  const handleSubmit = async (e) => {
    e.preventDefault();
    await addVendorRating(vendorId, rating, comment);
  };
  
  return (
    <form onSubmit={handleSubmit}>
      <label>
        Rating (1-5):
        <input 
          type="number" 
          min="1" 
          max="5" 
          step="0.5"
          value={rating}
          onChange={(e) => setRating(parseFloat(e.target.value))}
        />
      </label>
      
      <label>
        Comment:
        <textarea 
          value={comment}
          onChange={(e) => setComment(e.target.value)}
          maxLength={1000}
          placeholder="Share your experience with this vendor..."
        />
      </label>
      
      <button type="submit">Submit Rating</button>
    </form>
  );
}
```

### Display Comments Component

```jsx
function VendorComments({ vendorId }) {
  const [comments, setComments] = useState([]);
  const [loading, setLoading] = useState(true);
  
  useEffect(() => {
    loadComments();
  }, [vendorId]);
  
  const loadComments = async () => {
    const response = await fetch(`/api/vendors/${vendorId}/comments`, {
      headers: { 'Authorization': `Bearer ${token}` }
    });
    const result = await response.json();
    
    if (result.success) {
      setComments(result.data);
    }
    setLoading(false);
  };
  
  return (
    <div>
      <h3>Vendor Reviews</h3>
      {comments.map(comment => (
        <div key={comment.id} className="comment">
          <div className="rating">★ {comment.rating}</div>
          <p>{comment.comment}</p>
          <div className="meta">
            By {comment.createdBy.name} on {formatDate(comment.createdAt)}
          </div>
        </div>
      ))}
    </div>
  );
}
```

---

## 🔒 Security & Permissions

### Who Can Rate?

**Current:** Any authenticated user can rate any vendor

**Optional Enhancements:**
- Restrict to users who have worked with the vendor
- Restrict to specific roles (procurement managers, etc.)
- Allow one rating per user per vendor

### Implement One Rating Per User (Optional)

**Update Migration:**
```php
// In create_vendor_ratings_table migration
$table->unique(['vendor_id', 'user_id']); // Uncomment this line
```

**Handle Duplicate Rating:**
```php
// In controller, update instead of create if rating exists
$rating = VendorRating::updateOrCreate(
    ['vendor_id' => $vendor->id, 'user_id' => $user->id],
    ['rating' => $request->rating, 'comment' => $request->comment]
);
```

---

## 📊 Response Examples

### After Adding First Rating

**Request:**
```json
POST /api/vendors/V001/rating
{
  "rating": 4.5,
  "comment": "Great vendor"
}
```

**Response:**
```json
{
  "success": true,
  "message": "Rating added successfully",
  "rating": 4.5,  // Average is 4.5 (only one rating)
  "comments": [
    {
      "id": 1,
      "comment": "Great vendor",
      "rating": 4.5,
      "createdAt": "2026-01-09T10:00:00Z",
      "createdBy": {...}
    }
  ]
}
```

### After Adding Multiple Ratings

**Request:**
```json
POST /api/vendors/V001/rating
{
  "rating": 5.0,
  "comment": "Excellent!"
}
```

**Response:**
```json
{
  "success": true,
  "message": "Rating added successfully",
  "rating": 4.75,  // Average of 4.5 and 5.0
  "comments": [
    {
      "id": 2,
      "comment": "Excellent!",
      "rating": 5.0,
      "createdAt": "2026-01-09T11:00:00Z",
      "createdBy": {...}
    },
    {
      "id": 1,
      "comment": "Great vendor",
      "rating": 4.5,
      "createdAt": "2026-01-09T10:00:00Z",
      "createdBy": {...}
    }
  ]
}
```

---

## 🔍 Database Queries

### Get Vendor Average Rating

```sql
SELECT AVG(rating) as avg_rating, COUNT(*) as total_ratings
FROM vendor_ratings
WHERE vendor_id = 1;
```

### Get Recent Ratings

```sql
SELECT vr.*, u.name as user_name, u.email as user_email
FROM vendor_ratings vr
JOIN users u ON u.id = vr.user_id
WHERE vr.vendor_id = 1
ORDER BY vr.created_at DESC
LIMIT 10;
```

### Get Top Rated Vendors

```sql
SELECT v.vendor_id, v.name, v.rating, COUNT(vr.id) as total_ratings
FROM vendors v
LEFT JOIN vendor_ratings vr ON vr.vendor_id = v.id
GROUP BY v.id
ORDER BY v.rating DESC, total_ratings DESC
LIMIT 10;
```

---

## 🚀 Deployment Checklist

- [x] Migration created: `2026_01_09_000000_create_vendor_ratings_table.php`
- [x] Model created: `VendorRating.php`
- [x] Vendor model updated with `ratings()` relationship
- [x] Controller methods added: `addRating()`, `getComments()`
- [x] Routes registered: POST rating, GET comments
- [x] Automatic rating calculation implemented
- [x] Validation rules in place
- [x] No linting errors
- [ ] Run migration: `php artisan migrate`
- [ ] Test add rating endpoint
- [ ] Test get comments endpoint
- [ ] Verify average calculation
- [ ] Frontend integration

---

## 📝 Migration Instructions

### Run Migration

```bash
php artisan migrate
```

### Rollback (if needed)

```bash
php artisan migrate:rollback
```

### Check Migration Status

```bash
php artisan migrate:status
```

---

## 🐛 Troubleshooting

### Issue: "vendor_ratings table not found"

**Solution:** Run migration
```bash
php artisan migrate
```

### Issue: "SQLSTATE foreign key constraint"

**Cause:** Trying to rate vendor that doesn't exist

**Fix:** Verify vendor exists in database

### Issue: Rating not updating

**Cause:** Calculation issue

**Fix:** Manually recalculate
```sql
UPDATE vendors v
SET rating = (
  SELECT AVG(rating) 
  FROM vendor_ratings 
  WHERE vendor_id = v.id
);
```

---

## 🎯 Features Summary

| Feature | Status |
|---------|--------|
| Add rating with comment | ✅ |
| Add rating without comment | ✅ |
| Get all comments | ✅ |
| Auto-calculate average | ✅ |
| Rating validation (1-5) | ✅ |
| Comment length validation | ✅ |
| User info in responses | ✅ |
| Ordered by newest first | ✅ |
| Works with both ID types | ✅ |
| Authorization required | ✅ |

---

*Implementation Date: January 9, 2026*
*Status: ✅ Complete - Ready for Migration & Testing*
