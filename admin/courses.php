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
require_once '../config/url_helper.php';

function normalize_course_thumbnail(&$course) {
    if (!empty($course['thumbnail_url'])) {
        $course['thumbnail_url'] = normalize_public_file_url($course['thumbnail_url']);
    }
}

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
            $courseId = $_GET['id'];
            $query = "SELECT c.*, 
                      (SELECT COUNT(*) FROM course_teachers ct WHERE ct.course_id = c.id) as teacher_count,
                      (SELECT COUNT(*) FROM course_enrollments ce WHERE ce.course_id = c.id) as student_count
                      FROM courses c WHERE c.id = :id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':id', $courseId);
            $stmt->execute();
            
            $course = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($course) {
                normalize_course_thumbnail($course);
                // Get assigned teachers
                $teacherQuery = "SELECT u.id, u.first_name, u.last_name, u.email 
                                FROM users u 
                                INNER JOIN course_teachers ct ON u.id = ct.teacher_id 
                                WHERE ct.course_id = :course_id";
                $teacherStmt = $db->prepare($teacherQuery);
                $teacherStmt->bindParam(':course_id', $courseId);
                $teacherStmt->execute();
                $course['teachers'] = $teacherStmt->fetchAll(PDO::FETCH_ASSOC);
                
                http_response_code(200);
                echo json_encode(['course' => $course]);
            } else {
                http_response_code(404);
                echo json_encode(['message' => 'Course not found']);
            }
        } else {
            $query = "SELECT c.*, 
                      (SELECT COUNT(*) FROM course_teachers ct WHERE ct.course_id = c.id) as teacher_count,
                      (SELECT COUNT(*) FROM course_enrollments ce WHERE ce.course_id = c.id) as student_count
                      FROM courses c ORDER BY c.created_at DESC";
            $stmt = $db->prepare($query);
            $stmt->execute();
            
            $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($courses as &$course) {
                normalize_course_thumbnail($course);
            }
            
            http_response_code(200);
            echo json_encode(['courses' => $courses]);
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['message' => 'Database error: ' . $e->getMessage()]);
    }
}

function handlePost($db) {
    try {
        $data = json_decode(file_get_contents("php://input"), true);
        
        if (!isset($data['course_code']) || !isset($data['course_name'])) {
            http_response_code(400);
            echo json_encode(['message' => 'Course code and name are required']);
            return;
        }
        
        // Check if course code already exists
        $checkQuery = "SELECT id FROM courses WHERE course_code = :course_code";
        $checkStmt = $db->prepare($checkQuery);
        $checkStmt->bindParam(':course_code', $data['course_code']);
        $checkStmt->execute();
        
        if ($checkStmt->fetch()) {
            http_response_code(409);
            echo json_encode(['message' => 'Course code already exists']);
            return;
        }
        
        $status = isset($data['status']) ? $data['status'] : 'draft';
        
        $query = "INSERT INTO courses (
            course_code, course_name, description, category, subcategory,
            duration_hours, max_students, status, thumbnail_url
        ) VALUES (
            :course_code, :course_name, :description, :category, :subcategory,
            :duration_hours, :max_students, :status, :thumbnail_url
        )";
        
        $stmt = $db->prepare($query);
        
        $stmt->bindParam(':course_code', $data['course_code']);
        $stmt->bindParam(':course_name', $data['course_name']);
        $stmt->bindParam(':description', $data['description']);
        $stmt->bindParam(':category', $data['category']);
        $stmt->bindParam(':subcategory', $data['subcategory']);
        $stmt->bindParam(':duration_hours', $data['duration_hours']);
        $stmt->bindParam(':max_students', $data['max_students']);
        $stmt->bindParam(':status', $status);
        $stmt->bindParam(':thumbnail_url', $data['thumbnail_url']);
        
        if ($stmt->execute()) {
            $courseId = $db->lastInsertId();
            http_response_code(201);
            echo json_encode([
                'message' => 'Course created successfully',
                'course' => ['id' => $courseId]
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['message' => 'Failed to create course']);
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
            echo json_encode(['message' => 'Course ID is required']);
            return;
        }
        
        $updateFields = [];
        $params = [':id' => $data['id']];
        
        $allowedFields = [
            'course_code', 'course_name', 'description', 'category', 'subcategory',
            'duration_hours', 'max_students', 'status', 'thumbnail_url'
        ];
        
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
        
        $query = "UPDATE courses SET " . implode(', ', $updateFields) . " WHERE id = :id";
        $stmt = $db->prepare($query);
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        if ($stmt->execute()) {
            http_response_code(200);
            echo json_encode(['message' => 'Course updated successfully']);
        } else {
            http_response_code(500);
            echo json_encode(['message' => 'Failed to update course']);
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
            echo json_encode(['message' => 'Course ID is required']);
            return;
        }
        
        $query = "DELETE FROM courses WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $data['id']);
        
        if ($stmt->execute()) {
            if ($stmt->rowCount() > 0) {
                http_response_code(200);
                echo json_encode(['message' => 'Course deleted successfully']);
            } else {
                http_response_code(404);
                echo json_encode(['message' => 'Course not found']);
            }
        } else {
            http_response_code(500);
            echo json_encode(['message' => 'Failed to delete course']);
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['message' => 'Database error: ' . $e->getMessage()]);
    }
}

?>

