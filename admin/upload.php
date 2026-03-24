<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, ngrok-skip-browser-warning');
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/url_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['message' => 'Method not allowed']);
    exit();
}

$uploadDir = '../uploads/';
$maxFileSize = 50 * 1024 * 1024;

$allowedTypes = [
    'pdf' => 'application/pdf',
    'doc' => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'mp4' => 'video/mp4',
];

try {
    if (!isset($_FILES['file'])) {
        http_response_code(400);
        echo json_encode(['message' => 'No file uploaded']);
        exit();
    }
    
    $file = $_FILES['file'];
    $courseId = $_POST['course_id'] ?? 'general';
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['message' => 'File upload error']);
        exit();
    }
    
    if ($file['size'] > $maxFileSize) {
        http_response_code(400);
        echo json_encode(['message' => 'File too large']);
        exit();
    }
    
    $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    if (!array_key_exists($fileExtension, $allowedTypes)) {
        http_response_code(400);
        echo json_encode(['message' => 'File type not allowed']);
        exit();
    }
    
    $courseUploadDir = $uploadDir . 'course_' . $courseId . '/';
    if (!file_exists($courseUploadDir)) {
        mkdir($courseUploadDir, 0755, true);
    }
    
    $fileName = uniqid() . '_' . time() . '.' . $fileExtension;
    $filePath = $courseUploadDir . $fileName;
    
    if (move_uploaded_file($file['tmp_name'], $filePath)) {
        $fileUrl = make_local_file_url('uploads/course_' . $courseId . '/' . $fileName);
        http_response_code(200);
        echo json_encode([
            'message' => 'File uploaded successfully',
            'file_url' => $fileUrl,
            'file_size' => $file['size']
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['message' => 'Failed to save file']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['message' => 'Upload error: ' . $e->getMessage()]);
}
?>

