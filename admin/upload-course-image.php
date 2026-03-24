<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, ngrok-skip-browser-warning');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';
require_once '../config/url_helper.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header("Content-Type: application/json");
    
    try {
        // Check if file was uploaded
        if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'No file uploaded or upload error occurred'
            ]);
            exit();
        }
        
        $file = $_FILES['image'];
        $fileName = $file['name'];
        $fileTmpName = $file['tmp_name'];
        $fileSize = $file['size'];
        $fileError = $file['error'];
        
        // Get file extension
        $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        
        // Allowed extensions
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        
        if (!in_array($fileExt, $allowedExtensions)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Invalid file type. Allowed: JPG, JPEG, PNG, GIF, WEBP'
            ]);
            exit();
        }
        
        // Check file size (max 5MB)
        if ($fileSize > 5 * 1024 * 1024) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'File size too large. Maximum 5MB allowed'
            ]);
            exit();
        }
        
        // Create uploads directory if it doesn't exist
        $uploadDir = '../uploads/course_images/';
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        
        // Generate unique filename
        $uniqueFileName = uniqid('course_', true) . '.' . $fileExt;
        $uploadPath = $uploadDir . $uniqueFileName;
        
        // Move uploaded file
        if (move_uploaded_file($fileTmpName, $uploadPath)) {
            // Generate URL for the uploaded file
            $fileUrl = make_local_file_url('uploads/course_images/' . $uniqueFileName);
            
            http_response_code(200);
            echo json_encode([
                'success' => true,
                'message' => 'Image uploaded successfully',
                'file_url' => $fileUrl,
                'file_name' => $uniqueFileName
            ]);
        } else {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Failed to save uploaded file'
            ]);
        }
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error: ' . $e->getMessage()
        ]);
    }
} else {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed'
    ]);
}
?>

