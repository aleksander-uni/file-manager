<?php
// Set UTF-8 encoding for proper Cyrillic support
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');
ini_set('default_charset', 'UTF-8');

// Disable error output to prevent JSON corruption
ini_set('display_errors', 0);
error_reporting(0);

// Start output buffering
ob_start();

// Set JSON header
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
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

function sanitizeFilename($filename) {
    // Remove dangerous characters but keep basic ones
    $filename = preg_replace('/[<>:"|?*\\\\\/]/', '_', $filename);
    $filename = trim($filename);
    $filename = trim($filename, '.');
    return $filename;
}

function sendJsonResponse($data) {
    // Clear any output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit();
}

function addToZip($zip, $sourcePath, $zipPath = '') {
    if (is_file($sourcePath)) {
        return $zip->addFile($sourcePath, $zipPath);
    } elseif (is_dir($sourcePath)) {
        if ($zipPath !== '') {
            $zip->addEmptyDir($zipPath);
        }
        
        $files = scandir($sourcePath);
        if ($files === false) {
            return false;
        }
        
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            
            $newSourcePath = $sourcePath . '/' . $file;
            $newZipPath = $zipPath ? $zipPath . '/' . $file : $file;
            
            if (!addToZip($zip, $newSourcePath, $newZipPath)) {
                return false;
            }
        }
    }
    
    return true;
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
    
    // Debug: log received data for troubleshooting
    error_log('Archive creation request received: ' . print_r($input, true));
    
    // Validate input - support both old and new formats
    if (!isset($input['archiveName']) || empty(trim($input['archiveName']))) {
        throw new Exception('Имя архива не указано');
    }
    
    // Support both 'files' and 'items' parameters
    $files = null;
    if (isset($input['files']) && is_array($input['files'])) {
        $files = $input['files'];
    } elseif (isset($input['items']) && is_array($input['items'])) {
        // Convert items format to files format
        $files = [];
        foreach ($input['items'] as $item) {
            if (is_array($item) && isset($item['name'])) {
                $files[] = $item['name'];
            } elseif (is_string($item)) {
                $files[] = $item;
            }
        }
    }
    
    if (!$files || empty($files)) {
        error_log('Archive creation failed: No files provided. Input data: ' . print_r($input, true));
        throw new Exception('Не выбраны файлы для архивирования');
    }
    
    error_log('Archive creation: Processing ' . count($files) . ' files: ' . implode(', ', $files));
    
    $archiveName = sanitizeFilename($input['archiveName']);
    
    // Support both 'format' and 'archiveType' parameters
    $format = 'zip'; // default
    if (isset($input['format'])) {
        $format = $input['format'];
    } elseif (isset($input['archiveType'])) {
        $format = $input['archiveType'];
    }
    
    $requestedPath = isset($input['path']) ? sanitizePath($input['path']) : '';
    
    if (empty($archiveName)) {
        throw new Exception('Недопустимое имя архива');
    }
    
    // Check ZIP support
    if ($format === 'zip' && !class_exists('ZipArchive')) {
        throw new Exception('ZIP архивирование не поддерживается на сервере');
    }
    
    $workingDir = $baseDir . $requestedPath;
    
    // Security check
    $realBasePath = realpath($baseDir);
    if (!$realBasePath) {
        throw new Exception('Базовая директория не найдена');
    }
    
    $realWorkingPath = realpath($workingDir);
    if (!$realWorkingPath) {
        throw new Exception('Рабочая директория не найдена');
    }
    
    if (strpos($realWorkingPath, $realBasePath) !== 0) {
        throw new Exception('Недопустимый путь');
    }
    
    // Determine archive path
    $archiveExtension = ($format === 'tar') ? '.tar' : '.zip';
    $archiveFileName = $archiveName . $archiveExtension;
    $archivePath = $realWorkingPath . '/' . $archiveFileName;
    
    // Check if archive already exists
    if (file_exists($archivePath)) {
        throw new Exception('Архив с таким именем уже существует');
    }
    
    // Validate files exist
    $validFiles = [];
    foreach ($files as $fileName) {
        $filePath = $realWorkingPath . '/' . $fileName;
        $realFilePath = realpath($filePath);
        
        if (!$realFilePath) {
            continue; // Skip non-existent files
        }
        
        // Security check
        if (strpos($realFilePath, $realBasePath) !== 0) {
            continue; // Skip files outside base directory
        }
        
        $validFiles[] = $fileName;
    }
    
    if (empty($validFiles)) {
        throw new Exception('Не найдены файлы для архивирования');
    }
    
    $success = false;
    $method = '';
    
    if ($format === 'zip') {
        // Create ZIP archive
        $zip = new ZipArchive();
        $result = $zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        
        if ($result !== TRUE) {
            throw new Exception('Не удалось создать ZIP архив (код ошибки: ' . $result . ')');
        }
        
        foreach ($validFiles as $fileName) {
            $filePath = $realWorkingPath . '/' . $fileName;
            
            if (!addToZip($zip, $filePath, $fileName)) {
                $zip->close();
                if (file_exists($archivePath)) {
                    unlink($archivePath);
                }
                throw new Exception('Не удалось добавить файл в архив: ' . $fileName);
            }
        }
        
        $zip->close();
        $method = 'PHP ZipArchive';
        $success = true;
        
    } elseif ($format === 'tar') {
        // Create TAR archive using command line
        if (!function_exists('exec')) {
            throw new Exception('TAR архивирование недоступно (exec отключен)');
        }
        
        $oldDir = getcwd();
        chdir($realWorkingPath);
        
        $fileArgs = array_map('escapeshellarg', $validFiles);
        $command = 'tar -cf ' . escapeshellarg($archiveFileName) . ' ' . implode(' ', $fileArgs) . ' 2>&1';
        
        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);
        
        chdir($oldDir);
        
        if ($returnCode !== 0) {
            throw new Exception('Не удалось создать TAR архив: ' . implode(' ', $output));
        }
        
        $method = 'Command line tar';
        $success = true;
    } else {
        throw new Exception('Неподдерживаемый формат архива: ' . $format);
    }
    
    // Check if archive was created successfully
    if (!file_exists($archivePath) || filesize($archivePath) === 0) {
        if (file_exists($archivePath)) {
            unlink($archivePath);
        }
        throw new Exception('Архив создан, но пуст или поврежден');
    }
    
    sendJsonResponse([
        'success' => true,
        'message' => 'Архив создан успешно',
        'archiveName' => $archiveFileName,
        'method' => $method,
        'filesCount' => count($validFiles),
        'size' => filesize($archivePath)
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

// Fallback response
sendJsonResponse([
    'success' => false,
    'error' => 'Неизвестная ошибка выполнения скрипта'
]);
?>
            pack('v', strlen($filename)) . // File name length
            "\x00\x00" .         // Extra field length
            "\x00\x00" .         // File comment length
            "\x00\x00" .         // Disk number start
            "\x00\x00" .         // Internal file attributes
            "\x00\x00\x00\x00" . // External file attributes
            pack('V', $offset) .  // Relative offset of local header
            $filename;           // File name
        
        $offset += strlen($local_header);
    }
    
    // End of central directory record
    $end_central_dir = 
        "\x50\x4b\x05\x06" . // End of central dir signature
        "\x00\x00" .         // Number of this disk
        "\x00\x00" .         // Number of the disk with the start of the central directory
        pack('v', count($files)) . // Total number of entries in the central directory on this disk
        pack('v', count($files)) . // Total number of entries in the central directory
        pack('V', strlen($central_dir)) . // Size of the central directory
        pack('V', $offset) .  // Offset of start of central directory
        "\x00\x00";          // .ZIP file comment length
    
    $zip_content = $zip_data . $central_dir . $end_central_dir;
    
    return file_put_contents($archivePath, $zip_content) !== false;
}

