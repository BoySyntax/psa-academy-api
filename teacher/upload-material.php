<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, ngrok-skip-browser-warning');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';
require_once '../config/url_helper.php';

$database = new Database();
$db = $database->getConnection();

// Upload learning material
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header("Content-Type: application/json");
    
    try {
        // Get form data
        $lesson_id = isset($_POST['lesson_id']) ? $_POST['lesson_id'] : null;
        $course_id = isset($_POST['course_id']) ? $_POST['course_id'] : null;
        $teacher_id = isset($_POST['teacher_id']) ? $_POST['teacher_id'] : null;
        $material_name = isset($_POST['material_name']) ? $_POST['material_name'] : null;
        $description = isset($_POST['description']) ? $_POST['description'] : '';
        
        if (!$lesson_id || !$teacher_id) {
            http_response_code(400);
            echo json_encode([
                'message' => 'Lesson ID and Teacher ID are required',
                'received' => [
                    'lesson_id' => $lesson_id,
                    'teacher_id' => $teacher_id
                ]
            ]);
            exit();
        }
        
        // Check if file was uploaded
        if (!isset($_FILES['file'])) {
            http_response_code(400);
            echo json_encode([
                'message' => 'No file in request',
                'files_received' => array_keys($_FILES),
                'post_data' => $_POST
            ]);
            exit();
        }
        
        if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $error_messages = [
                UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize',
                UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE',
                UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
                UPLOAD_ERR_NO_FILE => 'No file was uploaded',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
                UPLOAD_ERR_EXTENSION => 'File upload stopped by extension'
            ];
            
            http_response_code(400);
            echo json_encode([
                'message' => 'File upload error',
                'error_code' => $_FILES['file']['error'],
                'error_message' => $error_messages[$_FILES['file']['error']] ?? 'Unknown error'
            ]);
            exit();
        }
        
        $file = $_FILES['file'];
        $file_name = $file['name'];
        $file_size = $file['size'];
        $file_tmp = $file['tmp_name'];
        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        
        // Allowed file types
        $allowed_extensions = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'zip', 'mp4', 'mp3', 'jpg', 'jpeg', 'png'];
        
        if (!in_array($file_ext, $allowed_extensions)) {
            http_response_code(400);
            echo json_encode(['message' => 'File type not allowed. Allowed: ' . implode(', ', $allowed_extensions)]);
            exit();
        }
        
        // Max file size: 500MB
        if ($file_size > 500 * 1024 * 1024) {
            http_response_code(400);
            echo json_encode(['message' => 'File size exceeds 500MB limit']);
            exit();
        }
        
        // Create uploads directory if it doesn't exist
        $upload_dir = '../uploads/materials/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        // Generate unique filename
        $unique_name = uniqid() . '_' . time() . '.' . $file_ext;
        $upload_path = $upload_dir . $unique_name;
        
        // Move uploaded file
        if (!move_uploaded_file($file_tmp, $upload_path)) {
            http_response_code(500);
            echo json_encode(['message' => 'Failed to save file']);
            exit();
        }
        
        // Determine material type
        $material_type = 'document';
        if (in_array($file_ext, ['pdf'])) {
            $material_type = 'pdf';
        } elseif (in_array($file_ext, ['mp4', 'avi', 'mov'])) {
            $material_type = 'video';
        } elseif (in_array($file_ext, ['jpg', 'jpeg', 'png', 'gif'])) {
            $material_type = 'image';
        }
        
        // Insert into database
        $file_url = make_local_file_url('uploads/materials/' . $unique_name);
        $display_name = $material_name ? $material_name : $file_name;
        
        $query = "INSERT INTO learning_materials 
                  (lesson_id, course_id, material_name, material_type, file_url, file_size, description, uploaded_by)
                  VALUES (:lesson_id, :course_id, :material_name, :material_type, :file_url, :file_size, :description, :uploaded_by)";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':lesson_id', $lesson_id);
        $stmt->bindParam(':course_id', $course_id);
        $stmt->bindParam(':material_name', $display_name);
        $stmt->bindParam(':material_type', $material_type);
        $stmt->bindParam(':file_url', $file_url);
        $stmt->bindParam(':file_size', $file_size);
        $stmt->bindParam(':description', $description);
        $stmt->bindParam(':uploaded_by', $teacher_id);
        
        if ($stmt->execute()) {
            http_response_code(201);
            echo json_encode([
                'message' => 'Material uploaded successfully',
                'material' => [
                    'id' => $db->lastInsertId(),
                    'material_name' => $display_name,
                    'material_type' => $material_type,
                    'file_url' => normalize_public_file_url($file_url),
                    'file_size' => $file_size
                ]
            ]);
        } else {
            // Delete uploaded file if database insert fails
            unlink($upload_path);
            http_response_code(500);
            echo json_encode(['message' => 'Failed to save material info to database']);
        }
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['message' => 'Error: ' . $e->getMessage()]);
    }
}

// Delete learning material
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    header("Content-Type: application/json");
    
    try {
        $data = json_decode(file_get_contents("php://input"), true);
        $material_id = isset($data['material_id']) ? $data['material_id'] : null;
        
        if (!$material_id) {
            http_response_code(400);
            echo json_encode(['message' => 'Material ID is required']);
            exit();
        }
        
        // Get file info before deleting
        $query = "SELECT file_url FROM learning_materials WHERE id = :material_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':material_id', $material_id);
        $stmt->execute();
        $material = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$material) {
            http_response_code(404);
            echo json_encode(['message' => 'Material not found']);
            exit();
        }
        
        // Delete from database
        $deleteQuery = "DELETE FROM learning_materials WHERE id = :material_id";
        $deleteStmt = $db->prepare($deleteQuery);
        $deleteStmt->bindParam(':material_id', $material_id);
        
        if ($deleteStmt->execute()) {
            // Delete physical file
            $file_path = url_to_local_path($material['file_url']);
            if (file_exists($file_path)) {
                unlink($file_path);
            }
            
            http_response_code(200);
            echo json_encode(['message' => 'Material deleted successfully']);
        } else {
            http_response_code(500);
            echo json_encode(['message' => 'Failed to delete material']);
        }
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['message' => 'Error: ' . $e->getMessage()]);
    }
}
?>





