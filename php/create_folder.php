<?php
// Disable error output to prevent JSON corruption
ini_set('display_errors', 0);
error_reporting(0);

// Start output buffering to catch any unexpected output
ob_start();

header('Content-Type: application/json');
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

function sanitizeFolderName($name) {
    // Ensure proper UTF-8 encoding
    if (!mb_check_encoding($name, 'UTF-8')) {
        // Try to convert from various encodings
        $encodings = ['Windows-1251', 'CP1251', 'ISO-8859-1', 'CP866'];
        foreach ($encodings as $encoding) {
            $converted = mb_convert_encoding($name, 'UTF-8', $encoding);
            if (mb_check_encoding($converted, 'UTF-8')) {
                $name = $converted;
                break;
            }
        }
    }
    
    // Remove only truly dangerous characters, keep Cyrillic and normal ones
    $name = preg_replace('/[<>:"|?*]/', '_', $name);
    // Remove path separators but allow normal characters
    $name = str_replace(['/', '\\'], '_', $name);
    // Trim whitespace
    $name = trim($name);
    // Remove leading/trailing dots to prevent hidden folders issues
    $name = trim($name, '.');
    
    return $name;
}

function sendJsonResponse($data) {
    // Clear any unexpected output
    ob_clean();
    echo json_encode($data);
    ob_end_flush();
    exit();
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
        throw new Exception('Ошибка декодирования JSON: ' . json_last_error_msg());
    }
    
    if (!isset($input['name']) || empty(trim($input['name']))) {
        throw new Exception('Имя папки не указано');
    }
    
    $folderName = sanitizeFolderName($input['name']);
    $requestedPath = isset($input['path']) ? sanitizePath($input['path']) : '';
    
    if (empty($folderName) || strlen($folderName) < 1) {
        throw new Exception('Имя папки не может быть пустым');
    }
    
    if (strlen($folderName) > 255) {
        throw new Exception('Имя папки слишком длинное (максимум 255 символов)');
    }
    
    // Check for reserved names on Windows
    $reservedNames = ['CON', 'PRN', 'AUX', 'NUL', 'COM1', 'COM2', 'COM3', 'COM4', 'COM5', 'COM6', 'COM7', 'COM8', 'COM9', 'LPT1', 'LPT2', 'LPT3', 'LPT4', 'LPT5', 'LPT6', 'LPT7', 'LPT8', 'LPT9'];
    if (in_array(strtoupper($folderName), $reservedNames)) {
        throw new Exception('Недопустимое имя папки (зарезервированное системное имя)');
    }
    
    $parentDir = $baseDir . $requestedPath;
    $newFolderPath = $parentDir . '/' . $folderName;
    
    // Ensure parent directory exists
    if (!is_dir($parentDir)) {
        if (!mkdir($parentDir, 0755, true)) {
            throw new Exception('Не удалось создать родительскую директорию');
        }
    }
    
    // Security check
    $realBasePath = realpath($baseDir);
    $realParentPath = realpath($parentDir);
    
    if (!$realParentPath || ($realBasePath && strpos($realParentPath, $realBasePath) !== 0)) {
        throw new Exception('Недопустимый путь');
    }
    
    // Check if folder already exists
    if (file_exists($newFolderPath)) {
        throw new Exception('Папка с таким именем уже существует');
    }
    
    // Create folder
    if (!mkdir($newFolderPath, 0755)) {
        $error = error_get_last();
        throw new Exception('Не удалось создать папку: ' . ($error ? $error['message'] : 'неизвестная ошибка'));
    }
    
    sendJsonResponse([
        'success' => true,
        'message' => 'Папка создана успешно',
        'folderName' => $folderName
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