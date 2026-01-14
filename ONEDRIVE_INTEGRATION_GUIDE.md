# OneDrive Integration Guide for Supply Chain System

## Overview

This guide will help you integrate Microsoft OneDrive as the primary file storage for your Supply Chain Management system.

## Prerequisites

1. Microsoft 365 Business Account with OneDrive
2. Azure AD App Registration (for API access)
3. Sufficient OneDrive storage space

---

## Phase 1: Azure AD Setup

### Step 1: Register App in Azure Portal

1. Go to [Azure Portal](https://portal.azure.com)
2. Navigate to **Azure Active Directory** → **App registrations**
3. Click **New registration**
4. Fill in details:
   - **Name**: `Supply Chain Backend`
   - **Supported account types**: `Accounts in this organizational directory only`
   - **Redirect URI**: `https://supply-chain-backend-hwh6.onrender.com/auth/microsoft/callback`
5. Click **Register**

### Step 2: Get Credentials

After registration, note these values:

- **Application (client) ID**: `xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx`
- **Directory (tenant) ID**: `xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx`

### Step 3: Create Client Secret

1. Go to **Certificates & secrets**
2. Click **New client secret**
3. Description: `Backend API Access`
4. Expires: `24 months` (or your preference)
5. Click **Add**
6. **Copy the secret value immediately** (you won't see it again!)

### Step 4: Configure API Permissions

1. Go to **API permissions**
2. Click **Add a permission**
3. Select **Microsoft Graph**
4. Choose **Delegated permissions** OR **Application permissions** (see below)

**For Application (Background) Access (Recommended):**
- `Files.ReadWrite.All` - Read and write files in all site collections
- `Sites.ReadWrite.All` - Read and write items in all site collections

**For Delegated (User) Access:**
- `Files.ReadWrite.All` - Have full access to all files user can access
- `offline_access` - Maintain access to data you have given it access to
- `User.Read` - Sign in and read user profile

5. Click **Grant admin consent** for your organization

---

## Phase 2: Backend Setup (Laravel)

### Step 1: Install Required Packages

```bash
cd "/Users/asukuonukaba/Desktop/SCM Backend/supply-chain-backend"

# Install Microsoft Graph SDK
composer require microsoft/microsoft-graph

# Install Flysystem OneDrive adapter
composer require league/flysystem-onedrive
```

### Step 2: Add Environment Variables

Edit `.env` file:

```env
# Microsoft OneDrive Configuration
MICROSOFT_CLIENT_ID=your_client_id_from_azure
MICROSOFT_CLIENT_SECRET=your_client_secret_from_azure
MICROSOFT_TENANT_ID=your_tenant_id_from_azure
MICROSOFT_REDIRECT_URI=https://supply-chain-backend-hwh6.onrender.com/auth/microsoft/callback

# OneDrive Settings
ONEDRIVE_DRIVE_ID=your_drive_id
ONEDRIVE_ROOT_FOLDER=/SupplyChainDocs
FILESYSTEM_DISK=onedrive

# Optional: SharePoint Site (if using SharePoint instead of personal OneDrive)
SHAREPOINT_SITE_ID=your_site_id
```

### Step 3: Configure Filesystem

Edit `config/filesystems.php`:

```php
'disks' => [
    // ... existing disks ...

    'onedrive' => [
        'driver' => 'onedrive',
        'client_id' => env('MICROSOFT_CLIENT_ID'),
        'client_secret' => env('MICROSOFT_CLIENT_SECRET'),
        'tenant_id' => env('MICROSOFT_TENANT_ID'),
        'redirect_uri' => env('MICROSOFT_REDIRECT_URI'),
        'drive_id' => env('ONEDRIVE_DRIVE_ID'),
        'root' => env('ONEDRIVE_ROOT_FOLDER', '/SupplyChainDocs'),
    ],

    // Set OneDrive as documents disk
    'documents' => [
        'driver' => 'onedrive',
        'client_id' => env('MICROSOFT_CLIENT_ID'),
        'client_secret' => env('MICROSOFT_CLIENT_SECRET'),
        'tenant_id' => env('MICROSOFT_TENANT_ID'),
        'redirect_uri' => env('MICROSOFT_REDIRECT_URI'),
        'drive_id' => env('ONEDRIVE_DRIVE_ID'),
        'root' => env('ONEDRIVE_ROOT_FOLDER', '/SupplyChainDocs'),
    ],
],
```

### Step 4: Create OneDrive Service

Create `app/Services/OneDriveService.php`:

```php
<?php

namespace App\Services;

use Microsoft\Graph\Graph;
use Microsoft\Graph\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class OneDriveService
{
    protected $graph;
    protected $accessToken;

    public function __construct()
    {
        $this->authenticate();
    }

    /**
     * Authenticate with Microsoft Graph API
     */
    protected function authenticate()
    {
        $guzzle = new \GuzzleHttp\Client();
        
        // Get cached token or request new one
        $this->accessToken = Cache::remember('onedrive_access_token', 3000, function () use ($guzzle) {
            $url = 'https://login.microsoftonline.com/' . config('filesystems.disks.onedrive.tenant_id') . '/oauth2/v2.0/token';
            
            $response = $guzzle->post($url, [
                'form_params' => [
                    'client_id' => config('filesystems.disks.onedrive.client_id'),
                    'client_secret' => config('filesystems.disks.onedrive.client_secret'),
                    'scope' => 'https://graph.microsoft.com/.default',
                    'grant_type' => 'client_credentials',
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            return $data['access_token'];
        });

        $this->graph = new Graph();
        $this->graph->setAccessToken($this->accessToken);
    }

    /**
     * Upload file to OneDrive
     * 
     * @param \Illuminate\Http\UploadedFile $file
     * @param string $folder Folder path (e.g., 'PurchaseOrders', 'Vendors/Documents')
     * @param string $fileName Optional custom file name
     * @return array ['path' => string, 'webUrl' => string, 'id' => string]
     */
    public function uploadFile($file, $folder = '', $fileName = null)
    {
        try {
            $fileName = $fileName ?? $file->getClientOriginalName();
            $fileName = $this->sanitizeFileName($fileName);
            
            // Construct full path
            $rootFolder = config('filesystems.disks.onedrive.root', '/SupplyChainDocs');
            $fullPath = $rootFolder . '/' . trim($folder, '/') . '/' . $fileName;
            
            Log::info('Uploading file to OneDrive', [
                'file_name' => $fileName,
                'path' => $fullPath,
                'size' => $file->getSize()
            ]);

            // For files under 4MB, use simple upload
            if ($file->getSize() < 4 * 1024 * 1024) {
                $endpoint = "/me/drive/root:/{$fullPath}:/content";
                
                $response = $this->graph->createRequest("PUT", $endpoint)
                    ->attachBody($file->getContent())
                    ->setReturnType(Model\DriveItem::class)
                    ->execute();

                return [
                    'path' => $fullPath,
                    'webUrl' => $response->getWebUrl(),
                    'id' => $response->getId(),
                    'name' => $response->getName(),
                ];
            } else {
                // For larger files, use upload session
                return $this->uploadLargeFile($file, $fullPath);
            }
        } catch (\Exception $e) {
            Log::error('OneDrive upload failed', [
                'error' => $e->getMessage(),
                'file' => $fileName
            ]);
            throw $e;
        }
    }

    /**
     * Upload large file (>4MB) using upload session
     */
    protected function uploadLargeFile($file, $path)
    {
        $endpoint = "/me/drive/root:/{$path}:/createUploadSession";
        
        $uploadSession = $this->graph->createRequest("POST", $endpoint)
            ->attachBody([
                'item' => [
                    '@microsoft.graph.conflictBehavior' => 'replace'
                ]
            ])
            ->setReturnType(Model\UploadSession::class)
            ->execute();

        // Upload file in chunks
        $uploadUrl = $uploadSession->getUploadUrl();
        $fileSize = $file->getSize();
        $chunkSize = 320 * 1024 * 10; // 3.2MB chunks
        $offset = 0;

        $handle = fopen($file->getRealPath(), 'r');

        while (!feof($handle)) {
            $chunk = fread($handle, $chunkSize);
            $chunkLength = strlen($chunk);
            $end = $offset + $chunkLength - 1;

            $guzzle = new \GuzzleHttp\Client();
            $response = $guzzle->put($uploadUrl, [
                'headers' => [
                    'Content-Length' => $chunkLength,
                    'Content-Range' => "bytes {$offset}-{$end}/{$fileSize}",
                ],
                'body' => $chunk,
            ]);

            $offset += $chunkLength;
        }

        fclose($handle);

        $result = json_decode($response->getBody()->getContents(), true);
        
        return [
            'path' => $path,
            'webUrl' => $result['webUrl'],
            'id' => $result['id'],
            'name' => $result['name'],
        ];
    }

    /**
     * Get file download URL from OneDrive
     * 
     * @param string $path File path on OneDrive
     * @return string Download URL
     */
    public function getDownloadUrl($path)
    {
        try {
            $endpoint = "/me/drive/root:/{$path}";
            
            $driveItem = $this->graph->createRequest("GET", $endpoint)
                ->setReturnType(Model\DriveItem::class)
                ->execute();

            return $driveItem->getProperties()['@microsoft.graph.downloadUrl'];
        } catch (\Exception $e) {
            Log::error('Failed to get OneDrive download URL', [
                'path' => $path,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get OneDrive web URL for file
     * 
     * @param string $path File path on OneDrive
     * @return string Web URL
     */
    public function getWebUrl($path)
    {
        try {
            $endpoint = "/me/drive/root:/{$path}";
            
            $driveItem = $this->graph->createRequest("GET", $endpoint)
                ->setReturnType(Model\DriveItem::class)
                ->execute();

            return $driveItem->getWebUrl();
        } catch (\Exception $e) {
            Log::error('Failed to get OneDrive web URL', [
                'path' => $path,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Delete file from OneDrive
     * 
     * @param string $path File path on OneDrive
     * @return bool
     */
    public function deleteFile($path)
    {
        try {
            $endpoint = "/me/drive/root:/{$path}";
            
            $this->graph->createRequest("DELETE", $endpoint)->execute();
            
            Log::info('File deleted from OneDrive', ['path' => $path]);
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to delete file from OneDrive', [
                'path' => $path,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Create folder in OneDrive
     * 
     * @param string $folderPath Folder path
     * @return array Folder details
     */
    public function createFolder($folderPath)
    {
        try {
            $parts = explode('/', trim($folderPath, '/'));
            $folderName = array_pop($parts);
            $parentPath = implode('/', $parts);
            
            $rootFolder = config('filesystems.disks.onedrive.root', '/SupplyChainDocs');
            $fullParentPath = $rootFolder . '/' . $parentPath;
            
            $endpoint = "/me/drive/root:/{$fullParentPath}:/children";
            
            $folder = $this->graph->createRequest("POST", $endpoint)
                ->attachBody([
                    'name' => $folderName,
                    'folder' => new \stdClass(),
                    '@microsoft.graph.conflictBehavior' => 'rename'
                ])
                ->setReturnType(Model\DriveItem::class)
                ->execute();

            return [
                'id' => $folder->getId(),
                'name' => $folder->getName(),
                'webUrl' => $folder->getWebUrl(),
            ];
        } catch (\Exception $e) {
            Log::error('Failed to create OneDrive folder', [
                'path' => $folderPath,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get folder web URL
     * 
     * @param string $folderPath Folder path
     * @return string|null Web URL
     */
    public function getFolderWebUrl($folderPath)
    {
        try {
            $rootFolder = config('filesystems.disks.onedrive.root', '/SupplyChainDocs');
            $fullPath = $rootFolder . '/' . trim($folderPath, '/');
            
            $endpoint = "/me/drive/root:/{$fullPath}";
            
            $driveItem = $this->graph->createRequest("GET", $endpoint)
                ->setReturnType(Model\DriveItem::class)
                ->execute();

            return $driveItem->getWebUrl();
        } catch (\Exception $e) {
            Log::warning('Folder not found or error', [
                'path' => $folderPath,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Sanitize file name for OneDrive
     * OneDrive doesn't allow certain characters: " * : < > ? / \ |
     */
    protected function sanitizeFileName($fileName)
    {
        $fileName = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '_', $fileName);
        return $fileName;
    }

    /**
     * Get sharing link for file
     * 
     * @param string $path File path
     * @param string $type Type of link: 'view' or 'edit'
     * @return string Sharing link
     */
    public function getSharingLink($path, $type = 'view')
    {
        try {
            $endpoint = "/me/drive/root:/{$path}:/createLink";
            
            $response = $this->graph->createRequest("POST", $endpoint)
                ->attachBody([
                    'type' => $type,
                    'scope' => 'organization' // Only people in your organization
                ])
                ->execute();

            $data = $response->getBody();
            return $data['link']['webUrl'];
        } catch (\Exception $e) {
            Log::error('Failed to create sharing link', [
                'path' => $path,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
}
```

### Step 5: Update MRF Controller to Use OneDrive

Edit `app/Http/Controllers/Api/MRFWorkflowController.php`:

```php
use App\Services\OneDriveService;

class MRFWorkflowController extends Controller
{
    protected NotificationService $notificationService;
    protected EmailService $emailService;
    protected OneDriveService $oneDriveService;

    public function __construct(
        NotificationService $notificationService, 
        EmailService $emailService,
        OneDriveService $oneDriveService
    ) {
        $this->notificationService = $notificationService;
        $this->emailService = $emailService;
        $this->oneDriveService = $oneDriveService;
    }

    public function generatePO(Request $request, $id)
    {
        // ... existing validation code ...

        // Upload to OneDrive instead of local storage
        if ($request->hasFile('unsigned_po')) {
            $file = $request->hasFile('unsigned_po');
            
            try {
                // Upload to OneDrive in PurchaseOrders folder
                $oneDriveResult = $this->oneDriveService->uploadFile(
                    $file,
                    'PurchaseOrders/' . date('Y/m'), // Organize by year/month
                    "PO_{$poNumber}_{$mrf->mrf_id}." . $file->getClientOriginalExtension()
                );

                $poUrl = $oneDriveResult['webUrl'];
                $poPath = $oneDriveResult['path'];
                $poOneDriveId = $oneDriveResult['id'];

                Log::info('PO uploaded to OneDrive', [
                    'mrf_id' => $id,
                    'po_number' => $poNumber,
                    'onedrive_path' => $poPath,
                    'web_url' => $poUrl,
                ]);
            } catch (\Exception $e) {
                return response()->json([
                    'success' => false,
                    'error' => 'Failed to upload PO to OneDrive: ' . $e->getMessage(),
                    'code' => 'ONEDRIVE_ERROR'
                ], 500);
            }
        }

        // Update MRF with OneDrive details
        $mrf->update([
            'po_number' => $poNumber,
            'unsigned_po_url' => $poUrl,
            'unsigned_po_path' => $poPath, // Store OneDrive path
            'unsigned_po_onedrive_id' => $poOneDriveId, // Store OneDrive file ID
            'po_generated_at' => now(),
            'status' => 'supply_chain',
            'current_stage' => 'supply_chain',
        ]);

        // ... rest of the code ...
    }
}
```

---

## Phase 3: Frontend Integration

### Step 1: Add OneDrive Icons and Buttons

Create `src/components/OneDriveLink.tsx`:

```typescript
import { ExternalLink, FileText } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";

interface OneDriveLinkProps {
  webUrl?: string;
  fileName?: string;
  variant?: "button" | "badge" | "link";
  className?: string;
}

export const OneDriveLink = ({ 
  webUrl, 
  fileName, 
  variant = "button",
  className = "" 
}: OneDriveLinkProps) => {
  if (!webUrl) return null;

  if (variant === "badge") {
    return (
      <Badge 
        variant="outline" 
        className={`cursor-pointer hover:bg-blue-50 ${className}`}
        onClick={() => window.open(webUrl, '_blank')}
      >
        <FileText className="h-3 w-3 mr-1" />
        On OneDrive
        <ExternalLink className="h-3 w-3 ml-1" />
      </Badge>
    );
  }

  if (variant === "link") {
    return (
      <a
        href={webUrl}
        target="_blank"
        rel="noopener noreferrer"
        className={`text-sm text-blue-600 hover:text-blue-800 flex items-center gap-1 ${className}`}
      >
        <FileText className="h-4 w-4" />
        View on OneDrive
        <ExternalLink className="h-3 w-3" />
      </a>
    );
  }

  return (
    <Button
      variant="outline"
      size="sm"
      onClick={() => window.open(webUrl, '_blank')}
      className={className}
    >
      <FileText className="h-4 w-4 mr-2" />
      {fileName ? `View ${fileName}` : 'View on OneDrive'}
      <ExternalLink className="h-3 w-3 ml-2" />
    </Button>
  );
};
```

### Step 2: Update MRF Display to Show OneDrive Links

Example in `src/pages/SupplyChainDashboard.tsx`:

```typescript
import { OneDriveLink } from "@/components/OneDriveLink";

// In your MRF card/table row:
<div className="flex items-center gap-2">
  <p className="text-sm font-medium">
    PO: {mrf.poNumber}
  </p>
  {mrf.unsignedPoUrl && (
    <OneDriveLink 
      webUrl={mrf.unsignedPoUrl} 
      fileName={`PO-${mrf.poNumber}.pdf`}
      variant="badge"
    />
  )}
</div>
```

### Step 3: Update API Types

Edit `src/types/index.ts`:

```typescript
export interface MRF {
  // ... existing fields ...
  unsignedPoUrl?: string; // OneDrive web URL
  unsignedPoPath?: string; // OneDrive file path
  unsignedPoOneDriveId?: string; // OneDrive file ID
  signedPoUrl?: string;
  signedPoPath?: string;
  signedPoOneDriveId?: string;
}
```

---

## Phase 4: Testing

### Test Checklist

- [ ] Upload PO to OneDrive
- [ ] View PO web URL in OneDrive
- [ ] Download PO from OneDrive
- [ ] Delete old PO when regenerating
- [ ] Create folder structure (PurchaseOrders/2026/01/)
- [ ] Upload vendor documents to OneDrive
- [ ] Sharing links work within organization

---

## Phase 5: Deployment

### Production Environment Variables (Render)

Add these to Render environment variables:

```
MICROSOFT_CLIENT_ID=your_production_client_id
MICROSOFT_CLIENT_SECRET=your_production_client_secret
MICROSOFT_TENANT_ID=your_tenant_id
ONEDRIVE_DRIVE_ID=your_drive_id
ONEDRIVE_ROOT_FOLDER=/SupplyChainDocs
FILESYSTEM_DISK=onedrive
```

---

## Benefits

✅ **Unlimited Storage** (depending on your Microsoft 365 plan)
✅ **Version History** - OneDrive tracks file versions
✅ **Access Control** - Use Microsoft 365 permissions
✅ **Collaboration** - Users can view/edit in OneDrive
✅ **Backup** - Microsoft handles backups
✅ **Mobile Access** - OneDrive mobile app support
✅ **Search** - OneDrive's powerful search
✅ **Compliance** - Microsoft's enterprise security

---

## Folder Structure

```
OneDrive/
└── SupplyChainDocs/
    ├── PurchaseOrders/
    │   ├── 2026/
    │   │   ├── 01/
    │   │   │   ├── PO_PO-2026-001_MRF-2026-001.pdf
    │   │   │   └── PO_PO-2026-002_MRF-2026-002.pdf
    │   │   └── 02/
    │   └── 2025/
    ├── PurchaseOrders_Signed/
    │   └── 2026/
    │       └── 01/
    ├── Vendors/
    │   ├── VendorABC/
    │   │   ├── TIN_Certificate.pdf
    │   │   ├── CAC_Document.pdf
    │   │   └── Bank_Statement.pdf
    │   └── VendorXYZ/
    └── Quotations/
        └── 2026/
            └── Q-2026-001/
```

---

## Next Steps

1. Register Azure AD app
2. Install packages (`composer require microsoft/microsoft-graph`)
3. Create OneDriveService
4. Update controllers to use OneDrive
5. Add frontend OneDrive components
6. Test thoroughly
7. Deploy to production

Need help with any step? Let me know!
