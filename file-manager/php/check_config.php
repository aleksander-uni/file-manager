<?php
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

$config = [
    'php_version' => phpversion(),
    'upload_settings' => [
        'file_uploads' => ini_get('file_uploads') ? 'enabled' : 'disabled',
        'upload_max_filesize' => ini_get('upload_max_filesize'),
        'post_max_size' => ini_get('post_max_size'),
        'max_execution_time' => ini_get('max_execution_time'),
        'max_input_time' => ini_get('max_input_time'),
        'memory_limit' => ini_get('memory_limit'),
        'max_file_uploads' => ini_get('max_file_uploads'),
        'upload_tmp_dir' => ini_get('upload_tmp_dir') ?: sys_get_temp_dir()
    ],
    'directory_status' => [
        'uploads_exists' => is_dir('../uploads/'),
        'uploads_readable' => is_readable('../uploads/'),
        'uploads_writable' => is_writable('../uploads/'),
        'uploads_permissions' => is_dir('../uploads/') ? substr(sprintf('%o', fileperms('../uploads/')), -4) : 'N/A',
        'temp_dir_writable' => is_writable(sys_get_temp_dir())
    ],
    'server_info' => [
        'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
        'request_method' => $_SERVER['REQUEST_METHOD'],
        'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'not set'
    ]
];

// Test file creation
try {
    $testFile = '../uploads/test_write.txt';
    if (file_put_contents($testFile, 'test')) {
        $config['write_test'] = 'success';
        unlink($testFile);
    } else {
        $config['write_test'] = 'failed';
    }
} catch (Exception $e) {
    $config['write_test'] = 'error: ' . $e->getMessage();
}

echo json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>