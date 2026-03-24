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
        if (isset($_GET['id'])) {
            $lessonId = $_GET['id'];
            $query = "SELECT * FROM course_lessons WHERE id = :id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':id', $lessonId);
            $stmt->execute();
            
            $lesson = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($lesson) {
                // Get materials for this lesson
                $materialQuery = "SELECT * FROM learning_materials WHERE lesson_id = :lesson_id ORDER BY created_at DESC";
                $materialStmt = $db->prepare($materialQuery);
                $materialStmt->bindParam(':lesson_id', $lessonId);
                $materialStmt->execute();
                $lesson['materials'] = $materialStmt->fetchAll(PDO::FETCH_ASSOC);
                
                http_response_code(200);
                echo json_encode(['lesson' => $lesson]);
            } else {
                http_response_code(404);
                echo json_encode(['message' => 'Lesson not found']);
            }
        } elseif (isset($_GET['module_id'])) {
            $moduleId = $_GET['module_id'];
            $query = "SELECT * FROM course_lessons WHERE module_id = :module_id ORDER BY order_index ASC";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':module_id', $moduleId);
            $stmt->execute();
            
            $lessons = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            http_response_code(200);
            echo json_encode(['lessons' => $lessons]);
        } else {
            http_response_code(400);
            echo json_encode(['message' => 'Module ID or Lesson ID is required']);
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['message' => 'Database error: ' . $e->getMessage()]);
    }
}

function handlePost($db) {
    try {
        $data = json_decode(file_get_contents("php://input"), true);
        
        if (!isset($data['module_id']) || !isset($data['lesson_title'])) {
            http_response_code(400);
            echo json_encode(['message' => 'Module ID and lesson title are required']);
            return;
        }
        
        $isPublished = isset($data['is_published']) ? ($data['is_published'] ? 1 : 0) : 0;
        
        $query = "INSERT INTO course_lessons (
            module_id, lesson_title, lesson_content, lesson_type,
            duration_minutes, order_index, is_published
        ) VALUES (
            :module_id, :lesson_title, :lesson_content, :lesson_type,
            :duration_minutes, :order_index, :is_published
        )";
        
        $stmt = $db->prepare($query);
        
        $stmt->bindParam(':module_id', $data['module_id']);
        $stmt->bindParam(':lesson_title', $data['lesson_title']);
        $stmt->bindParam(':lesson_content', $data['lesson_content']);
        $stmt->bindParam(':lesson_type', $data['lesson_type']);
        $stmt->bindParam(':duration_minutes', $data['duration_minutes']);
        $stmt->bindParam(':order_index', $data['order_index']);
        $stmt->bindParam(':is_published', $isPublished);
        
        if ($stmt->execute()) {
            $lessonId = $db->lastInsertId();
            http_response_code(201);
            echo json_encode([
                'message' => 'Lesson created successfully',
                'lesson' => ['id' => $lessonId]
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['message' => 'Failed to create lesson']);
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
            echo json_encode(['message' => 'Lesson ID is required']);
            return;
        }
        
        $updateFields = [];
        $params = [':id' => $data['id']];
        
        $allowedFields = [
            'lesson_title', 'lesson_content', 'lesson_type',
            'duration_minutes', 'order_index'
        ];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updateFields[] = "$field = :$field";
                $params[":$field"] = $data[$field];
            }
        }
        
        if (isset($data['is_published'])) {
            $updateFields[] = "is_published = :is_published";
            $params[':is_published'] = $data['is_published'] ? 1 : 0;
        }
        
        if (empty($updateFields)) {
            http_response_code(400);
            echo json_encode(['message' => 'No fields to update']);
            return;
        }
        
        $query = "UPDATE course_lessons SET " . implode(', ', $updateFields) . " WHERE id = :id";
        $stmt = $db->prepare($query);
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        if ($stmt->execute()) {
            http_response_code(200);
            echo json_encode(['message' => 'Lesson updated successfully']);
        } else {
            http_response_code(500);
            echo json_encode(['message' => 'Failed to update lesson']);
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
            echo json_encode(['message' => 'Lesson ID is required']);
            return;
        }
        
        $query = "DELETE FROM course_lessons WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $data['id']);
        
        if ($stmt->execute()) {
            if ($stmt->rowCount() > 0) {
                http_response_code(200);
                echo json_encode(['message' => 'Lesson deleted successfully']);
            } else {
                http_response_code(404);
                echo json_encode(['message' => 'Lesson not found']);
            }
        } else {
            http_response_code(500);
            echo json_encode(['message' => 'Failed to delete lesson']);
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['message' => 'Database error: ' . $e->getMessage()]);
    }
}
?>