try {
    error_log("Archive creation request received");
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Метод не поддерживается: ' . $_SERVER['REQUEST_METHOD']);
    }
    
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Ошибка декодирования JSON: ' . json_last_error_msg());
    }
    
    if (!isset($input['archiveName']) || empty(trim($input['archiveName']))) {
        throw new Exception('Имя архива не указано');
    }
    
    if (!isset($input['items']) || !is_array($input['items']) || empty($input['items'])) {
        throw new Exception('Не выбраны элементы для архивирования');
    }
    
    $archiveName = sanitizeFilename($input['archiveName']);
    $archiveType = isset($input['archiveType']) ? $input['archiveType'] : 'zip';
    $items = $input['items'];
    $requestedPath = isset($input['path']) ? sanitizePath($input['path']) : '';
    
    $workingDir = $baseDir . $requestedPath;
    
    // Security check
    $realBasePath = realpath($baseDir);
    $realWorkingPath = realpath($workingDir);
    
    if (!$realWorkingPath || ($realBasePath && strpos($realWorkingPath, $realBasePath) !== 0)) {
        throw new Exception('Недопустимый путь');
    }
    
    // Determine archive extension and path
    $archiveExtension = ($archiveType === 'tar') ? '.tar' : '.zip';
    $archiveFileName = $archiveName . $archiveExtension;
    $archivePath = $workingDir . '/' . $archiveFileName;
    
    // Check if archive already exists
    if (file_exists($archivePath)) {
        throw new Exception('Архив с таким именем уже существует');
    }
    
    $success = false;
    $method_used = '';
    
    // Try different methods in order of preference
    if ($archiveType === 'zip') {
        // Method 1: PHP ZipArchive
        if (createZipArchive($archivePath, $items, $workingDir)) {
            $success = true;
            $method_used = 'PHP ZipArchive';
        }
        // Method 2: Command line zip
        elseif (createZipCommand($archivePath, $items, $workingDir)) {
            $success = true;
            $method_used = 'Command line zip';
        }
        // Method 3: Manual ZIP (fallback)
        elseif (createManualZip($archivePath, $items, $workingDir)) {
            $success = true;
            $method_used = 'Manual ZIP creation';
        }
    } elseif ($archiveType === 'tar') {
        // TAR archive
        if (createTarArchive($archivePath, $items, $workingDir)) {
            $success = true;
            $method_used = 'Command line 