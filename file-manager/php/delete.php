<?php
// Set UTF-8 encoding for proper Cyrillic support
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');
ini_set('default_charset', 'UTF-8');

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Configuration
$baseDir = '../uploads/';

function sanitizePath($path) {
    $path = str_replace(['../', '..\\', '\\'], '', $path);
    $path = trim($path, '/\\');
    return $path;
}

function deleteDirectory($dir) {
    if (!is_dir($dir)) {
        error_log("Delete error: Directory does not exist: $dir");
        return false;
    }
    
    // Check if directory is readable
    if (!is_readable($dir)) {
        error_log("Delete error: Directory is not readable: $dir");
        return false;
    }
    
    $files = scandir($dir);
    if ($files === false) {
        error_log("Delete error: Cannot scan directory: $dir");
        return false;
    }
    
    $files = array_diff($files, ['.', '..']);
    
    foreach ($files as $file) {
        $filePath = $dir . DIRECTORY_SEPARATOR . $file;
        
        if (is_dir($filePath)) {
            if (!deleteDirectory($filePath)) {
                error_log("Delete error: Failed to delete subdirectory: $filePath");
                return false;
            }
        } else {
            if (!unlink($filePath)) {
                error_log("Delete error: Failed to delete file: $filePath");
                return false;
            }
        }
    }
    
    // Try to remove the directory
    if (!rmdir($dir)) {
        error_log("Delete error: Failed to remove directory: $dir");
        return false;
    }
    
    return true;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Метод не поддерживается');
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['name']) || empty($input['name'])) {
        throw new Exception('Имя элемента не указано');
    }
    
    $itemName = $input['name'];
    $requestedPath = isset($input['path']) ? sanitizePath($input['path']) : '';
    $isDirectory = isset($input['isDirectory']) ? $input['isDirectory'] : false;
    
    $parentDir = $baseDir . $requestedPath;
    $itemPath = $parentDir . '/' . $itemName;
    
    // Security check
    $realBasePath = realpath($baseDir);
    $realItemPath = realpath($itemPath);
    
    if (!$realItemPath || strpos($realItemPath, $realBasePath) !== 0) {
        throw new Exception('Недопустимый путь');
    }
    
    // Check if item exists
    if (!file_exists($itemPath)) {
        throw new Exception('Элемент не найден: ' . $itemPath);
    }
    
    // Additional checks for directories
    if ($isDirectory && is_dir($itemPath)) {
        // Check if directory is empty
        $files = scandir($itemPath);
        if ($files === false) {
            throw new Exception('Не удается прочитать содержимое папки');
        }
        
        $files = array_diff($files, ['.', '..']);
        $fileCount = count($files);
        
        error_log("Deleting directory: $itemPath (contains $fileCount items)");
        
        // Check permissions
        if (!is_writable($itemPath)) {
            throw new Exception('Недостаточно прав для удаления папки');
        }
        
        if (!deleteDirectory($itemPath)) {
            throw new Exception('Не удалось удалить папку. Проверьте права доступа и содержимое папки.');
        }
    } elseif (!$isDirectory && is_file($itemPath)) {
        // Check permissions for file
        if (!is_writable($itemPath)) {
            throw new Exception('Недостаточно прав для удаления файла');
        }
        
        if (!unlink($itemPath)) {
            throw new Exception('Не удалось удалить файл');
        }
    } else {
        throw new Exception('Тип элемента не соответствует ожидаемому');
    }
    
    echo json_encode([
        'success' => true,
        'message' => $isDirectory ? 'Папка удалена успешно' : 'Файл удален успешно'
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>