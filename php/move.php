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

// Configuration
$baseDir = '../uploads/';

function sanitizePath($path) {
    $path = str_replace(['../', '..\\', '\\'], '', $path);
    $path = trim($path, '/\\');
    return $path;
}

function sendJsonResponse($data) {
    // Clear any unexpected output
    ob_clean();
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    ob_end_flush();
    exit();
}

function moveRecursive($source, $destination) {
    if (!file_exists($source)) {
        return false;
    }
    
    if (is_file($source)) {
        return rename($source, $destination);
    }
    
    if (is_dir($source)) {
        if (!is_dir($destination)) {
            mkdir($destination, 0755, true);
        }
        
        $items = scandir($source);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            
            $sourcePath = $source . '/' . $item;
            $destPath = $destination . '/' . $item;
            
            if (!moveRecursive($sourcePath, $destPath)) {
                return false;
            }
        }
        
        return rmdir($source);
    }
    
    return false;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Метод не поддерживается: ' . $_SERVER['REQUEST_METHOD']);
    }
    
    // Get input data
    $rawInput = file_get_contents('php://input');
    
    if (empty($rawInput)) {
        throw new Exception('Пустые данные запроса');
    }
    
    $input = json_decode($rawInput, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Ошибка парсинга JSON: ' . json_last_error_msg());
    }
    
    // Validate required fields
    if (!isset($input['source']) || !isset($input['destination'])) {
        throw new Exception('Отсутствуют обязательные поля: source, destination');
    }
    
    $sourcePath = sanitizePath($input['source']);
    $destinationPath = sanitizePath($input['destination']);
    
    if (empty($sourcePath)) {
        throw new Exception('Не указан источник для перемещения');
    }
    
    if (empty($destinationPath)) {
        throw new Exception('Не указано назначение для перемещения');
    }
    
    // Build full paths
    $fullSourcePath = $baseDir . $sourcePath;
    $fullDestinationPath = $baseDir . $destinationPath;
    
    // Security check: ensure we're not going outside the base directory
    $realBasePath = realpath($baseDir);
    $realSourcePath = realpath(dirname($fullSourcePath));
    $realDestinationPath = realpath(dirname($fullDestinationPath));
    
    if (!$realSourcePath || strpos($realSourcePath, $realBasePath) !== 0) {
        throw new Exception('Недопустимый путь источника');
    }
    
    if (!$realDestinationPath || strpos($realDestinationPath, $realBasePath) !== 0) {
        throw new Exception('Недопустимый путь назначения');
    }
    
    // Check if source exists
    if (!file_exists($fullSourcePath)) {
        throw new Exception('Источник не найден: ' . $sourcePath);
    }
    
    // Check if destination already exists
    if (file_exists($fullDestinationPath)) {
        throw new Exception('Файл или папка с таким именем уже существует в назначении');
    }
    
    // Check if trying to move into itself (for directories)
    if (is_dir($fullSourcePath) && strpos($fullDestinationPath, $fullSourcePath) === 0) {
        throw new Exception('Нельзя переместить папку в саму себя');
    }
    
    // Create destination directory if it doesn't exist
    $destinationDir = dirname($fullDestinationPath);
    if (!is_dir($destinationDir)) {
        if (!mkdir($destinationDir, 0755, true)) {
            throw new Exception('Не удалось создать папку назначения');
        }
    }
    
    // Perform the move operation
    if (moveRecursive($fullSourcePath, $fullDestinationPath)) {
        sendJsonResponse([
            'success' => true,
            'message' => 'Элемент успешно перемещен',
            'source' => $sourcePath,
            'destination' => $destinationPath
        ]);
    } else {
        throw new Exception('Не удалось переместить элемент');
    }
    
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
}
?>