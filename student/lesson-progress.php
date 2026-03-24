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

$database = new Database();
$db = $database->getConnection();

// Update lesson progress
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $data = json_decode(file_get_contents("php://input"), true);
        
        $student_id = isset($data['student_id']) ? $data['student_id'] : null;
        $lesson_id = isset($data['lesson_id']) ? $data['lesson_id'] : null;
        $status = isset($data['status']) ? $data['status'] : 'in_progress';
        $progress_percentage = isset($data['progress_percentage']) ? $data['progress_percentage'] : 0;
        
        if (!$student_id || !$lesson_id) {
            http_response_code(400);
            echo json_encode(['message' => 'Student ID and Lesson ID are required']);
            exit();
        }
        
        // Check if progress record exists
        $checkQuery = "SELECT id FROM lesson_progress 
                       WHERE student_id = :student_id AND lesson_id = :lesson_id";
        $checkStmt = $db->prepare($checkQuery);
        $checkStmt->bindParam(':student_id', $student_id);
        $checkStmt->bindParam(':lesson_id', $lesson_id);
        $checkStmt->execute();
        
        $exists = $checkStmt->fetch();
        
        if ($exists) {
            // Update existing progress
            $updateQuery = "UPDATE lesson_progress 
                            SET status = :status, 
                                progress_percentage = :progress_percentage,
                                completed_at = CASE WHEN :status = 'completed' THEN NOW() ELSE completed_at END
                            WHERE student_id = :student_id AND lesson_id = :lesson_id";
            $updateStmt = $db->prepare($updateQuery);
            $updateStmt->bindParam(':status', $status);
            $updateStmt->bindParam(':progress_percentage', $progress_percentage);
            $updateStmt->bindParam(':student_id', $student_id);
            $updateStmt->bindParam(':lesson_id', $lesson_id);
            $updateStmt->execute();
        } else {
            // Insert new progress record
            $insertQuery = "INSERT INTO lesson_progress 
                            (student_id, lesson_id, status, progress_percentage, started_at, completed_at)
                            VALUES (:student_id, :lesson_id, :status, :progress_percentage, NOW(), 
                                    CASE WHEN :status = 'completed' THEN NOW() ELSE NULL END)";
            $insertStmt = $db->prepare($insertQuery);
            $insertStmt->bindParam(':student_id', $student_id);
            $insertStmt->bindParam(':lesson_id', $lesson_id);
            $insertStmt->bindParam(':status', $status);
            $insertStmt->bindParam(':progress_percentage', $progress_percentage);
            $insertStmt->execute();
        }
        
        // Update course enrollment progress
        updateCourseProgress($db, $student_id, $lesson_id);
        
        http_response_code(200);
        echo json_encode(['message' => 'Progress updated successfully']);
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['message' => 'Database error: ' . $e->getMessage()]);
    }
}

function updateCourseProgress($db, $student_id, $lesson_id) {
    // Get course_id from lesson
    $courseQuery = "SELECT cm.course_id 
                    FROM course_lessons cl
                    JOIN course_modules cm ON cl.module_id = cm.id
                    WHERE cl.id = :lesson_id";
    $courseStmt = $db->prepare($courseQuery);
    $courseStmt->bindParam(':lesson_id', $lesson_id);
    $courseStmt->execute();
    $courseData = $courseStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($courseData) {
        $course_id = $courseData['course_id'];
        
        // Calculate overall course progress
        $progressQuery = "SELECT 
                          COUNT(cl.id) as total_lessons,
                          SUM(CASE WHEN lp.status = 'completed' THEN 1 ELSE 0 END) as completed_lessons
                          FROM course_lessons cl
                          JOIN course_modules cm ON cl.module_id = cm.id
                          LEFT JOIN lesson_progress lp ON cl.id = lp.lesson_id AND lp.student_id = :student_id
                          WHERE cm.course_id = :course_id";
        $progressStmt = $db->prepare($progressQuery);
        $progressStmt->bindParam(':student_id', $student_id);
        $progressStmt->bindParam(':course_id', $course_id);
        $progressStmt->execute();
        $progress = $progressStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($progress && $progress['total_lessons'] > 0) {
            $percentage = ($progress['completed_lessons'] / $progress['total_lessons']) * 100;
            
            // Update enrollment progress
            $updateQuery = "UPDATE course_enrollments 
                            SET progress_percentage = :percentage,
                                status = CASE 
                                    WHEN :percentage >= 100 THEN 'completed'
                                    WHEN :percentage > 0 THEN 'in_progress'
                                    ELSE status
                                END,
                                completion_date = CASE WHEN :percentage >= 100 THEN NOW() ELSE NULL END
                            WHERE student_id = :student_id AND course_id = :course_id";
            $updateStmt = $db->prepare($updateQuery);
            $updateStmt->bindParam(':percentage', $percentage);
            $updateStmt->bindParam(':student_id', $student_id);
            $updateStmt->bindParam(':course_id', $course_id);
            $updateStmt->execute();
        }
    }
}
?>

