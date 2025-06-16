<?php
// Set UTF-8 encoding for proper Cyrillic support
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');
ini_set('default_charset', 'UTF-8');

// Configuration
$baseDir = '../uploads/';

function sanitizePath($path) {
    $path = str_replace(['../', '..\\', '\\'], '', $path);
    $path = trim($path, '/\\');
    return $path;
}

function getMimeType($filename) {
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    
    $mimeTypes = [
        'txt' => 'text/plain',
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'zip' => 'application/zip',
        'tar' => 'application/x-tar',
        'gz' => 'application/gzip',
        'bz2' => 'application/x-bzip2',
        '7z' => 'application/x-7z-compressed',
        'rar' => 'application/x-rar-compressed',
        'mp3' => 'audio/mpeg',
        'mp4' => 'video/mp4',
        'avi' => 'video/x-msvideo',
        'mov' => 'video/quicktime'
    ];
    
    return isset($mimeTypes[$extension]) ? $mimeTypes[$extension] : 'application/octet-stream';
}

try {
    if (!isset($_GET['name']) || empty($_GET['name'])) {
        throw new Exception('Имя файла не указано');
    }
    
    $fileName = $_GET['name'];
    $requestedPath = isset($_GET['path']) ? sanitizePath($_GET['path']) : '';
    
    $filePath = $baseDir . $requestedPath . '/' . $fileName;
    
    // Security check
    $realBasePath = realpath($baseDir);
    $realFilePath = realpath($filePath);
    
    if (!$realFilePath || strpos($realFilePath, $realBasePath) !== 0) {
        throw new Exception('Недопустимый путь к файлу');
    }
    
    // Check if file exists and is actually a file
    if (!file_exists($filePath) || !is_file($filePath)) {
        throw new Exception('Файл не найден');
    }
    
    // Get file info
    $fileSize = filesize($filePath);
    $mimeType = getMimeType($fileName);
    
    // Set headers for download with proper UTF-8 filename encoding
    header('Content-Type: ' . $mimeType);
    
    // Encode filename for proper Cyrillic support in different browsers
    $encodedFileName = rawurlencode(basename($fileName));
    $utfFileName = basename($fileName);
    
    header('Content-Disposition: attachment; filename="' . $utfFileName . '"; filename*=UTF-8\'\'' . $encodedFileName);
    header('Content-Length: ' . $fileSize);
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // Output file
    if ($fileSize > 0) {
        $handle = fopen($filePath, 'rb');
        if ($handle) {
            while (!feof($handle)) {
                echo fread($handle, 8192);
                flush();
            }
            fclose($handle);
        } else {
            throw new Exception('Не удалось открыть файл для чтения');
        }
    }
    
} catch (Exception $e) {
    // Clear any output buffers
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    // Send error response
    header('HTTP/1.1 404 Not Found');
    header('Content-Type: text/plain');
    echo 'Ошибка: ' . $e->getMessage();
}
?>