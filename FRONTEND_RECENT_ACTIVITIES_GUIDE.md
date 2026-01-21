# Frontend Integration Guide: Recent Activities

## API Endpoint

**URL:** `GET /api/dashboard/recent-activities`

**Authentication:** Required (Bearer token via Sanctum)

**Query Parameters:**
- `limit` (optional): Number of activities to return (default: 20, max recommended: 50)

## Request Example

```javascript
// Using fetch
const fetchRecentActivities = async (limit = 20) => {
  try {
    const response = await fetch(
      `${API_BASE_URL}/api/dashboard/recent-activities?limit=${limit}`,
      {
        method: 'GET',
        headers: {
          'Authorization': `Bearer ${authToken}`,
          'Content-Type': 'application/json',
          'Accept': 'application/json'
        }
      }
    );

    if (!response.ok) {
      throw new Error(`HTTP error! status: ${response.status}`);
    }

    const data = await response.json();
    return data;
  } catch (error) {
    console.error('Error fetching recent activities:', error);
    throw error;
  }
};
```

## Response Format

```json
{
  "success": true,
  "data": [
    {
      "id": "1",
      "type": "mrf_created",
      "title": "MRF Created",
      "description": "MRF MRF-2026-001 was created by John Doe",
      "timestamp": "2026-01-20T10:30:00Z",
      "user": "John Doe",
      "entityId": "MRF-2026-001",
      "entityType": "mrf",
      "status": "pending"
    },
    {
      "id": "2",
      "type": "mrf_approved",
      "title": "MRF Approved by Procurement",
      "description": "MRF MRF-2026-001 was approved by Jane Smith and forwarded to Executive",
      "timestamp": "2026-01-20T11:15:00Z",
      "user": "Jane Smith",
      "entityId": "MRF-2026-001",
      "entityType": "mrf",
      "status": "approved"
    },
    {
      "id": "3",
      "type": "quotation_submitted",
      "title": "Quotation Submitted",
      "description": "Quotation QUO-2026-001 submitted by Vendor ABC for RFQ RFQ-2026-001",
      "timestamp": "2026-01-20T12:00:00Z",
      "user": "Vendor ABC",
      "entityId": "QUO-2026-001",
      "entityType": "quotation",
      "status": "pending"
    }
  ]
}
```

## Activity Types

The endpoint returns various activity types:

- `mrf_created` - MRF was created/submitted
- `mrf_approved` - MRF was approved (by procurement, executive, or chairman)
- `mrf_rejected` - MRF was rejected
- `rfq_sent` - RFQ was sent to vendors
- `quotation_submitted` - Vendor submitted a quotation
- `quotation_approved` - Quotation was approved
- `quotation_rejected` - Quotation was rejected
- `quotation_closed` - Quotation was closed
- `quotation_reopened` - Quotation was reopened
- `vendor_selected` - Vendor was selected by procurement
- `vendor_approved` - Vendor selection was approved by SCD
- `vendor_rejected` - Vendor selection was rejected
- `po_generated` - Purchase Order was generated
- `signed_po` - PO was signed/uploaded
- `payment_processed` - Payment was processed
- `payment_approved` - Payment was approved

## React/Next.js Integration Example

### 1. Create a Custom Hook

```javascript
// hooks/useRecentActivities.js
import { useState, useEffect } from 'react';
import { useAuth } from './useAuth'; // Your auth hook

export const useRecentActivities = (limit = 20) => {
  const [activities, setActivities] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const { token } = useAuth();

  useEffect(() => {
    const fetchActivities = async () => {
      if (!token) {
        setLoading(false);
        return;
      }

      try {
        setLoading(true);
        setError(null);

        const response = await fetch(
          `${process.env.NEXT_PUBLIC_API_URL}/api/dashboard/recent-activities?limit=${limit}`,
          {
            headers: {
              'Authorization': `Bearer ${token}`,
              'Content-Type': 'application/json',
              'Accept': 'application/json'
            }
          }
        );

        if (!response.ok) {
          throw new Error(`Failed to fetch activities: ${response.status}`);
        }

        const data = await response.json();
        
        if (data.success) {
          setActivities(data.data || []);
        } else {
          throw new Error(data.error || 'Failed to fetch activities');
        }
      } catch (err) {
        console.error('Error fetching recent activities:', err);
        setError(err.message);
        setActivities([]);
      } finally {
        setLoading(false);
      }
    };

    fetchActivities();

    // Optionally refresh every 30 seconds
    const interval = setInterval(fetchActivities, 30000);
    return () => clearInterval(interval);
  }, [token, limit]);

  const refresh = async () => {
    // Manual refresh function
    if (!token) return;
    
    try {
      const response = await fetch(
        `${process.env.NEXT_PUBLIC_API_URL}/api/dashboard/recent-activities?limit=${limit}`,
        {
          headers: {
            'Authorization': `Bearer ${token}`,
            'Content-Type': 'application/json',
            'Accept': 'application/json'
          }
        }
      );

      if (response.ok) {
        const data = await response.json();
        if (data.success) {
          setActivities(data.data || []);
        }
      }
    } catch (err) {
      console.error('Error refreshing activities:', err);
    }
  };

  return { activities, loading, error, refresh };
};
```

