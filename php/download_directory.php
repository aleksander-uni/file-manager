<?php
// Disable error output to prevent corrupting the download
ini_set('display_errors', 0);
error_reporting(0);

// Start output buffering
ob_start();

// Configuration
$baseDir = '../uploads/';

function sanitizePath($path) {
    $path = str_replace(['../', '..\\', '\\'], '', $path);
    $path = trim($path, '/\\');
    return $path;
}

function addDirectoryToZip($zip, $dir, $zipPath = '') {
    if (!is_dir($dir)) {
        return false;
    }
    
    $files = scandir($dir);
    if ($files === false) {
        return false;
    }
    
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') {
            continue;
        }
        
        $filePath = $dir . '/' . $file;
        $zipFilePath = $zipPath ? $zipPath . '/' . $file : $file;
        
        if (is_dir($filePath)) {
            // Add directory
            $zip->addEmptyDir($zipFilePath);
            // Recursively add directory contents
            if (!addDirectoryToZip($zip, $filePath, $zipFilePath)) {
                return false;
            }
        } else {
            // Add file
            if (!$zip->addFile($filePath, $zipFilePath)) {
                return false;
            }
        }
    }
    
    return true;
}

function createTarArchive($dirPath, $tarPath, $dirName) {
    // Try to create TAR archive using command line
    $command = sprintf('cd %s && tar -czf %s %s 2>&1', 
        escapeshellarg(dirname($dirPath)), 
        escapeshellarg($tarPath), 
        escapeshellarg(basename($dirPath))
    );
    
    $output = [];
    $returnCode = 0;
    @exec($command, $output, $returnCode);
    
    return $returnCode === 0 && file_exists($tarPath) && filesize($tarPath) > 0;
}

function sendErrorResponse($message, $code = 500) {
    // Clear any output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Send error response
    http_response_code($code);
    header('Content-Type: text/html; charset=utf-8');
    
    echo '<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Ошибка скачивания</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 600px; margin: 50px auto; padding: 20px; }
        .error { background: #ffebee; border: 1px solid #f44336; padding: 20px; border-radius: 4px; }
        .error h2 { color: #d32f2f; margin-top: 0; }
        .back-link { margin-top: 20px; }
        .back-link a { color: #1976d2; text-decoration: none; }
    </style>
</head>
<body>
    <div class="error">
        <h2>Ошибка скачивания директории</h2>
        <p><strong>Причина:</strong> ' . htmlspecialchars($message) . '</p>
        <div class="back-link">
            <a href="javascript:history.back()">← Вернуться назад</a>
        </div>
    </div>
</body>
</html>';
    exit();
}

try {
    if (!isset($_GET['name']) || empty($_GET['name'])) {
        throw new Exception('Имя директории не указано');
    }
    
    $dirName = $_GET['name'];
    $requestedPath = isset($_GET['path']) ? sanitizePath($_GET['path']) : '';
    
    // Build directory path
    if ($requestedPath) {
        $dirPath = $baseDir . $requestedPath . '/' . $dirName;
    } else {
        $dirPath = $baseDir . $dirName;
    }
    
    // Security check
    $realBasePath = realpath($baseDir);
    if (!$realBasePath) {
        throw new Exception('Базовая директория не найдена');
    }
    
    $realDirPath = realpath($dirPath);
    if (!$realDirPath) {
        throw new Exception('Директория не найдена: ' . $dirName);
    }
    
    if (strpos($realDirPath, $realBasePath) !== 0) {
        throw new Exception('Недопустимый путь к директории');
    }
    
    // Check if directory exists and is readable
    if (!is_dir($realDirPath)) {
        throw new Exception('Указанный путь не является директорией');
    }
    
    if (!is_readable($realDirPath)) {
        throw new Exception('Нет прав на чтение директории');
    }
    
    // Check if directory is empty
    $files = scandir($realDirPath);
    if ($files === false) {
        throw new Exception('Не удалось прочитать содержимое директории');
    }
    
    $hasFiles = false;
    foreach ($files as $file) {
        if ($file !== '.' && $file !== '..') {
            $hasFiles = true;
            break;
        }
    }
    
    if (!$hasFiles) {
        throw new Exception('Директория пуста');
    }
    
    // Try ZIP first
    $useZip = class_exists('ZipArchive');
    $tempArchivePath = null;
    $archiveName = $dirName . ($useZip ? '.zip' : '.tar.gz');
    
    if ($useZip) {
        // Create temporary ZIP file
        $tempArchivePath = sys_get_temp_dir() . '/' . uniqid('download_', true) . '.zip';
        
        $zip = new ZipArchive();
        $result = $zip->open($tempArchivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        
        if ($result !== TRUE) {
            throw new Exception('Не удалось создать ZIP архив (код ошибки: ' . $result . ')');
        }
        
        // Add directory contents to ZIP
        if (!addDirectoryToZip($zip, $realDirPath)) {
            $zip->close();
            if (file_exists($tempArchivePath)) {
                unlink($tempArchivePath);
            }
            throw new Exception('Не удалось добавить файлы в ZIP архив');
        }
        
        $zip->close();
        
        // Check if ZIP file was created successfully
        if (!file_exists($tempArchivePath) || filesize($tempArchivePath) === 0) {
            if (file_exists($tempArchivePath)) {
                unlink($tempArchivePath);
            }
            throw new Exception('ZIP архив создан, но пуст или поврежден');
        }
        
    } else {
        // Try TAR as fallback
        $tempArchivePath = sys_get_temp_dir() . '/' . uniqid('download_', true) . '.tar.gz';
        
        if (!createTarArchive($realDirPath, $tempArchivePath, $dirName)) {
            if (file_exists($tempArchivePath)) {
                unlink($tempArchivePath);
            }
            throw new Exception('Архивирование не поддерживается на сервере (нет ZIP и TAR)');
        }
    }
    
    $archiveSize = filesize($tempArchivePath);
    if ($archiveSize === 0) {
        unlink($tempArchivePath);
        throw new Exception('Созданный архив пуст');
    }
    
    // Clear output buffer before sending file
    ob_end_clean();
    
    // Set headers for download
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $archiveName . '"');
    header('Content-Length: ' . $archiveSize);
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // Output archive file
    $handle = fopen($tempArchivePath, 'rb');
    if (!$handle) {
        unlink($tempArchivePath);
        throw new Exception('Не удалось открыть архив для чтения');
    }
    
    while (!feof($handle)) {
        $chunk = fread($handle, 8192);
        if ($chunk === false) {
            break;
        }
        echo $chunk;
        flush();
    }
    fclose($handle);
    
    // Clean up temporary file
    unlink($tempArchivePath);
    
} catch (Exception $e) {
    // Clean up temporary file if it exists
    if (isset($tempArchivePath) && file_exists($tempArchivePath)) {
        unlink($tempArchivePath);
    }
    
    sendErrorResponse($e->getMessage());
} catch (Error $e) {
    // Clean up temporary file if it exists
    if (isset($tempArchivePath) && file_exists($tempArchivePath)) {
        unlink($tempArchivePath);
    }
    
    sendErrorResponse('Системная ошибка: ' . $e->getMessage());
} catch (Throwable $e) {
    // Clean up temporary file if it exists
    if (isset($tempArchivePath) && file_exists($tempArchivePath)) {
        unlink($tempArchivePath);
    }
    
    sendErrorResponse('Критическая ошибка: ' . $e->getMessage());
}
?>