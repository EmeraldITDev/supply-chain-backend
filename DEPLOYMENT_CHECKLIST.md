# Deployment Checklist - User Profile & Centralized OneDrive

## ✅ Pre-Deployment

### **Backend**

- [ ] **Run Migrations:**
  ```bash
  php artisan migrate
  ```

- [ ] **Update Environment Variables (.env):**
  ```env
  # Existing OneDrive variables
  MICROSOFT_CLIENT_ID=your_client_id
  MICROSOFT_CLIENT_SECRET=your_client_secret
  MICROSOFT_TENANT_ID=your_tenant_id
  
  # NEW: Centralized account
  ONEDRIVE_USER_EMAIL=procurement@emeraldcfze.com
  ONEDRIVE_ROOT_FOLDER=/SupplyChainDocs
  ```

- [ ] **Clear Cache:**
  ```bash
  php artisan config:clear
  php artisan cache:clear
  php artisan route:clear
  ```

- [ ] **Verify Azure AD Configuration:**
  - [ ] Application permissions: `Files.ReadWrite.All` granted
  - [ ] Admin consent granted
  - [ ] `procurement@emeraldcfze.com` account exists in tenant
  - [ ] Account has OneDrive enabled

### **Frontend**

- [ ] **Build Production:**
  ```bash
  npm run build
  ```

- [ ] **Verify Environment Variables:**
  - `VITE_API_BASE_URL` is set correctly

---

## ✅ Post-Deployment Testing

### **OneDrive Integration**

- [ ] Generate a PO → Verify upload to OneDrive
- [ ] Check sharing link is created
- [ ] Click "On OneDrive" badge → Verify opens correctly
- [ ] Upload signed PO → Verify goes to OneDrive
- [ ] Register vendor → Verify documents in OneDrive
- [ ] Check folder structure matches expected pattern

### **User Profile**

- [ ] Click user info in top-right → Opens settings
- [ ] Update name → Verify saves correctly
- [ ] Update department → Verify saves correctly
- [ ] Change password → Verify works
- [ ] Check loading states appear correctly

### **Sharing Links**

- [ ] Verify sharing links work (view-only)
- [ ] Test links open without login requirement
- [ ] Verify organization-only access

---

## ✅ Verification Queries

### **Database**

```sql
-- Check sharing URLs are stored
SELECT mrf_id, po_number, unsigned_po_url, unsigned_po_share_url 
FROM m_r_f_s 
WHERE unsigned_po_share_url IS NOT NULL 
LIMIT 5;

-- Check vendor documents have URLs
SELECT id, file_name, file_url, file_share_url 
FROM vendor_registration_documents 
WHERE file_share_url IS NOT NULL 
LIMIT 5;
```

### **OneDrive**

1. Log in to `procurement@emeraldcfze.com`
2. Navigate to OneDrive
3. Check `SupplyChainDocs` folder structure
4. Verify files are organized correctly

---

## 🐛 Troubleshooting

### **OneDrive Not Working**

1. Check logs: `storage/logs/laravel.log`
2. Verify credentials in `.env`
3. Test token generation:
   ```bash
   php artisan tinker
   >>> app(\App\Services\OneDriveService::class)->getAccessToken();
   ```
4. Verify Azure AD permissions

### **Sharing Links Not Creating**

1. Check OneDrive API responses in logs
2. Verify file upload succeeded first
3. Check if organization sharing is enabled in Azure AD

### **Profile Update Fails**

1. Check API endpoint: `PUT /api/auth/profile`
2. Verify authentication token is valid
3. Check user permissions

---

## ✅ Success Criteria

- ✅ All POs uploaded to procurement OneDrive
- ✅ Sharing links work for all documents
- ✅ Vendor documents in OneDrive
- ✅ User profile updates work
- ✅ Password change works
- ✅ User info clickable

---

## 📞 Support

If issues occur:
1. Check Laravel logs: `storage/logs/laravel.log`
2. Check browser console for frontend errors
3. Verify Azure AD app permissions
4. Test OneDrive authentication manually
