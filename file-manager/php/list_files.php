<?php
// Set UTF-8 encoding for proper Cyrillic support
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');
ini_set('default_charset', 'UTF-8');

// Disable error output to prevent JSON corruption
ini_set('display_errors', 0);
error_reporting(0);

// Start output buffering to catch any unexpected output
ob_start();

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    ob_end_clean();
    http_response_code(200);
    exit();
}

function sendJsonResponse($data) {
    // Clear any unexpected output
    ob_clean();
    echo json_encode($data);
    ob_end_flush();
    exit();
}

// Configuration
$baseDir = '../uploads/';
$maxDepth = 10; // Maximum directory depth for security

function sanitizePath($path) {
    // Remove any attempts to go outside the base directory
    $path = str_replace(['../', '..\\', '\\'], '', $path);
    $path = trim($path, '/\\');
    return $path;
}

function formatFileSize($bytes) {
    if ($bytes == 0) return '0 B';
    
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $power = floor(log($bytes, 1024));
    
    return round($bytes / pow(1024, $power), 2) . ' ' . $units[$power];
}

function formatDate($timestamp) {
    return date('d.m.Y H:i', $timestamp);
}

function sanitizeFileName($filename) {
    // Ensure proper UTF-8 encoding
    if (!mb_check_encoding($filename, 'UTF-8')) {
        // Try to convert from various encodings
        $encodings = ['Windows-1251', 'CP1251', 'ISO-8859-1', 'CP866'];
        foreach ($encodings as $encoding) {
            $converted = mb_convert_encoding($filename, 'UTF-8', $encoding);
            if (mb_check_encoding($converted, 'UTF-8')) {
                $filename = $converted;
                break;
            }
        }
    }
    return $filename;
}

function getFileIcon($filename, $isDir) {
    if ($isDir) {
        return 'fas fa-folder';
    }
    
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    
    $iconMap = [
        // Images
        'jpg' => 'fas fa-file-image',
        'jpeg' => 'fas fa-file-image',
        'png' => 'fas fa-file-image',
        'gif' => 'fas fa-file-image',
        'bmp' => 'fas fa-file-image',
        'svg' => 'fas fa-file-image',
        'webp' => 'fas fa-file-image',
        
        // Documents
        'pdf' => 'fas fa-file-pdf',
        'doc' => 'fas fa-file-word',
        'docx' => 'fas fa-file-word',
        'xls' => 'fas fa-file-excel',
        'xlsx' => 'fas fa-file-excel',
        'ppt' => 'fas fa-file-powerpoint',
        'pptx' => 'fas fa-file-powerpoint',
        'txt' => 'fas fa-file-alt',
        'rtf' => 'fas fa-file-alt',
        
        // Archives
        'zip' => 'fas fa-file-archive',
        'rar' => 'fas fa-file-archive',
        '7z' => 'fas fa-file-archive',
        'tar' => 'fas fa-file-archive',
        'gz' => 'fas fa-file-archive',
        
        // Code
        'html' => 'fas fa-file-code',
        'css' => 'fas fa-file-code',
        'js' => 'fas fa-file-code',
        'php' => 'fas fa-file-code',
        'py' => 'fas fa-file-code',
        'java' => 'fas fa-file-code',
        'cpp' => 'fas fa-file-code',
        'c' => 'fas fa-file-code',
        
        // Audio
        'mp3' => 'fas fa-file-audio',
        'wav' => 'fas fa-file-audio',
        'flac' => 'fas fa-file-audio',
        'aac' => 'fas fa-file-audio',
        
        // Video
        'mp4' => 'fas fa-file-video',
        'avi' => 'fas fa-file-video',
        'mkv' => 'fas fa-file-video',
        'mov' => 'fas fa-file-video',
        'wmv' => 'fas fa-file-video',
    ];
    
    return isset($iconMap[$extension]) ? $iconMap[$extension] : 'fas fa-file';
}

try {
    $requestedPath = isset($_GET['path']) ? sanitizePath($_GET['path']) : '';
    $fullPath = $baseDir . $requestedPath;
    
    // Security check: ensure we're not going outside the base directory
    $realBasePath = realpath($baseDir);
    $realFullPath = realpath($fullPath);
    
    if (!$realFullPath || strpos($realFullPath, $realBasePath) !== 0) {
        throw new Exception('Недопустимый путь');
    }
    
    if (!is_dir($fullPath)) {
        throw new Exception('Директория не найдена');
    }
    
    $files = [];
    $items = scandir($fullPath);
    
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        
        $itemPath = $fullPath . '/' . $item;
        $isDirectory = is_dir($itemPath);
        
        // Sanitize filename for proper UTF-8 encoding
        $sanitizedName = sanitizeFileName($item);
        
        $fileInfo = [
            'name' => $sanitizedName,
            'type' => $isDirectory ? 'directory' : 'file',
            'size' => $isDirectory ? '' : formatFileSize(filesize($itemPath)),
            'modified' => formatDate(filemtime($itemPath)),
            'path' => $requestedPath ? $requestedPath . '/' . $sanitizedName : $sanitizedName,
            'icon' => getFileIcon($sanitizedName, $isDirectory)
        ];
        
        $files[] = $fileInfo;
    }
    
    sendJsonResponse([
        'success' => true,
        'files' => $files,
        'currentPath' => $requestedPath
    ]);
    
} catch (Exception $e) {
    sendJsonResponse([
        'success' => false,
        'error' => $e->getMessage()
    ]);
} catch (Error $e) {
    sendJsonResponse([
        'success' => false,
        'error' => 'Системная ошибка: ' . $e->getMessage()
    ]);
} catch (Throwable $e) {
    sendJsonResponse([
        'success' => false,
        'error' => 'Критическая ошибка: ' . $e->getMessage()
    ]);
}

// Fallback response if nothing was sent
sendJsonResponse([
    'success' => false,
    'error' => 'Неизвестная ошибка выполнения скрипта'
]);
?>