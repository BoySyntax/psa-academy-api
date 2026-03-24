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

$database = new Database();
$db = $database->getConnection();

// Get lesson details with materials
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $lesson_id = isset($_GET['lesson_id']) ? $_GET['lesson_id'] : null;
        $student_id = isset($_GET['student_id']) ? $_GET['student_id'] : null;
        
        if (!$lesson_id || !$student_id) {
            http_response_code(400);
            echo json_encode(['message' => 'Lesson ID and Student ID are required']);
            exit();
        }
        
        // Get lesson details
        $lessonQuery = "SELECT * FROM course_lessons WHERE id = :lesson_id";
        $lessonStmt = $db->prepare($lessonQuery);
        $lessonStmt->bindParam(':lesson_id', $lesson_id);
        $lessonStmt->execute();
        $lesson = $lessonStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$lesson) {
            http_response_code(404);
            echo json_encode(['message' => 'Lesson not found']);
            exit();
        }
        
        // Get learning materials
        $materialsQuery = "SELECT * FROM learning_materials WHERE lesson_id = :lesson_id";
        $materialsStmt = $db->prepare($materialsQuery);
        $materialsStmt->bindParam(':lesson_id', $lesson_id);
        $materialsStmt->execute();
        $materials = $materialsStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($materials as &$material) {
            if (!empty($material['file_url'])) {
                $material['file_url'] = normalize_public_file_url($material['file_url']);
            }
        }
        
        http_response_code(200);
        echo json_encode([
            'lesson' => $lesson,
            'materials' => $materials
        ]);
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['message' => 'Database error: ' . $e->getMessage()]);
    }
}
?>

