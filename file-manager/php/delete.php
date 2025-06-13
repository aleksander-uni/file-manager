<?php
// Set UTF-8 encoding for proper Cyrillic support
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');
ini_set('default_charset', 'UTF-8');

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
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
    
    if (!isset($input['names']) || !is_array($input['names']) || empty($input['names'])) {
        throw new Exception('Нет элементов для удаления');
    }
    
    $names = $input['names'];
    $requestedPath = isset($input['path']) ? sanitizePath($input['path']) : '';
    
    $parentDir = $baseDir . $requestedPath;
    
    $realBasePath = realpath($baseDir);
    $realParentPath = realpath($parentDir);
    
    if (!$realParentPath || strpos($realParentPath, $realBasePath) !== 0) {
        throw new Exception('Недопустимый путь');
    }
    
    $errors = [];
    
    foreach ($names as $itemName) {
        $itemPath = $parentDir . '/' . $itemName;
        $realItemPath = realpath($itemPath);
        
        if (!$realItemPath || strpos($realItemPath, $realBasePath) !== 0) {
            $errors[] = "Недопустимый путь для элемента: $itemName";
            continue;
        }
        
        if (!file_exists($realItemPath)) {
            $errors[] = "Элемент не найден: $itemName";
            continue;
        }
        
        if (is_dir($realItemPath)) {
            if (!is_writable($realItemPath)) {
                $errors[] = "Недостаточно прав для удаления папки: $itemName";
                continue;
            }
            if (!deleteDirectory($realItemPath)) {
                $errors[] = "Не удалось удалить папку: $itemName";
            }
        } elseif (is_file($realItemPath)) {
            if (!is_writable($realItemPath)) {
                $errors[] = "Недостаточно прав для удаления файла: $itemName";
                continue;
            }
            if (!unlink($realItemPath)) {
                $errors[] = "Не удалось удалить файл: $itemName";
            }
        } else {
            $errors[] = "Тип элемента не соответствует ожидаемому: $itemName";
        }
    }
    
    if (count($errors) > 0) {
        echo json_encode([
            'success' => false,
            'error' => implode('; ', $errors)
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'message' => 'Элементы успешно удалены'
        ]);
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>