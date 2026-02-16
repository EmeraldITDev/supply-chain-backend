# Backend API Response Structure Guide

## Overview
All Logistics API endpoints follow a consistent response structure to ensure predictable data handling on the frontend.

## Response Format

### Success Response Structure
```json
{
  "success": true,
  "data": {
    "trips": { ...pagination object... },
    "vehicles": { ...pagination object... },
    // or specific entity
    "trip": { ...single object... }
  }
}
```

### Pagination Object Structure
When endpoints return lists (GET /trips, GET /vehicles, etc.), the data is wrapped in Laravel's pagination object:

```json
{
  "success": true,
  "data": {
    "trips": {
      "current_page": 1,
      "data": [
        { "id": 1, "title": "Trip 1", ... },
        { "id": 2, "title": "Trip 2", ... }
      ],
      "first_page_url": "...",
      "from": 1,
      "last_page": 5,
      "last_page_url": "...",
      "next_page_url": "...",
      "path": "...",
      "per_page": 20,
      "prev_page_url": null,
      "to": 20,
      "total": 100
    }
  }
}
```

## Frontend Data Extraction

### ❌ WRONG - Passing Whole Response Object
```javascript
// This will fail because response.data is an object, not an array
const trips = response.data;
trips.filter(...) // ERROR: filter is not a function
```

### ✅ CORRECT - Extract the Array
```javascript
// Option 1: Direct extraction from axios response
const trips = response.data.data.trips.data; // Array of trip objects

// Option 2: Destructure for clarity
const { data: { trips: tripsData } } = response.data;
const trips = tripsData.data; // Array of trip objects

// Option 3: Create a helper utility
const extractData = (response, key) => {
  return response.data.data[key]?.data || [];
};
const trips = extractData(response, 'trips');
```

## Endpoint-Specific Examples

### GET /api/v1/logistics/trips
**Response:**
```json
{
  "success": true,
  "data": {
    "trips": {
      "current_page": 1,
      "data": [ /* array of trip objects */ ],
      "per_page": 20,
      "total": 45
    }
  }
}
```

**Frontend extraction:**
```javascript
const response = await axios.get('/api/v1/logistics/trips');
const trips = response.data.data.trips.data; // ✅ Array
const pagination = {
  currentPage: response.data.data.trips.current_page,
  perPage: response.data.data.trips.per_page,
  total: response.data.data.trips.total
};
```

### GET /api/v1/logistics/trips/{id}
**Response:**
```json
{
  "success": true,
  "data": {
    "trip": {
      "id": 1,
      "title": "Delivery to Warehouse",
      "origin": "Lagos",
      "destination": "Abuja"
    }
  }
}
```

**Frontend extraction:**
```javascript
const response = await axios.get('/api/v1/logistics/trips/1');
const trip = response.data.data.trip; // ✅ Single object
```

### POST /api/v1/logistics/trips (Create)
**Response:**
```json
{
  "success": true,
  "data": {
    "trip": {
      "id": 123,
      "trip_code": "TRIP-20260216-ABC123",
      "title": "New Trip",
      ...
    }
  }
}
```

**Frontend extraction:**
```javascript
const response = await axios.post('/api/v1/logistics/trips', payload);
const newTrip = response.data.data.trip; // ✅ Newly created object
```

### GET /api/v1/logistics/fleet/vehicles
**Response:**
```json
{
  "success": true,
  "data": {
    "vehicles": {
      "data": [ /* array of vehicle objects */ ],
      "current_page": 1,
      "total": 30
    }
  }
}
```

**Frontend extraction:**
```javascript
const response = await axios.get('/api/v1/logistics/fleet/vehicles');
const vehicles = response.data.data.vehicles.data; // ✅ Array
```

### GET /api/v1/logistics/materials
**Response:**
```json
{
  "success": true,
  "data": {
    "materials": {
      "data": [ /* array of material objects */ ],
      "current_page": 1,
      "total": 50
    }
  }
}
```

**Frontend extraction:**
```javascript
const response = await axios.get('/api/v1/logistics/materials');
const materials = response.data.data.materials.data; // ✅ Array
```

## Recommended Frontend API Service Pattern

### Base API Service
```javascript
// services/api.js
import axios from 'axios';

const api = axios.create({
  baseURL: process.env.REACT_APP_API_URL,
  headers: {
    'Content-Type': 'application/json'
  }
});

// Add auth token interceptor
api.interceptors.request.use((config) => {
  const token = localStorage.getItem('token');
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

export default api;
```