### 2. Create a Recent Activities Component

```jsx
// components/RecentActivities.jsx
import React from 'react';
import { useRecentActivities } from '../hooks/useRecentActivities';
import { formatDistanceToNow } from 'date-fns';

const RecentActivities = ({ limit = 10 }) => {
  const { activities, loading, error, refresh } = useRecentActivities(limit);

  const getActivityIcon = (type) => {
    const icons = {
      'mrf_created': '📝',
      'mrf_approved': '✅',
      'mrf_rejected': '❌',
      'rfq_sent': '📤',
      'quotation_submitted': '📋',
      'quotation_approved': '✅',
      'quotation_rejected': '❌',
      'vendor_selected': '👤',
      'po_generated': '📄',
      'payment_processed': '💰',
    };
    return icons[type] || '📌';
  };

  const getActivityColor = (type) => {
    if (type.includes('approved') || type.includes('created')) {
      return 'text-green-600';
    }
    if (type.includes('rejected')) {
      return 'text-red-600';
    }
    return 'text-blue-600';
  };

  if (loading) {
    return (
      <div className="bg-white rounded-lg shadow p-6">
        <div className="flex items-center justify-between mb-4">
          <h2 className="text-xl font-semibold">Recent Activities</h2>
        </div>
        <div className="space-y-3">
          {[...Array(limit)].map((_, i) => (
            <div key={i} className="animate-pulse">
              <div className="h-4 bg-gray-200 rounded w-3/4"></div>
              <div className="h-3 bg-gray-100 rounded w-1/2 mt-2"></div>
            </div>
          ))}
        </div>
      </div>
    );
  }

  if (error) {
    return (
      <div className="bg-white rounded-lg shadow p-6">
        <div className="flex items-center justify-between mb-4">
          <h2 className="text-xl font-semibold">Recent Activities</h2>
          <button
            onClick={refresh}
            className="text-sm text-blue-600 hover:text-blue-800"
          >
            Retry
          </button>
        </div>
        <div className="text-red-600 text-sm">{error}</div>
      </div>
    );
  }

  if (activities.length === 0) {
    return (
      <div className="bg-white rounded-lg shadow p-6">
        <div className="flex items-center justify-between mb-4">
          <h2 className="text-xl font-semibold">Recent Activities</h2>
        </div>
        <div className="text-gray-500 text-center py-8">
          <p>No recent activities</p>
          <p className="text-sm mt-2">Activities will appear here as you use the system</p>
        </div>
      </div>
    );
  }

  return (
    <div className="bg-white rounded-lg shadow p-6">
      <div className="flex items-center justify-between mb-4">
        <h2 className="text-xl font-semibold">Recent Activities</h2>
        <button
          onClick={refresh}
          className="text-sm text-blue-600 hover:text-blue-800"
          title="Refresh activities"
        >
          🔄 Refresh
        </button>
      </div>
      
      <div className="space-y-4">
        {activities.map((activity) => (
          <div
            key={activity.id}
            className="flex items-start space-x-3 p-3 hover:bg-gray-50 rounded-lg transition-colors"
          >
            <div className="text-2xl flex-shrink-0">
              {getActivityIcon(activity.type)}
            </div>
            <div className="flex-1 min-w-0">
              <div className="flex items-center justify-between">
                <p className={`text-sm font-medium ${getActivityColor(activity.type)}`}>
                  {activity.title}
                </p>
                <span className="text-xs text-gray-500 flex-shrink-0 ml-2">
                  {formatDistanceToNow(new Date(activity.timestamp), { addSuffix: true })}
                </span>
              </div>
              <p className="text-sm text-gray-600 mt-1">
                {activity.description}
              </p>
              <div className="flex items-center space-x-2 mt-2">
                <span className="text-xs text-gray-500">
                  by {activity.user}
                </span>
                {activity.entityId && (
                  <span className="text-xs bg-gray-100 px-2 py-1 rounded">
                    {activity.entityId}
                  </span>
                )}
              </div>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
};

export default RecentActivities;
```

### 3. Use in Dashboard

```jsx
// pages/Dashboard.jsx or components/Dashboard.jsx
import React from 'react';
import RecentActivities from '../components/RecentActivities';

const Dashboard = () => {
  return (
    <div className="container mx-auto px-4 py-8">
      <h1 className="text-2xl font-bold mb-6">Dashboard</h1>
      
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {/* Main content */}
        <div className="lg:col-span-2">
          {/* Your main dashboard content */}
        </div>
        
        {/* Sidebar with Recent Activities */}
        <div className="lg:col-span-1">
          <RecentActivities limit={10} />
        </div>
      </div>
    </div>
  );
};

export default Dashboard;
```

