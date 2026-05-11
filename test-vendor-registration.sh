#!/bin/bash

# Vendor Registration Endpoint - Quick Test Script
# Tests CORS configuration, health check, and vendor registration flow

set -e

BACKEND_URL="${1:-https://supply-chain-backend-hwh6.onrender.com}"
FRONTEND_ORIGIN="${2:-https://emerald-supply-chain.vercel.app}"

echo "================================"
echo "Vendor Registration Test Suite"
echo "================================"
echo "Backend: $BACKEND_URL"
echo "Frontend Origin: $FRONTEND_ORIGIN"
echo ""

# Color codes for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

test_result() {
    local test_name=$1
    local http_code=$2
    local expected_code=$3

    if [ "$http_code" = "$expected_code" ]; then
        echo -e "${GREEN}✓ PASS${NC}: $test_name (HTTP $http_code)"
    else
        echo -e "${RED}✗ FAIL${NC}: $test_name (Expected HTTP $expected_code, got $http_code)"
    fi
}

# Test 1: CORS Preflight Request
echo -e "\n${YELLOW}Test 1: CORS Preflight Request${NC}"
CORS_RESPONSE=$(curl -s -X OPTIONS \
  "$BACKEND_URL/api/vendors/register" \
  -H "Origin: $FRONTEND_ORIGIN" \
  -H "Access-Control-Request-Method: POST" \
  -H "Access-Control-Request-Headers: Content-Type, Authorization" \
  -w "\n%{http_code}" \
  -o /tmp/cors_response.txt)

HTTP_CODE=$(tail -n1 /tmp/cors_response.txt)
test_result "CORS Preflight" "$HTTP_CODE" "204"

if [ "$HTTP_CODE" = "204" ] || [ "$HTTP_CODE" = "200" ]; then
    echo "  Response headers:"
    curl -s -I -X OPTIONS \
      "$BACKEND_URL/api/vendors/register" \
      -H "Origin: $FRONTEND_ORIGIN" \
      -H "Access-Control-Request-Method: POST" | grep -i "access-control"
fi

# Test 2: Health Check
echo -e "\n${YELLOW}Test 2: Health Check Endpoint${NC}"
HEALTH_RESPONSE=$(curl -s "$BACKEND_URL/api/health" \
  -H "Origin: $FRONTEND_ORIGIN" \
  -w "\n%{http_code}")

HTTP_CODE=$(echo "$HEALTH_RESPONSE" | tail -n1)
test_result "Health Check" "$HTTP_CODE" "200"

if [ "$HTTP_CODE" = "200" ]; then
    echo "  Response:"
    echo "$HEALTH_RESPONSE" | head -n-1 | jq '.' 2>/dev/null || echo "$HEALTH_RESPONSE" | head -n-1
fi

# Test 3: CORS Test Endpoint
echo -e "\n${YELLOW}Test 3: CORS Test Endpoint${NC}"
CORS_TEST_RESPONSE=$(curl -s "$BACKEND_URL/api/cors-test" \
  -H "Origin: $FRONTEND_ORIGIN" \
  -w "\n%{http_code}")

HTTP_CODE=$(echo "$CORS_TEST_RESPONSE" | tail -n1)
test_result "CORS Test" "$HTTP_CODE" "200"

if [ "$HTTP_CODE" = "200" ]; then
    echo "  Response:"
    echo "$CORS_TEST_RESPONSE" | head -n-1 | jq '.' 2>/dev/null || echo "$CORS_TEST_RESPONSE" | head -n-1
fi

# Test 4: Vendor Registration (Valid Request)
echo -e "\n${YELLOW}Test 4: Vendor Registration - Valid Request${NC}"
REGISTER_RESPONSE=$(curl -s -X POST "$BACKEND_URL/api/vendors/register" \
  -H "Origin: $FRONTEND_ORIGIN" \
  -H "Content-Type: application/json" \
  -d '{
    "companyName": "Test Company",
    "email": "test'$(date +%s)'@example.com",
    "category": "SUPPLIER",
    "phone": "+1234567890",
    "address": "123 Business Street",
    "contactPerson": "John Doe"
  }' \
  -w "\n%{http_code}")

HTTP_CODE=$(echo "$REGISTER_RESPONSE" | tail -n1)
test_result "Vendor Registration" "$HTTP_CODE" "201"

