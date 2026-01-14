# Testing Guide - OneDrive Integration & User Profile

## 🧪 Testing Checklist

### **1. OneDrive Service - Centralized Account**

#### Test: Verify Centralized Account Usage
```bash
# In Laravel Tinker
php artisan tinker

>>> $service = app(\App\Services\OneDriveService::class);
>>> $service->getDriveEndpoint("/root:/test");
# Should return: https://graph.microsoft.com/v1.0/users/procurement@emeraldcfze.com/drive/root:/test
```

**Expected Result:**
- All endpoints use `/users/procurement@emeraldcfze.com/drive`
- No `/me/drive` endpoints remain

---

### **2. PO Generation & OneDrive Upload**

#### Test: Generate PO with File Upload
1. Navigate to Procurement Dashboard
2. Find an Executive-approved MRF
3. Click "Generate PO"
4. Fill in:
   - Select vendor
   - Upload PO document (PDF/DOC/DOCX)
   - Enter amount, delivery date, payment terms
5. Click "Generate PO"

**Expected Results:**
- ✅ PO generated successfully
- ✅ File uploaded to OneDrive: `SupplyChainDocs/PurchaseOrders/{year}/{month}/PO_{po_number}_{mrf_id}.{ext}`
- ✅ Sharing link created and stored in `unsigned_po_share_url`
- ✅ "View on OneDrive" badge appears in Supply Chain Dashboard
- ✅ File accessible via sharing link without login

**Verify in Database:**
```sql
SELECT mrf_id, po_number, unsigned_po_url, unsigned_po_share_url 
FROM m_r_f_s 
WHERE po_number IS NOT NULL 
ORDER BY po_generated_at DESC 
LIMIT 1;
```

**Verify in OneDrive:**
1. Log in to `procurement@emeraldcfze.com`
2. Navigate to OneDrive
3. Check `SupplyChainDocs/PurchaseOrders/{year}/{month}/`
4. Verify file exists and is accessible

---

### **3. Signed PO Upload**

#### Test: Upload Signed PO
1. Navigate to Supply Chain Dashboard
2. Find a PO awaiting signature
3. Click "Upload Signed PO"
4. Select signed PDF file
5. Click "Upload"

**Expected Results:**
- ✅ Signed PO uploaded successfully
- ✅ File uploaded to OneDrive: `SupplyChainDocs/PurchaseOrders_Signed/{year}/{month}/PO_Signed_{po_number}_{mrf_id}.pdf`
- ✅ Sharing link created and stored in `signed_po_share_url`
- ✅ "View on OneDrive" badge appears in Finance Dashboard
- ✅ MRF status changes to "finance"

**Verify in Database:**
```sql
SELECT mrf_id, po_number, signed_po_url, signed_po_share_url 
FROM m_r_f_s 
WHERE signed_po_url IS NOT NULL 
ORDER BY po_signed_at DESC 
LIMIT 1;
```

---

### **4. Vendor Document Upload**

#### Test: Register Vendor with Documents
1. Navigate to Vendor Registration
2. Fill in vendor details
3. Upload required documents (CAC, TIN, Bank Statement, etc.)
4. Submit registration

**Expected Results:**
- ✅ Documents uploaded to OneDrive: `SupplyChainDocs/VendorDocuments/{year}/{company-name}/`
- ✅ Sharing links created for each document
- ✅ URLs stored in `vendor_registration_documents` table
- ✅ Documents accessible via sharing links

**Verify in Database:**
```sql
SELECT id, file_name, file_url, file_share_url 
FROM vendor_registration_documents 
WHERE file_share_url IS NOT NULL 
ORDER BY uploaded_at DESC 
LIMIT 5;
```

**Verify in OneDrive:**
1. Log in to `procurement@emeraldcfze.com`
2. Navigate to `SupplyChainDocs/VendorDocuments/{year}/`
3. Check company folder exists
4. Verify all documents are present

---

### **5. Sharing Links Functionality**

#### Test: Access Documents via Sharing Links
1. Get a sharing URL from database or UI
2. Open sharing URL in incognito/private browser window
3. Verify document opens without requiring login

**Expected Results:**
- ✅ Sharing link works without authentication
- ✅ Document is view-only (cannot edit)
- ✅ Accessible to organization members only
- ✅ Link doesn't expire (unless manually revoked)

**Test URLs:**
- Unsigned PO sharing link
- Signed PO sharing link
- Vendor document sharing link

---

### **6. User Profile Management**

#### Test: Update Profile
1. Click user info in top-right corner
2. Settings page opens
3. Update:
   - Name
   - Department
   - Phone (optional)
4. Click "Save Changes"

**Expected Results:**
- ✅ Profile updates successfully
- ✅ Loading state shows during save
- ✅ Success toast appears
- ✅ Changes persist after page refresh
- ✅ User data updated in database