### Logistics API Service (Correct Implementation)
```javascript
// services/logistics.js
import api from './api';

// Helper to extract pagination data
const extractPaginatedData = (response, key) => {
  const paginationObj = response.data.data[key];
  return {
    data: paginationObj.data || [],
    pagination: {
      currentPage: paginationObj.current_page,
      lastPage: paginationObj.last_page,
      perPage: paginationObj.per_page,
      total: paginationObj.total
    }
  };
};

// Helper to extract single entity
const extractEntity = (response, key) => {
  return response.data.data[key];
};

export const logisticsService = {
  // Trips
  async getTrips(params = {}) {
    const response = await api.get('/v1/logistics/trips', { params });
    return extractPaginatedData(response, 'trips');
  },
  
  async getTrip(id) {
    const response = await api.get(`/v1/logistics/trips/${id}`);
    return extractEntity(response, 'trip');
  },
  
  async createTrip(data) {
    const response = await api.post('/v1/logistics/trips', data);
    return extractEntity(response, 'trip');
  },
  
  async updateTrip(id, data) {
    const response = await api.put(`/v1/logistics/trips/${id}`, data);
    return extractEntity(response, 'trip');
  },
  
  // Vehicles
  async getVehicles(params = {}) {
    const response = await api.get('/v1/logistics/fleet/vehicles', { params });
    return extractPaginatedData(response, 'vehicles');
  },
  
  async createVehicle(data) {
    const response = await api.post('/v1/logistics/fleet/vehicles', data);
    return extractEntity(response, 'vehicle');
  },
  
  // Materials
  async getMaterials(params = {}) {
    const response = await api.get('/v1/logistics/materials', { params });
    return extractPaginatedData(response, 'materials');
  },
  
  async createMaterial(data) {
    const response = await api.post('/v1/logistics/materials', data);
    return extractEntity(response, 'material');
  }
};
```

### Usage in React Components
```javascript
// components/TripList.jsx
import { useState, useEffect } from 'react';
import { logisticsService } from '../services/logistics';

function TripList() {
  const [trips, setTrips] = useState([]);
  const [pagination, setPagination] = useState({});
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    fetchTrips();
  }, []);

  const fetchTrips = async () => {
    try {
      setLoading(true);
      const result = await logisticsService.getTrips({ page: 1 });
      setTrips(result.data); // ✅ This is an array
      setPagination(result.pagination);
    } catch (error) {
      console.error('Failed to fetch trips:', error);
    } finally {
      setLoading(false);
    }
  };

  return (
    <div>
      {trips.map(trip => ( // ✅ Works correctly
        <div key={trip.id}>{trip.title}</div>
      ))}
    </div>
  );
}
```

## Error Response Structure
```json
{
  "success": false,
  "error": "Validation failed",
  "code": "VALIDATION_ERROR",
  "errors": {
    "title": ["The title field is required"],
    "origin": ["The origin field is required"]
  }
}
```

## Common Mistakes to Avoid

### 1. ❌ Directly using response.data
```javascript
const trips = response.data;
trips.filter(...) // ERROR
```

### 2. ❌ Forgetting pagination wrapper
```javascript
const trips = response.data.data.trips;
trips.filter(...) // ERROR - trips is pagination object, not array
```

### 3. ✅ Correct approach
```javascript
const trips = response.data.data.trips.data;
trips.filter(...) // ✅ WORKS
```

## Quick Reference

| Endpoint Type | Response Path | What You Get |
|--------------|---------------|--------------|
| GET /trips | `response.data.data.trips.data` | Array of trips |
| GET /trips/{id} | `response.data.data.trip` | Single trip object |
| POST /trips | `response.data.data.trip` | Newly created trip |
| GET /vehicles | `response.data.data.vehicles.data` | Array of vehicles |
| GET /materials | `response.data.data.materials.data` | Array of materials |
| GET /journeys/{trip_id} | `response.data.data.journey` | Single journey object |

## Summary
- All successful responses have `{ success: true, data: {...} }`
- List endpoints return paginated objects with the actual array in `.data`
- Always extract the correct level: `response.data.data[key].data` for lists
- Use helper functions to standardize data extraction
- The actual arrays are nested inside pagination objects
