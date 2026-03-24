<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS");
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, ngrok-skip-browser-warning');
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';
require_once '../config/url_helper.php';

$method = $_SERVER['REQUEST_METHOD'];
$database = new Database();
$db = $database->getConnection();

switch ($method) {
    case 'GET':
        handleGet($db);
        break;
    case 'POST':
        handlePost($db);
        break;
    case 'DELETE':
        handleDelete($db);
        break;
    default:
        http_response_code(405);
        echo json_encode(['message' => 'Method not allowed']);
        break;
}

function handleGet($db) {
    try {
        if (isset($_GET['lesson_id'])) {
            $lessonId = $_GET['lesson_id'];
            $query = "SELECT * FROM learning_materials WHERE lesson_id = :lesson_id ORDER BY created_at DESC";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':lesson_id', $lessonId);
            $stmt->execute();
            
            $materials = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($materials as &$material) {
                if (!empty($material['file_url'])) {
                    $material['file_url'] = normalize_public_file_url($material['file_url']);
                }
            }
            
            http_response_code(200);
            echo json_encode(['materials' => $materials]);
        } elseif (isset($_GET['course_id'])) {
            $courseId = $_GET['course_id'];
            $query = "SELECT * FROM learning_materials WHERE course_id = :course_id ORDER BY created_at DESC";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':course_id', $courseId);
            $stmt->execute();
            
            $materials = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($materials as &$material) {
                if (!empty($material['file_url'])) {
                    $material['file_url'] = normalize_public_file_url($material['file_url']);
                }
            }
            
            http_response_code(200);
            echo json_encode(['materials' => $materials]);
        } else {
            http_response_code(400);
            echo json_encode(['message' => 'Lesson ID or Course ID is required']);
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['message' => 'Database error: ' . $e->getMessage()]);
    }
}

function handlePost($db) {
    try {
        $data = json_decode(file_get_contents("php://input"), true);
        
        if (!isset($data['material_name']) || !isset($data['file_url']) || !isset($data['material_type'])) {
            http_response_code(400);
            echo json_encode(['message' => 'Material name, file URL, and material type are required']);
            return;
        }
        
        $query = "INSERT INTO learning_materials (
            lesson_id, course_id, material_name, material_type,
            file_url, file_size, description, uploaded_by
        ) VALUES (
            :lesson_id, :course_id, :material_name, :material_type,
            :file_url, :file_size, :description, :uploaded_by
        )";
        
        $stmt = $db->prepare($query);
        
        $stmt->bindParam(':lesson_id', $data['lesson_id']);
        $stmt->bindParam(':course_id', $data['course_id']);
        $stmt->bindParam(':material_name', $data['material_name']);
        $stmt->bindParam(':material_type', $data['material_type']);
        $stmt->bindParam(':file_url', $data['file_url']);
        $stmt->bindParam(':file_size', $data['file_size']);
        $stmt->bindParam(':description', $data['description']);
        $stmt->bindParam(':uploaded_by', $data['uploaded_by']);
        
        if ($stmt->execute()) {
            $materialId = $db->lastInsertId();
            http_response_code(201);
            echo json_encode([
                'message' => 'Material uploaded successfully',
                'material' => ['id' => $materialId]
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['message' => 'Failed to upload material']);
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['message' => 'Database error: ' . $e->getMessage()]);
    }
}

function handleDelete($db) {
    try {
        $data = json_decode(file_get_contents("php://input"), true);
        
        if (!isset($data['id'])) {
            http_response_code(400);
            echo json_encode(['message' => 'Material ID is required']);
            return;
        }
        
        // Get file URL before deleting to remove the file
        $getQuery = "SELECT file_url FROM learning_materials WHERE id = :id";
        $getStmt = $db->prepare($getQuery);
        $getStmt->bindParam(':id', $data['id']);
        $getStmt->execute();
        $material = $getStmt->fetch(PDO::FETCH_ASSOC);
        
        $query = "DELETE FROM learning_materials WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $data['id']);
        
        if ($stmt->execute()) {
            if ($stmt->rowCount() > 0) {
                // Optionally delete the physical file
                if ($material && file_exists($material['file_url'])) {
                    @unlink($material['file_url']);
                }
                
                http_response_code(200);
                echo json_encode(['message' => 'Material deleted successfully']);
            } else {
                http_response_code(404);
                echo json_encode(['message' => 'Material not found']);
            }
        } else {
            http_response_code(500);
            echo json_encode(['message' => 'Failed to delete material']);
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['message' => 'Database error: ' . $e->getMessage()]);
    }
}
?>

