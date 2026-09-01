<?php
/**
 * CloudinaryHelper - Upload and manage images on Cloudinary
 * 
 * This helper provides a unified way to upload images to Cloudinary
 * and falls back to local storage when Cloudinary is not configured.
 * 
 * Usage:
 *   $cloudinary = CloudinaryHelper::getInstance();
 *   $result = $cloudinary->upload($_FILES['photo']['tmp_name'], 'logos');
 *   // $result = ['url' => 'https://res.cloudinary.com/...', 'public_id' => '...', 'filename' => '...']
 * 
 *   // Display with auto-optimization:
 *   $url = $cloudinary->url($result['public_id'], ['width' => 800, 'quality' => 'auto']);
 */

class CloudinaryHelper {
    private static $instance = null;
    
    private $cloudName;
    private $apiKey;
    private $apiSecret;
    private $enabled = false;
    private $baseFolder = 'adf_system'; // Root folder in Cloudinary
    
    private function __construct() {
        // Try loading from config/config.php constants first, then .env, then hardcoded fallback
        $this->cloudName  = defined('CLOUDINARY_CLOUD_NAME') ? CLOUDINARY_CLOUD_NAME : $this->getEnv('CLOUDINARY_CLOUD_NAME');
        $this->apiKey     = defined('CLOUDINARY_API_KEY') ? CLOUDINARY_API_KEY : $this->getEnv('CLOUDINARY_API_KEY');
        $this->apiSecret  = defined('CLOUDINARY_API_SECRET') ? CLOUDINARY_API_SECRET : $this->getEnv('CLOUDINARY_API_SECRET');
        
        // Fallback for hosting where .env might not be accessible
        if (!$this->cloudName) $this->cloudName = 'dpdmut9ls';
        if (!$this->apiKey) $this->apiKey = '999333726539525';
        if (!$this->apiSecret) $this->apiSecret = 'cUP7S-bH0OlrJxtndLQj_Gl2jHw';
        
        if ($this->cloudName && $this->apiKey && $this->apiSecret) {
            $this->enabled = true;
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Check if Cloudinary is configured and enabled
     */
    public function isEnabled() {
        return $this->enabled;
    }
    
    /**
     * Upload an image to Cloudinary
     * 
     * @param string $filePath  Local file path (e.g., $_FILES['photo']['tmp_name'])
     * @param string $folder    Sub-folder in Cloudinary (e.g., 'logos', 'rooms/king', 'hero')
     * @param string|null $publicId  Optional custom public_id (filename without extension)
     * @param array $options    Additional upload options
     * @return array|false      ['url' => '...', 'public_id' => '...', 'secure_url' => '...'] or false on failure
     */
    public function upload($filePath, $folder = '', $publicId = null, $options = []) {
        if (!$this->enabled) {
            return false;
        }
        
        if (!file_exists($filePath)) {
            return false;
        }
        
        $timestamp = time();
        $fullFolder = $this->baseFolder . ($folder ? '/' . $folder : '');
        
        // Build params
        $params = [
            'timestamp' => $timestamp,
            'folder' => $fullFolder,
        ];
        
        if ($publicId) {
            $params['public_id'] = $publicId;
            $params['overwrite'] = 'true';
        }
        
        // Add transformation for auto quality/format
        if (!empty($options['transformation'])) {
            $params['transformation'] = $options['transformation'];
        }
        
        // Generate signature
        $signature = $this->generateSignature($params);
        
        // Build POST data
        $postData = array_merge($params, [
            'file' => new \CURLFile($filePath),
            'api_key' => $this->apiKey,
            'signature' => $signature,
        ]);
        
        // Upload via cURL
        $url = "https://api.cloudinary.com/v1_1/{$this->cloudName}/image/upload";
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error || $httpCode !== 200) {
            error_log("Cloudinary upload error: HTTP $httpCode - $error - Response: $response");
            return false;
        }
        
        $result = json_decode($response, true);
        
        if (!$result || isset($result['error'])) {
            error_log("Cloudinary upload error: " . ($result['error']['message'] ?? 'Unknown error'));
            return false;
        }
        
        return [
            'url'        => $result['secure_url'],
            'secure_url' => $result['secure_url'],
            'public_id'  => $result['public_id'],
            'format'     => $result['format'],
            'width'      => $result['width'] ?? 0,
            'height'     => $result['height'] ?? 0,
            'bytes'      => $result['bytes'] ?? 0,
            'filename'   => basename($result['secure_url']),
        ];
    }
    
    /**
     * Upload from $_FILES array entry
     * 
     * @param array $fileEntry  e.g., $_FILES['photo']
     * @param string $folder    Sub-folder
     * @param string|null $publicId  Optional custom name
     * @return array|false
     */
    public function uploadFromFiles($fileEntry, $folder = '', $publicId = null) {
        if ($fileEntry['error'] !== UPLOAD_ERR_OK) {
            return false;
        }
        return $this->upload($fileEntry['tmp_name'], $folder, $publicId);
    }
    
    /**
     * Delete an image from Cloudinary
     * 
     * @param string $publicId  The public_id of the image to delete
     * @return bool
     */
    public function delete($publicId) {
        if (!$this->enabled || !$publicId) {
            return false;
        }
        
        $timestamp = time();
        $params = [
            'public_id' => $publicId,
            'timestamp' => $timestamp,
        ];
        
        $signature = $this->generateSignature($params);
        
        $postData = array_merge($params, [
            'api_key' => $this->apiKey,
            'signature' => $signature,
        ]);
        
        $url = "https://api.cloudinary.com/v1_1/{$this->cloudName}/image/destroy";
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        $result = json_decode($response, true);
        return isset($result['result']) && $result['result'] === 'ok';
    }
    
    /**
     * Generate optimized URL for display
     * 
     * @param string $publicIdOrUrl  Cloudinary public_id or full URL
     * @param array $options  Transformation options:
     *   'width'   => int    (resize width)
     *   'height'  => int    (resize height)
     *   'crop'    => string (fill, fit, scale, thumb, etc.)  
     *   'quality' => string (auto, auto:low, auto:good, 80, etc.)
     *   'format'  => string (auto, webp, png, jpg)
     *   'gravity' => string (auto, face, center)
     * @return string  Optimized URL
     */
    public function url($publicIdOrUrl, $options = []) {
        // If it's already a full URL (starts with http), handle it
        if (strpos($publicIdOrUrl, 'http') === 0) {
            // If it's a Cloudinary URL, we can insert transformations
            if (strpos($publicIdOrUrl, 'res.cloudinary.com') !== false) {
                return $this->addTransformationsToUrl($publicIdOrUrl, $options);
            }
            return $publicIdOrUrl;
        }
        
        // Build transformation string
        $transformations = $this->buildTransformations($options);
        $transformStr = $transformations ? $transformations . '/' : '';
        
        return "https://res.cloudinary.com/{$this->cloudName}/image/upload/{$transformStr}{$publicIdOrUrl}";
    }
    
    /**
     * Get optimized URL with auto quality and format
     * Best for displaying images — auto-compresses and serves WebP when supported
     * 
     * @param string $publicIdOrUrl
     * @param int|null $width  Optional resize width
     * @param int|null $height Optional resize height
     * @return string
     */
    public function optimizedUrl($publicIdOrUrl, $width = null, $height = null) {
        $options = [
            'quality' => 'auto',
            'format' => 'auto',
            'crop' => 'fill',
        ];
        if ($width) $options['width'] = $width;
        if ($height) $options['height'] = $height;
        
        return $this->url($publicIdOrUrl, $options);
    }
    
    /**
     * Smart upload: tries Cloudinary first, falls back to local storage
     * Returns a unified result that works with both storage methods
     * 
     * @param array $fileEntry     $_FILES['fieldname']
     * @param string $localDir     Local directory (e.g., 'uploads/logos')
     * @param string $localFilename  Filename for local storage
     * @param string $cloudFolder  Cloudinary folder (e.g., 'logos')
     * @param string|null $cloudPublicId  Optional Cloudinary public_id
     * @return array ['success' => bool, 'path' => string, 'is_cloud' => bool, 'public_id' => string|null, 'url' => string|null]
     */
    public function smartUpload($fileEntry, $localDir, $localFilename, $cloudFolder = '', $cloudPublicId = null) {
        // Validate upload
        if ($fileEntry['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'error' => 'Upload error: ' . $fileEntry['error']];
        }
        
        // Try Cloudinary first
        if ($this->enabled) {
            $result = $this->upload($fileEntry['tmp_name'], $cloudFolder, $cloudPublicId);
            if ($result) {
                return [
                    'success'   => true,
                    'path'      => $result['secure_url'],  // Full Cloudinary URL
                    'is_cloud'  => true,
                    'public_id' => $result['public_id'],
                    'url'       => $result['secure_url'],
                ];
            }
            // If Cloudinary fails, fall through to local
            error_log("Cloudinary upload failed, falling back to local storage");
        }
        
        // Fallback: local storage
        $fullDir = BASE_PATH . '/' . ltrim($localDir, '/');
        if (!is_dir($fullDir)) {
            mkdir($fullDir, 0755, true);
        }
        
        $uploadPath = $fullDir . '/' . $localFilename;
        
        if (move_uploaded_file($fileEntry['tmp_name'], $uploadPath)) {
            return [
                'success'   => true,
                'path'      => $localFilename,  // Just filename for DB (backward compatible)
                'is_cloud'  => false,
                'public_id' => null,
                'url'       => null,
            ];
        }
        
        return ['success' => false, 'error' => 'Failed to move uploaded file'];
    }
    
    /**
     * Get display URL for an image stored in DB
     * Handles both Cloudinary URLs and local paths
     * 
     * @param string $storedPath  Value from database (could be URL or local filename)
     * @param string $localPrefix  Local path prefix (e.g., 'uploads/logos/')
     * @param array $optimize     Optimization options (width, height, etc.)
     * @return string|null  Full URL or null if not found
     */
    public function getDisplayUrl($storedPath, $localPrefix = '', $optimize = []) {
        if (!$storedPath) return null;
        
        // If it's already a full URL (Cloudinary or external)
        if (strpos($storedPath, 'http') === 0) {
            // Apply Cloudinary optimizations if it's a Cloudinary URL
            if (!empty($optimize) && strpos($storedPath, 'res.cloudinary.com') !== false) {
                return $this->addTransformationsToUrl($storedPath, $optimize);
            }
            return $storedPath;
        }
        
        // Local file — check if exists and return full URL
        $localPath = BASE_PATH . '/' . ltrim($localPrefix, '/') . $storedPath;
        if (file_exists($localPath)) {
            $timestamp = filemtime($localPath);
            return BASE_URL . '/' . ltrim($localPrefix, '/') . $storedPath . '?v=' . $timestamp;
        }
        
        return null;
    }
    
    // ============================
    // Private helpers
    // ============================
    
    private function generateSignature($params) {
        // Sort params alphabetically
        ksort($params);
        
        // Build string to sign (exclude file, api_key, resource_type)
        $signParts = [];
        foreach ($params as $key => $value) {
            if (!in_array($key, ['file', 'api_key', 'resource_type'])) {
                $signParts[] = "$key=$value";
            }
        }
        
        $toSign = implode('&', $signParts) . $this->apiSecret;
        return sha1($toSign);
    }
    
    private function buildTransformations($options) {
        $parts = [];
        
        $mapping = [
            'width'   => 'w',
            'height'  => 'h',
            'crop'    => 'c',
            'quality' => 'q',
            'format'  => 'f',
            'gravity' => 'g',
            'radius'  => 'r',
            'effect'  => 'e',
        ];
        
        foreach ($options as $key => $value) {
            if (isset($mapping[$key])) {
                $parts[] = $mapping[$key] . '_' . $value;
            }
        }
        
        return implode(',', $parts);
    }
    
    private function addTransformationsToUrl($url, $options) {
        if (empty($options)) return $url;
        
        $transformations = $this->buildTransformations($options);
        if (!$transformations) return $url;
        
        // Insert transformations into Cloudinary URL
        // URL format: https://res.cloudinary.com/{cloud}/image/upload/{transformations}/{public_id}
        $pattern = '/(\/image\/upload\/)/';
        return preg_replace($pattern, '$1' . $transformations . '/', $url, 1);
    }
    
    private function getEnv($key) {
        // Check environment variable
        $value = getenv($key);
        if ($value !== false) return $value;
        
        // Check .env file
        static $envLoaded = null;
        if ($envLoaded === null) {
            $envFile = defined('BASE_PATH') ? BASE_PATH . '/.env' : dirname(dirname(__FILE__)) . '/.env';
            $envLoaded = [];
            if (file_exists($envFile)) {
                $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (empty($line) || $line[0] === '#') continue;
                    $parts = explode('=', $line, 2);
                    if (count($parts) === 2) {
                        $envLoaded[trim($parts[0])] = trim($parts[1]);
                    }
                }
            }
        }
        
        return $envLoaded[$key] ?? null;
    }
}