**Verify in Database:**
```sql
SELECT id, name, email, department, phone 
FROM users 
WHERE email = 'your_test_email@example.com';
```

#### Test: Change Password
1. Navigate to Settings → Security tab
2. Enter:
   - Current password
   - New password (min 8 characters)
   - Confirm new password
3. Click "Change Password"

**Expected Results:**
- ✅ Password changes successfully
- ✅ Loading state shows during change
- ✅ Success toast appears
- ✅ Can login with new password
- ✅ Cannot login with old password

---

### **7. Frontend OneDrive Links**

#### Test: OneDrive Link Display
1. Navigate to Supply Chain Dashboard
2. Check for "View on OneDrive" badges on unsigned POs
3. Navigate to Finance Dashboard
4. Check for "View on OneDrive" badges on signed POs

**Expected Results:**
- ✅ OneDrive badges appear when sharing URL exists
- ✅ Badges are clickable
- ✅ Clicking opens document in new tab
- ✅ Badges use correct sharing URLs (not web URLs)

**Locations to Check:**
- Supply Chain Dashboard: Unsigned POs
- Finance Dashboard: Signed POs
- Procurement Dashboard: Generated POs (if applicable)

---

### **8. Fallback to Local Storage**

#### Test: OneDrive Failure Handling
1. Temporarily disable OneDrive credentials in `.env`
2. Generate a PO
3. Upload signed PO
4. Register vendor with documents

**Expected Results:**
- ✅ System falls back to local/S3 storage
- ✅ No errors thrown
- ✅ Files still stored successfully
- ✅ Logs show fallback messages
- ✅ System continues to function

**Check Logs:**
```bash
tail -f storage/logs/laravel.log | grep -i "onedrive\|fallback"
```

---

### **9. Error Handling**

#### Test: Invalid OneDrive Credentials
1. Set incorrect `MICROSOFT_CLIENT_ID` in `.env`
2. Attempt to generate PO

**Expected Results:**
- ✅ Error logged but not thrown to user
- ✅ System falls back to local storage
- ✅ User sees success message (file still stored)
- ✅ No application crash

#### Test: Large File Upload (>4MB)
1. Generate PO with file > 4MB
2. Verify upload session is created
3. Check file appears in OneDrive

**Expected Results:**
- ✅ Upload session created for large files
- ✅ File uploaded in chunks
- ✅ Upload completes successfully
- ✅ File accessible in OneDrive

---

### **10. Integration Testing**

#### Test: Complete Workflow
1. **Create MRF** → Executive approves
2. **Generate PO** → Verify OneDrive upload
3. **Supply Chain signs** → Upload signed PO → Verify OneDrive
4. **Finance processes** → Verify can access signed PO
5. **Vendor registers** → Upload documents → Verify OneDrive

**Expected Results:**
- ✅ All documents in OneDrive
- ✅ All sharing links work
- ✅ All workflows complete successfully
- ✅ No data loss or errors

---

## 🐛 Common Issues & Solutions

### **Issue: Sharing Links Not Created**

**Symptoms:**
- `unsigned_po_share_url` is NULL in database
- OneDrive badges don't appear

**Solutions:**
1. Check OneDrive API permissions in Azure AD
2. Verify `Files.ReadWrite.All` permission granted
3. Check Laravel logs for API errors
4. Verify organization sharing is enabled

### **Issue: OneDrive Upload Fails**

**Symptoms:**
- Error in logs: "Failed to upload file to OneDrive"
- Files stored locally instead

**Solutions:**
1. Verify Azure AD app credentials
2. Check token generation works
3. Verify `procurement@emeraldcfze.com` account exists
4. Check OneDrive storage quota

### **Issue: Profile Update Fails**

**Symptoms:**
- Error toast appears
- Changes don't persist

**Solutions:**
1. Check API endpoint: `PUT /api/auth/profile`
2. Verify authentication token is valid
3. Check user permissions
4. Verify database connection

---

## ✅ Success Criteria

All tests should pass:
- ✅ OneDrive uploads work
- ✅ Sharing links created
- ✅ Documents accessible
- ✅ Profile updates work
- ✅ Password changes work
- ✅ Fallback works
- ✅ Error handling works
- ✅ Complete workflows work

---

## 📊 Test Results Template

```
Date: ___________
Tester: ___________

OneDrive Service:
[ ] Centralized account verified
[ ] PO upload works
[ ] Signed PO upload works
[ ] Vendor documents upload works
[ ] Sharing links created
[ ] Sharing links accessible

User Profile:
[ ] Profile update works
[ ] Password change works
[ ] User info clickable
[ ] Settings page accessible

Frontend:
[ ] OneDrive badges appear
[ ] Badges clickable
[ ] Links open correctly

Error Handling:
[ ] Fallback works
[ ] Errors handled gracefully
[ ] No crashes

Overall: [ ] PASS [ ] FAIL

Notes:
_______________________________________
_______________________________________
```
