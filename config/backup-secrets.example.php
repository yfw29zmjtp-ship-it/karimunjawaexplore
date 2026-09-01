<?php

/**
 * Daily Backup - Secret Configuration TEMPLATE
 *
 * Copy this file to `config/backup-secrets.php` (same folder) and fill in
 * the real values below. `config/backup-secrets.php` is in .gitignore and
 * will NEVER be committed to git - keep it that way (it holds real secrets).
 *
 * Where to get the Backblaze B2 values:
 *   1. Sign up free at https://www.backblaze.com/cloud-storage
 *   2. Create a Bucket (private is fine) -> note the Bucket Name (or Bucket ID)
 *   3. Go to "Application Keys" -> "Add a New Application Key"
 *      -> scope it to just that bucket, Read and Write access
 *   4. Copy the "keyID" and "applicationKey" shown ONCE at creation time
 */

// ---- Backblaze B2 credentials ----
define('B2_KEY_ID', 'GANTI_dengan_keyID_dari_B2');
define('B2_APPLICATION_KEY', 'GANTI_dengan_applicationKey_dari_B2');

// Either set the bucket name (resolved automatically) OR the bucket ID directly
// (bucket ID is slightly faster - one less API call - find it on the bucket's
// detail page in the B2 dashboard).
define('B2_BUCKET_NAME', 'GANTI_nama_bucket');
// define('B2_BUCKET_ID', 'GANTI_bucket_id');

// ---- Secret token to allow triggering the cron over HTTP ----
// Generate any long random string, e.g. via: bin2hex(random_bytes(16))
define('BACKUP_CRON_TOKEN', 'GANTI_dengan_token_rahasia_panjang');

// ---- How many days of local .zip backups to keep as a fallback ----
// (files are only deleted once older than this, regardless of B2 upload status)
define('BACKUP_LOCAL_RETENTION_DAYS', 2);

// ---- Business IDs to SKIP (demo/test businesses, optional) ----
define('BACKUP_EXCLUDE_BUSINESS_IDS', ['demo', 'warung-makan-test']);
