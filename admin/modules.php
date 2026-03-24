<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, ngrok-skip-browser-warning');
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';

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
    case 'PUT':
        handlePut($db);
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
        if (isset($_GET['course_id'])) {
            $courseId = $_GET['course_id'];
            $query = "SELECT m.*, 
                      (SELECT COUNT(*) FROM course_lessons cl WHERE cl.module_id = m.id) as lesson_count
                      FROM course_modules m 
                      WHERE m.course_id = :course_id 
                      ORDER BY m.order_index ASC";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':course_id', $courseId);
            $stmt->execute();
            
            $modules = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            http_response_code(200);
            echo json_encode(['modules' => $modules]);
        } else {
            http_response_code(400);
            echo json_encode(['message' => 'Course ID is required']);
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['message' => 'Database error: ' . $e->getMessage()]);
    }
}

function handlePost($db) {
    try {
        $data = json_decode(file_get_contents("php://input"), true);
        
        if (!isset($data['course_id']) || !isset($data['module_name'])) {
            http_response_code(400);
            echo json_encode(['message' => 'Course ID and module name are required']);
            return;
        }
        
        $query = "INSERT INTO course_modules (
            course_id, module_name, description, order_index
        ) VALUES (
            :course_id, :module_name, :description, :order_index
        )";
        
        $stmt = $db->prepare($query);
        
        $stmt->bindParam(':course_id', $data['course_id']);
        $stmt->bindParam(':module_name', $data['module_name']);
        $stmt->bindParam(':description', $data['description']);
        $stmt->bindParam(':order_index', $data['order_index']);
        
        if ($stmt->execute()) {
            $moduleId = $db->lastInsertId();
            http_response_code(201);
            echo json_encode([
                'message' => 'Module created successfully',
                'module' => ['id' => $moduleId]
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['message' => 'Failed to create module']);
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['message' => 'Database error: ' . $e->getMessage()]);
    }
}

function handlePut($db) {
    try {
        $data = json_decode(file_get_contents("php://input"), true);
        
        if (!isset($data['id'])) {
            http_response_code(400);
            echo json_encode(['message' => 'Module ID is required']);
            return;
        }
        
        $updateFields = [];
        $params = [':id' => $data['id']];
        
        $allowedFields = ['module_name', 'description', 'order_index'];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updateFields[] = "$field = :$field";
                $params[":$field"] = $data[$field];
            }
        }
        
        if (empty($updateFields)) {
            http_response_code(400);
            echo json_encode(['message' => 'No fields to update']);
            return;
        }
        
        $query = "UPDATE course_modules SET " . implode(', ', $updateFields) . " WHERE id = :id";
        $stmt = $db->prepare($query);
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        if ($stmt->execute()) {
            http_response_code(200);
            echo json_encode(['message' => 'Module updated successfully']);
        } else {
            http_response_code(500);
            echo json_encode(['message' => 'Failed to update module']);
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
            echo json_encode(['message' => 'Module ID is required']);
            return;
        }
        
        $query = "DELETE FROM course_modules WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $data['id']);
        
        if ($stmt->execute()) {
            if ($stmt->rowCount() > 0) {
                http_response_code(200);
                echo json_encode(['message' => 'Module deleted successfully']);
            } else {
                http_response_code(404);
                echo json_encode(['message' => 'Module not found']);
            }
        } else {
            http_response_code(500);
            echo json_encode(['message' => 'Failed to delete module']);
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['message' => 'Database error: ' . $e->getMessage()]);
    }
}
?>

