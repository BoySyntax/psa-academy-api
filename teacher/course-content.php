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

// Get course content (modules and lessons) for teacher
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $course_id = isset($_GET['course_id']) ? $_GET['course_id'] : null;
        $teacher_id = isset($_GET['teacher_id']) ? $_GET['teacher_id'] : null;
        
        if (!$course_id || !$teacher_id) {
            http_response_code(400);
            echo json_encode(['message' => 'Course ID and Teacher ID are required']);
            exit();
        }
        
        // Verify teacher is assigned to this course
        $verifyQuery = "SELECT id FROM course_teachers WHERE course_id = :course_id AND teacher_id = :teacher_id";
        $verifyStmt = $db->prepare($verifyQuery);
        $verifyStmt->bindParam(':course_id', $course_id);
        $verifyStmt->bindParam(':teacher_id', $teacher_id);
        $verifyStmt->execute();
        
        if ($verifyStmt->rowCount() === 0) {
            http_response_code(403);
            echo json_encode(['message' => 'You are not assigned to this course']);
            exit();
        }
        
        // Get course details
        $courseQuery = "SELECT * FROM courses WHERE id = :course_id";
        $courseStmt = $db->prepare($courseQuery);
        $courseStmt->bindParam(':course_id', $course_id);
        $courseStmt->execute();
        $course = $courseStmt->fetch(PDO::FETCH_ASSOC);

        if ($course && !empty($course['thumbnail_url'])) {
            $course['thumbnail_url'] = normalize_public_file_url($course['thumbnail_url']);
        }
        
        // Get modules
        $modulesQuery = "SELECT * FROM course_modules WHERE course_id = :course_id ORDER BY order_index ASC";
        $modulesStmt = $db->prepare($modulesQuery);
        $modulesStmt->bindParam(':course_id', $course_id);
        $modulesStmt->execute();
        $modules = $modulesStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get lessons for each module
        foreach ($modules as &$module) {
            $lessonsQuery = "SELECT * FROM course_lessons WHERE module_id = :module_id ORDER BY order_index ASC";
            $lessonsStmt = $db->prepare($lessonsQuery);
            $lessonsStmt->bindParam(':module_id', $module['id']);
            $lessonsStmt->execute();
            $lessons = $lessonsStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get materials for each lesson
            foreach ($lessons as &$lesson) {
                $materialsQuery = "SELECT * FROM learning_materials WHERE lesson_id = :lesson_id ORDER BY created_at DESC";
                $materialsStmt = $db->prepare($materialsQuery);
                $materialsStmt->bindParam(':lesson_id', $lesson['id']);
                $materialsStmt->execute();
                $lesson['materials'] = $materialsStmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($lesson['materials'] as &$material) {
                    if (!empty($material['file_url'])) {
                        $material['file_url'] = normalize_public_file_url($material['file_url']);
                    }
                }
            }
            
            $module['lessons'] = $lessons;
        }
        
        http_response_code(200);
        echo json_encode([
            'course' => $course,
            'modules' => $modules
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['message' => 'Error: ' . $e->getMessage()]);
    }
}
?>

