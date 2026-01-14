<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class OneDriveService
{
    protected string $clientId;
    protected string $clientSecret;
    protected string $tenantId;
    protected string $rootFolder;
    protected string $userEmail;
    protected ?string $accessToken = null;

    public function __construct()
    {
        $this->clientId = config('filesystems.disks.onedrive.client_id');
        $this->clientSecret = config('filesystems.disks.onedrive.client_secret');
        $this->tenantId = config('filesystems.disks.onedrive.tenant_id');
        $this->rootFolder = config('filesystems.disks.onedrive.root', '/SupplyChainDocs');
        // Centralized procurement account email
        $this->userEmail = config('filesystems.disks.onedrive.user_email', 'procurement@emeraldcfze.com');
    }

    /**
     * Get the base endpoint for the centralized procurement OneDrive account
     */
    protected function getDriveEndpoint(string $path = ''): string
    {
        $userEmail = urlencode($this->userEmail);
        $baseEndpoint = "https://graph.microsoft.com/v1.0/users/{$userEmail}/drive";
        return $path ? "{$baseEndpoint}{$path}" : $baseEndpoint;
    }

    /**
     * Get access token using client credentials flow
     * Tokens are cached for 50 minutes (tokens expire after 1 hour)
     */
    protected function getAccessToken(): string
    {
        if ($this->accessToken) {
            return $this->accessToken;
        }

        // Try to get from cache first
        $this->accessToken = Cache::remember('onedrive_access_token', 50 * 60, function () {
            $tokenUrl = "https://login.microsoftonline.com/{$this->tenantId}/oauth2/v2.0/token";
            
            try {
                $response = Http::asForm()->post($tokenUrl, [
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'scope' => 'https://graph.microsoft.com/.default',
                    'grant_type' => 'client_credentials',
                ]);

                if (!$response->successful()) {
                    Log::error('OneDrive token request failed', [
                        'status' => $response->status(),
                        'body' => $response->body()
                    ]);
                    throw new \Exception('Failed to authenticate with OneDrive: ' . $response->body());
                }

                $data = $response->json();
                return $data['access_token'];
            } catch (\Exception $e) {
                Log::error('OneDrive authentication error', [
                    'error' => $e->getMessage()
                ]);
                throw $e;
            }
        });

        return $this->accessToken;
    }

    /**
     * Upload file to OneDrive
     * 
     * @param UploadedFile $file
     * @param string $folder Folder path (e.g., 'PurchaseOrders/2026/01')
     * @param string|null $fileName Optional custom file name
     * @return array ['path' => string, 'webUrl' => string, 'id' => string, 'name' => string]
     */
    public function uploadFile(UploadedFile $file, string $folder = '', ?string $fileName = null): array
    {
        try {
            $fileName = $fileName ?? $file->getClientOriginalName();
            $fileName = $this->sanitizeFileName($fileName);
            
            // Construct full path
            $rootFolder = trim($this->rootFolder, '/');
            $folderPath = trim($folder, '/');
            $fullPath = $rootFolder . ($folderPath ? '/' . $folderPath : '') . '/' . $fileName;
            
            Log::info('Uploading file to OneDrive', [
                'file_name' => $fileName,
                'path' => $fullPath,
                'size' => $file->getSize()
            ]);

            $accessToken = $this->getAccessToken();

            // For files under 4MB, use simple upload
            if ($file->getSize() < 4 * 1024 * 1024) {
                return $this->simpleUpload($file, $fullPath, $accessToken);
            } else {
                // For larger files, use upload session
                return $this->uploadLargeFile($file, $fullPath, $accessToken);
            }
        } catch (\Exception $e) {
            Log::error('OneDrive upload failed', [
                'error' => $e->getMessage(),
                'file' => $fileName ?? 'unknown'
            ]);
            throw $e;
        }
    }

    /**
     * Simple upload for files < 4MB
     */
    protected function simpleUpload(UploadedFile $file, string $fullPath, string $accessToken): array
    {
        $endpoint = $this->getDriveEndpoint("/root:/{$fullPath}:/content");
        
        $response = Http::withToken($accessToken)
            ->withBody($file->getContent(), 'application/octet-stream')
            ->put($endpoint);

        if (!$response->successful()) {
            Log::error('OneDrive simple upload failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'path' => $fullPath
            ]);
            throw new \Exception('Failed to upload file to OneDrive: ' . $response->body());
        }

        $data = $response->json();

        return [
            'path' => $fullPath,
            'webUrl' => $data['webUrl'] ?? null,
            'id' => $data['id'] ?? null,
            'name' => $data['name'] ?? basename($fullPath),
        ];
    }

    /**
     * Upload large file (>4MB) using upload session
     */
    protected function uploadLargeFile(UploadedFile $file, string $fullPath, string $accessToken): array
    {
        // Create upload session
        $sessionEndpoint = $this->getDriveEndpoint("/root:/{$fullPath}:/createUploadSession");
        
        $sessionResponse = Http::withToken($accessToken)
            ->post($sessionEndpoint, [
                'item' => [
                    '@microsoft.graph.conflictBehavior' => 'replace'
                ]
            ]);

        if (!$sessionResponse->successful()) {
            Log::error('OneDrive upload session creation failed', [
                'status' => $sessionResponse->status(),
                'body' => $sessionResponse->body()
            ]);
            throw new \Exception('Failed to create upload session: ' . $sessionResponse->body());
        }

        $sessionData = $sessionResponse->json();
        $uploadUrl = $sessionData['uploadUrl'];
        $fileSize = $file->getSize();
        $chunkSize = 320 * 1024 * 10; // 3.2MB chunks
        $offset = 0;

        $fileHandle = fopen($file->getRealPath(), 'r');

        while (!feof($fileHandle)) {
            $chunk = fread($fileHandle, $chunkSize);
            $chunkLength = strlen($chunk);
            
            if ($chunkLength === 0) break;
            
            $end = $offset + $chunkLength - 1;

            $chunkResponse = Http::withHeaders([
                'Content-Length' => (string)$chunkLength,
                'Content-Range' => "bytes {$offset}-{$end}/{$fileSize}",
            ])
            ->withBody($chunk, 'application/octet-stream')
            ->put($uploadUrl);

            if (!$chunkResponse->successful() && $chunkResponse->status() !== 202) {
                fclose($fileHandle);
                Log::error('OneDrive chunk upload failed', [
                    'status' => $chunkResponse->status(),
                    'body' => $chunkResponse->body(),
                    'offset' => $offset
                ]);
                throw new \Exception('Failed to upload chunk: ' . $chunkResponse->body());
            }

            $offset += $chunkLength;

            // If status is 200 or 201, upload is complete
            if (in_array($chunkResponse->status(), [200, 201])) {
                $result = $chunkResponse->json();
                fclose($fileHandle);
                
                return [
                    'path' => $fullPath,
                    'webUrl' => $result['webUrl'] ?? null,
                    'id' => $result['id'] ?? null,
                    'name' => $result['name'] ?? basename($fullPath),
                ];
            }
        }

        fclose($fileHandle);
        throw new \Exception('Upload session completed but no final response received');
    }

    /**
     * Get file download URL from OneDrive
     * 
     * @param string $path File path on OneDrive
     * @return string|null Download URL
     */
    public function getDownloadUrl(string $path): ?string
    {
        try {
            $accessToken = $this->getAccessToken();
            $endpoint = $this->getDriveEndpoint("/root:/{$path}");
            
            $response = Http::withToken($accessToken)->get($endpoint);
            
            if (!$response->successful()) {
                Log::error('Failed to get OneDrive file', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'path' => $path
                ]);
                return null;
            }

            $data = $response->json();
            return $data['@microsoft.graph.downloadUrl'] ?? null;
        } catch (\Exception $e) {
            Log::error('Failed to get OneDrive download URL', [
                'path' => $path,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Get OneDrive web URL for file
     * 
     * @param string $path File path on OneDrive
     * @return string|null Web URL
     */
    public function getWebUrl(string $path): ?string
    {
        try {
            $accessToken = $this->getAccessToken();
            $endpoint = $this->getDriveEndpoint("/root:/{$path}");
            
            $response = Http::withToken($accessToken)->get($endpoint);
            
            if (!$response->successful()) {
                Log::error('Failed to get OneDrive file web URL', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'path' => $path
                ]);
                return null;
            }

            $data = $response->json();
            return $data['webUrl'] ?? null;
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
    public function deleteFile(string $path): bool
    {
        try {
            $accessToken = $this->getAccessToken();
            $endpoint = $this->getDriveEndpoint("/root:/{$path}");
            
            $response = Http::withToken($accessToken)->delete($endpoint);
            
            if ($response->successful() || $response->status() === 404) {
                Log::info('File deleted from OneDrive', ['path' => $path]);
                return true;
            }

            Log::error('Failed to delete file from OneDrive', [
                'status' => $response->status(),
                'body' => $response->body(),
                'path' => $path
            ]);
            return false;
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
     * @return array|null Folder details
     */
    public function createFolder(string $folderPath): ?array
    {
        try {
            $parts = explode('/', trim($folderPath, '/'));
            $folderName = array_pop($parts);
            $parentPath = implode('/', $parts);
            
            $rootFolder = trim($this->rootFolder, '/');
            $fullParentPath = $rootFolder . ($parentPath ? '/' . $parentPath : '');
            
            $accessToken = $this->getAccessToken();
            $endpoint = $this->getDriveEndpoint("/root:/{$fullParentPath}:/children");
            
            $response = Http::withToken($accessToken)
                ->post($endpoint, [
                    'name' => $folderName,
                    'folder' => new \stdClass(),
                    '@microsoft.graph.conflictBehavior' => 'rename'
                ]);
            
            if (!$response->successful()) {
                Log::error('Failed to create OneDrive folder', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'path' => $folderPath
                ]);
                return null;
            }

            $data = $response->json();
            return [
                'id' => $data['id'] ?? null,
                'name' => $data['name'] ?? $folderName,
                'webUrl' => $data['webUrl'] ?? null,
            ];
        } catch (\Exception $e) {
            Log::error('Failed to create OneDrive folder', [
                'path' => $folderPath,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Get folder web URL
     * 
     * @param string $folderPath Folder path
     * @return string|null Web URL
     */
    public function getFolderWebUrl(string $folderPath): ?string
    {
        try {
            $rootFolder = trim($this->rootFolder, '/');
            $fullPath = $rootFolder . '/' . trim($folderPath, '/');
            
            $accessToken = $this->getAccessToken();
            $endpoint = $this->getDriveEndpoint("/root:/{$fullPath}");
            
            $response = Http::withToken($accessToken)->get($endpoint);
            
            if (!$response->successful()) {
                Log::warning('Folder not found or error', [
                    'status' => $response->status(),
                    'path' => $folderPath
                ]);
                return null;
            }

            $data = $response->json();
            return $data['webUrl'] ?? null;
        } catch (\Exception $e) {
            Log::warning('Failed to get OneDrive folder URL', [
                'path' => $folderPath,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Create view-only sharing link for file
     * 
     * @param string $path File path on OneDrive
     * @param string $type Link type: 'view' or 'edit' (default: 'view')
     * @return string|null Sharing link URL
     */
    public function createSharingLink(string $path, string $type = 'view'): ?string
    {
        try {
            $accessToken = $this->getAccessToken();
            $endpoint = $this->getDriveEndpoint("/root:/{$path}:/createLink");
            
            $response = Http::withToken($accessToken)
                ->post($endpoint, [
                    'type' => $type, // 'view' for view-only, 'edit' for edit access
                    'scope' => 'organization', // Only people in your organization
                ]);
            
            if (!$response->successful()) {
                Log::error('Failed to create OneDrive sharing link', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'path' => $path
                ]);
                return null;
            }

            $data = $response->json();
            return $data['link']['webUrl'] ?? null;
        } catch (\Exception $e) {
            Log::error('Failed to create OneDrive sharing link', [
                'path' => $path,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Get file ID from path (needed for some operations)
     * 
     * @param string $path File path on OneDrive
     * @return string|null File ID
     */
    public function getFileId(string $path): ?string
    {
        try {
            $accessToken = $this->getAccessToken();
            $endpoint = $this->getDriveEndpoint("/root:/{$path}");
            
            $response = Http::withToken($accessToken)->get($endpoint);
            
            if (!$response->successful()) {
                return null;
            }

            $data = $response->json();
            return $data['id'] ?? null;
        } catch (\Exception $e) {
            Log::error('Failed to get OneDrive file ID', [
                'path' => $path,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Sanitize file name for OneDrive
     * OneDrive doesn't allow: " * : < > ? / \ |
     */
    protected function sanitizeFileName(string $fileName): string
    {
        $fileName = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '_', $fileName);
        return $fileName;
    }
}
