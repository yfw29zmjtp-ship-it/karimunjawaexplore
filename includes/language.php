<?php
/**
 * Language Loader - Auto language system
 * Load user's preferred language or default to Indonesian
 */

// Session is already started by config.php
// Just get user's preferred language
$userLang = $_SESSION['user_language'] ?? 'id';

// Load language file
$langFile = __DIR__ . '/languages/' . $userLang . '.php';
if (file_exists($langFile)) {
    $lang = require $langFile;
} else {
    // Fallback to Indonesian if language file not found
    $lang = require __DIR__ . '/languages/id.php';
}

/**
 * Translation function
 * @param string $key Translation key
 * @param array $replace Optional replacements for placeholders
 * @return string Translated text
 */
function __($key, $replace = []) {
    global $lang;
    
    // Get nested keys (e.g., 'dashboard.title')
    $keys = explode('.', $key);
    $value = $lang;
    
    foreach ($keys as $k) {
        if (isset($value[$k])) {
            $value = $value[$k];
        } else {
            return $key; // Return key if translation not found
        }
    }
    
    // Replace placeholders
    if (!empty($replace)) {
        foreach ($replace as $search => $replacement) {
            $value = str_replace(':' . $search, $replacement, $value);
        }
    }
    
    return $value;
}

/**
 * Get current language code
 */
function getCurrentLanguage() {
    return $_SESSION['user_language'] ?? 'id';
}
