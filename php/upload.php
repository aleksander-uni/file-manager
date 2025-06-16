<?php
// Set UTF-8 encoding for proper Cyrillic support
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');
ini_set('default_charset', 'UTF-8');

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
$maxFileSize = 0; // 0 = без ограничений (будет использоваться лимит PHP)
$allowedExtensions = []; // Пустой массив = все типы файлов разрешены

function sanitizePath($path) {
    $path = str_replace(['../', '..\\', '\\'], '', $path);
    $path = trim($path, '/\\');
    return $path;
}

function sanitizeFilename($filename) {
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
    
    // Remove dangerous characters but keep Cyrillic
    $filename = preg_replace('/[<>:"\\/\\|?*]/', '_', $filename);
    
    // Remove control characters
    $filename = preg_replace('/[\x00-\x1F\x7F]/', '', $filename);
    
    // Remove multiple underscores and trim
    $filename = preg_replace('/_+/', '_', $filename);
    $filename = trim($filename, '_. ');
    
    // Ensure filename is not empty and not just dots
    if (empty($filename) || preg_match('/^\.+$/', $filename)) {
        $filename = 'file';
    }
    
    return $filename;
}

function getFileExtension($filename) {
    return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
}

function sendJsonResponse($data) {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit();
}

try {
    // Check if file uploads are enabled
    if (!ini_get('file_uploads')) {
        sendJsonResponse([
            'success' => false,
            'error' => 'Загрузка файлов отключена в настройках PHP'
        ]);
    }
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJsonResponse([
            'success' => false,
            'error' => 'Метод не поддерживается'
        ]);
    }
    
    if (!isset($_FILES['files']) || !is_array($_FILES['files']['name'])) {
        sendJsonResponse([
            'success' => false,
            'error' => 'Файлы не выбраны или неправильный формат данных'
        ]);
    }
    
    $requestedPath = isset($_POST['path']) ? sanitizePath($_POST['path']) : '';
    $uploadDir = $baseDir . ($requestedPath ? $requestedPath . '/' : '');
    
    // Create directory if it doesn't exist
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true)) {
            sendJsonResponse([
                'success' => false,
                'error' => 'Не удалось создать директорию: ' . $uploadDir . '. Проверьте права доступа к родительской папке.'
            ]);
        }
        // Try to set permissions after creation
        @chmod($uploadDir, 0755);
    }
    
    // Check if directory is writable
    if (!is_writable($uploadDir)) {
        // Try to fix permissions
        @chmod($uploadDir, 0755);
        
        // Check again
        if (!is_writable($uploadDir)) {
            $perms = is_dir($uploadDir) ? substr(sprintf('%o', fileperms($uploadDir)), -4) : 'N/A';
            sendJsonResponse([
                'success' => false,
                'error' => "Директория не доступна для записи: $uploadDir. Текущие права: $perms. Выполните: chmod 755 $uploadDir"
            ]);
        }
    }
    
    // Security check
    $realBasePath = realpath($baseDir);
    $realUploadPath = realpath($uploadDir);
    
    if (!$realUploadPath || strpos($realUploadPath, $realBasePath) !== 0) {
        sendJsonResponse([
            'success' => false,
            'error' => 'Недопустимый путь загрузки'
        ]);
    }
    
    $uploadedFiles = [];
    $errors = [];
    
    $fileCount = count($_FILES['files']['name']);
    
    for ($i = 0; $i < $fileCount; $i++) {
        $fileName = $_FILES['files']['name'][$i];
        $fileTmpName = $_FILES['files']['tmp_name'][$i];
        $fileSize = $_FILES['files']['size'][$i];
        $fileError = $_FILES['files']['error'][$i];
        
        // Skip empty files
        if ($fileError === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        
        // Check for upload errors
        if ($fileError !== UPLOAD_ERR_OK) {
            $errorMessages = [
                UPLOAD_ERR_INI_SIZE => 'Файл слишком большой (превышен лимит php.ini)',
                UPLOAD_ERR_FORM_SIZE => 'Файл слишком большой (превышен лимит формы)',
                UPLOAD_ERR_PARTIAL => 'Файл загружен частично',
                UPLOAD_ERR_NO_FILE => 'Файл не выбран',
                UPLOAD_ERR_NO_TMP_DIR => 'Отсутствует временная папка',
                UPLOAD_ERR_CANT_WRITE => 'Ошибка записи на диск',
                UPLOAD_ERR_EXTENSION => 'Загрузка остановлена расширением PHP'
            ];
            $errorMessage = $errorMessages[$fileError] ?? "Неизвестная ошибка загрузки (код: $fileError)";
            $errors[] = "Ошибка загрузки файла $fileName: $errorMessage";
            continue;
        }
        
        // Check file size (только если установлен лимит)
        if ($maxFileSize > 0 && $fileSize > $maxFileSize) {
            $errors[] = "Файл $fileName слишком большой (максимум " . ($maxFileSize / 1024 / 1024) . "MB)";
            continue;
        }
        
        // Check file extension (только если есть ограничения)
        if (!empty($allowedExtensions)) {
            $fileExtension = getFileExtension($fileName);
            if (!in_array($fileExtension, $allowedExtensions)) {
                $errors[] = "Тип файла $fileName не разрешен";
                continue;
            }
        }
        
        // Sanitize filename
        $safeFileName = sanitizeFilename($fileName);
        
        // Ensure we don't have empty filename
        if (empty($safeFileName) || $safeFileName === '.') {
            $safeFileName = 'file_' . time() . '_' . $i;
            $extension = getFileExtension($fileName);
            if ($extension) {
                $safeFileName .= '.' . $extension;
            }
        }
        
        $targetPath = rtrim($uploadDir, '/') . '/' . $safeFileName;
        
        // Handle duplicate filenames
        $counter = 1;
        $originalName = pathinfo($safeFileName, PATHINFO_FILENAME);
        $extension = pathinfo($safeFileName, PATHINFO_EXTENSION);
        
        while (file_exists($targetPath)) {
            if ($extension) {
                $safeFileName = $originalName . '_' . $counter . '.' . $extension;
            } else {
                $safeFileName = $originalName . '_' . $counter;
            }
            $targetPath = rtrim($uploadDir, '/') . '/' . $safeFileName;
            $counter++;
        }
        
        // Move uploaded file
        if (move_uploaded_file($fileTmpName, $targetPath)) {
            $uploadedFiles[] = $safeFileName;
            // Set proper permissions
            @chmod($targetPath, 0644);
        } else {
            $lastError = error_get_last();
            $errorMsg = "Не удалось сохранить файл $fileName";
            if ($lastError && strpos($lastError['message'], 'move_uploaded_file') !== false) {
                $errorMsg .= ": " . $lastError['message'];
            }
            $errorMsg .= " (путь: $targetPath)";
            $errors[] = $errorMsg;
        }
    }
    
    if (count($uploadedFiles) > 0) {
        $response = [
            'success' => true,
            'uploaded' => $uploadedFiles,
            'message' => 'Загружено файлов: ' . count($uploadedFiles)
        ];
        
        if (count($errors) > 0) {
            $response['warnings'] = $errors;
        }
        
        sendJsonResponse($response);
    } else {
        sendJsonResponse([
            'success' => false,
            'error' => 'Не удалось загрузить ни одного файла. Ошибки: ' . implode(', ', $errors)
        ]);
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
} catch (Throwable $e) {
    sendJsonResponse([
        'success' => false,
        'error' => 'Неожиданная ошибка: ' . $e->getMessage()
    ]);
}
?>