if [ "$HTTP_CODE" = "201" ]; then
    echo "  Response:"
    echo "$REGISTER_RESPONSE" | head -n-1 | jq '.' 2>/dev/null || echo "$REGISTER_RESPONSE" | head -n-1
fi

# Test 5: Vendor Registration (Duplicate Email)
echo -e "\n${YELLOW}Test 5: Vendor Registration - Duplicate Email${NC}"
TEST_EMAIL="duplicate-$(date +%s)@example.com"

# First registration
curl -s -X POST "$BACKEND_URL/api/vendors/register" \
  -H "Origin: $FRONTEND_ORIGIN" \
  -H "Content-Type: application/json" \
  -d '{
    "companyName": "Test Company",
    "email": "'$TEST_EMAIL'",
    "category": "SUPPLIER",
    "phone": "+1234567890",
    "address": "123 Business Street",
    "contactPerson": "John Doe"
  }' > /dev/null

# Second registration with same email
DUPLICATE_RESPONSE=$(curl -s -X POST "$BACKEND_URL/api/vendors/register" \
  -H "Origin: $FRONTEND_ORIGIN" \
  -H "Content-Type: application/json" \
  -d '{
    "companyName": "Test Company 2",
    "email": "'$TEST_EMAIL'",
    "category": "SUPPLIER",
    "phone": "+1234567890",
    "address": "456 Business Avenue",
    "contactPerson": "Jane Doe"
  }' \
  -w "\n%{http_code}")

HTTP_CODE=$(echo "$DUPLICATE_RESPONSE" | tail -n1)
test_result "Duplicate Email Handling" "$HTTP_CODE" "200"

if [ "$HTTP_CODE" = "200" ]; then
    echo "  Response:"
    echo "$DUPLICATE_RESPONSE" | head -n-1 | jq '.' 2>/dev/null || echo "$DUPLICATE_RESPONSE" | head -n-1
fi

# Test 6: Vendor Registration (Missing Email)
echo -e "\n${YELLOW}Test 6: Vendor Registration - Missing Email${NC}"
MISSING_EMAIL_RESPONSE=$(curl -s -X POST "$BACKEND_URL/api/vendors/register" \
  -H "Origin: $FRONTEND_ORIGIN" \
  -H "Content-Type: application/json" \
  -d '{
    "companyName": "Test Company",
    "category": "SUPPLIER",
    "phone": "+1234567890",
    "address": "123 Business Street",
    "contactPerson": "John Doe"
  }' \
  -w "\n%{http_code}")

HTTP_CODE=$(echo "$MISSING_EMAIL_RESPONSE" | tail -n1)
test_result "Missing Email Handling" "$HTTP_CODE" "422"

if [ "$HTTP_CODE" = "422" ]; then
    echo "  Response:"
    echo "$MISSING_EMAIL_RESPONSE" | head -n-1 | jq '.' 2>/dev/null || echo "$MISSING_EMAIL_RESPONSE" | head -n-1
fi

# Test 7: Vendor Registration (Missing Required Fields)
echo -e "\n${YELLOW}Test 7: Vendor Registration - Missing Required Fields${NC}"
MISSING_FIELDS_RESPONSE=$(curl -s -X POST "$BACKEND_URL/api/vendors/register" \
  -H "Origin: $FRONTEND_ORIGIN" \
  -H "Content-Type: application/json" \
  -d '{
    "email": "incomplete@example.com"
  }' \
  -w "\n%{http_code}")

HTTP_CODE=$(echo "$MISSING_FIELDS_RESPONSE" | tail -n1)
test_result "Missing Required Fields" "$HTTP_CODE" "422"

if [ "$HTTP_CODE" = "422" ]; then
    echo "  Response:"
    echo "$MISSING_FIELDS_RESPONSE" | head -n-1 | jq '.' 2>/dev/null || echo "$MISSING_FIELDS_RESPONSE" | head -n-1
fi

# Summary
echo ""
echo "================================"
echo "Test Suite Complete"
echo "================================"
echo ""
echo "Next Steps:"
echo "1. Verify all tests show GREEN ✓"
echo "2. Check browser DevTools Network tab for actual CORS headers"
echo "3. Check server logs at: tail -f storage/logs/laravel.log"
echo "4. Review VENDOR_REGISTRATION_FIX_SUMMARY.md for full details"
