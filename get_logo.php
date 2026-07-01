<?php
// Start output buffering to prevent any accidental whitespace or output from config.php
ob_start();
require_once __DIR__ . '/config.php';
ob_clean();

$logo_data = null;
$mime_type = 'image/jpeg';

// Try to load from database first (SYSTEM logo or School logo)
if (isset($pdo)) {
    try {
        // We can check $_SESSION or fallback to 'SYSTEM' or the standard school code
        $school_code = $_SESSION['school_code'] ?? '31054002';
        $stmt = $pdo->prepare("SELECT setting_value FROM school_settings WHERE (school_code = ? OR school_code = 'SYSTEM') AND setting_key = 'system_logo' ORDER BY FIELD(school_code, ?, 'SYSTEM') LIMIT 1");
        $stmt->execute([$school_code, $school_code]);
        $db_logo = $stmt->fetchColumn();
        
        if ($db_logo && strpos($db_logo, 'data:image/') === 0) {
            $parts = explode(',', $db_logo);
            if (isset($parts[1])) {
                $logo_data = base64_decode($parts[1]);
                if (preg_match('/^data:(image\/[a-z]+);base64/', $db_logo, $matches)) {
                    $mime_type = $matches[1];
                }
            }
        }
    } catch (Exception $e) {
        // Fallback to file system
    }
}

// Fallback to local file if not found in database
if (!$logo_data) {
    $fallback_file = __DIR__ . '/src/assets/images/pwa_app_icon.jpg';
    if (file_exists($fallback_file)) {
        $logo_data = file_get_contents($fallback_file);
        $mime_type = 'image/jpeg';
    }
}

if ($logo_data) {
    // Clear any previous headers (including the text/html header sent by config.php)
    header_remove('Content-Type');
    
    // Set proper headers for caching and mime type
    header('Content-Type: ' . $mime_type);
    header('Cache-Control: public, max-age=31536000, immutable'); // Long-term caching since we use version cache busters
    header('Content-Length: ' . strlen($logo_data));
    echo $logo_data;
    exit;
} else {
    header('HTTP/1.1 404 Not Found');
    exit;
}