## Axios Integration Example

```javascript
// services/activityService.js
import axios from 'axios';

const API_BASE_URL = process.env.NEXT_PUBLIC_API_URL || 'http://localhost:8000';

export const activityService = {
  getRecentActivities: async (limit = 20, token) => {
    try {
      const response = await axios.get(
        `${API_BASE_URL}/api/dashboard/recent-activities`,
        {
          params: { limit },
          headers: {
            'Authorization': `Bearer ${token}`,
            'Accept': 'application/json'
          }
        }
      );
      
      return response.data;
    } catch (error) {
      console.error('Error fetching recent activities:', error);
      throw error;
    }
  }
};
```

## Vue.js Integration Example

```vue
<template>
  <div class="recent-activities">
    <div class="header">
      <h2>Recent Activities</h2>
      <button @click="refresh" :disabled="loading">🔄</button>
    </div>
    
    <div v-if="loading" class="loading">
      Loading activities...
    </div>
    
    <div v-else-if="error" class="error">
      {{ error }}
    </div>
    
    <div v-else-if="activities.length === 0" class="empty">
      No recent activities
    </div>
    
    <div v-else class="activities-list">
      <div
        v-for="activity in activities"
        :key="activity.id"
        class="activity-item"
      >
        <div class="activity-icon">{{ getIcon(activity.type) }}</div>
        <div class="activity-content">
          <div class="activity-title">{{ activity.title }}</div>
          <div class="activity-description">{{ activity.description }}</div>
          <div class="activity-meta">
            <span>{{ activity.user }}</span>
            <span>{{ formatTime(activity.timestamp) }}</span>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import { ref, onMounted } from 'vue';
import axios from 'axios';

export default {
  name: 'RecentActivities',
  setup() {
    const activities = ref([]);
    const loading = ref(true);
    const error = ref(null);
    const token = localStorage.getItem('auth_token');

    const fetchActivities = async () => {
      try {
        loading.value = true;
        error.value = null;
        
        const response = await axios.get(
          `${process.env.VUE_APP_API_URL}/api/dashboard/recent-activities`,
          {
            params: { limit: 10 },
            headers: {
              'Authorization': `Bearer ${token}`,
              'Accept': 'application/json'
            }
          }
        );
        
        if (response.data.success) {
          activities.value = response.data.data || [];
        }
      } catch (err) {
        error.value = err.message;
        console.error('Error fetching activities:', err);
      } finally {
        loading.value = false;
      }
    };

    const refresh = () => {
      fetchActivities();
    };

    const getIcon = (type) => {
      const icons = {
        'mrf_created': '📝',
        'mrf_approved': '✅',
        'quotation_submitted': '📋',
      };
      return icons[type] || '📌';
    };

    const formatTime = (timestamp) => {
      return new Date(timestamp).toLocaleString();
    };

    onMounted(() => {
      fetchActivities();
      // Refresh every 30 seconds
      setInterval(fetchActivities, 30000);
    });

    return {
      activities,
      loading,
      error,
      refresh,
      getIcon,
      formatTime
    };
  }
};
</script>
```

## Important Notes

1. **Authentication Required**: The endpoint requires a valid Bearer token. Make sure to include it in the Authorization header.

2. **User-Specific Data**: The endpoint automatically filters activities based on the authenticated user:
   - Activities performed by the user
   - Activities related to MRFs the user created
   - Activities related to vendor quotations (for vendors)

3. **No Role Parameter Needed**: The endpoint automatically determines what activities to show based on the authenticated user's role and relationships.

4. **Empty State**: If there are no activities, the endpoint returns an empty array `[]`. Handle this gracefully in your UI.

5. **Error Handling**: Always handle errors gracefully. The endpoint may return:
   - `401 Unauthorized` - Invalid or missing token
   - `500 Internal Server Error` - Server error
   - Empty array if no activities exist

6. **Polling/Refresh**: Consider implementing:
   - Auto-refresh every 30-60 seconds
   - Manual refresh button
   - Real-time updates via WebSockets (if available)

7. **Performance**: 
   - Default limit is 20, which is usually sufficient
   - Don't fetch too frequently (max once per 10-15 seconds)
   - Consider caching activities client-side

## Testing

Test the endpoint using curl:

```bash
curl -X GET "http://localhost:8000/api/dashboard/recent-activities?limit=10" \
  -H "Authorization: Bearer YOUR_TOKEN_HERE" \
  -H "Accept: application/json"
```

Or using Postman:
- Method: GET
- URL: `http://localhost:8000/api/dashboard/recent-activities?limit=10`
- Headers:
  - `Authorization: Bearer YOUR_TOKEN_HERE`
  - `Accept: application/json`
