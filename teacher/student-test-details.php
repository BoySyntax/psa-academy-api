<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, ngrok-skip-browser-warning');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

// Get parameters
$studentId = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;
$courseCode = isset($_GET['course_id']) ? $_GET['course_id'] : '';

if ($studentId <= 0 || empty($courseCode)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Student ID and Course ID are required']);
    exit();
}

try {
    if (!$db) {
        throw new Exception('Database connection failed');
    }

    // Get course ID from course code
    $courseQuery = "SELECT id FROM courses WHERE course_code = :course_code";
    $courseStmt = $db->prepare($courseQuery);
    $courseStmt->bindParam(':course_code', $courseCode);
    $courseStmt->execute();
    $course = $courseStmt->fetch(PDO::FETCH_ASSOC);

    if (!$course) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Course not found']);
        exit();
    }

    $courseId = $course['id'];

    // Get all test attempts for this student in this course
    $query = "
        SELECT 
            sta.id as attempt_id,
            sta.started_at,
            sta.completed_at,
            sta.score,
            sta.passed,
            sta.time_taken_minutes,
            mt.id as test_id,
            mt.test_title,
            mt.test_type,
            mt.passing_score,
            cm.module_name
        FROM student_test_attempts sta
        JOIN module_tests mt ON sta.test_id = mt.id
        JOIN course_modules cm ON mt.module_id = cm.id
        WHERE sta.student_id = :student_id 
        AND cm.course_id = :course_id
        AND sta.completed_at IS NOT NULL
        ORDER BY sta.completed_at DESC
    ";

    $stmt = $db->prepare($query);
    $stmt->bindParam(':student_id', $studentId, PDO::PARAM_INT);
    $stmt->bindParam(':course_id', $courseId, PDO::PARAM_INT);
    $stmt->execute();
    $attempts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $testDetails = [];
    
    foreach ($attempts as $attempt) {
        // Get student answers with question details
        $answersQuery = "
            SELECT 
                stans.selected_answer_id,
                stans.answer_text,
                tq.id as question_id,
                tq.question_text,
                tq.question_type,
                tq.points as max_points,
                tq.order_index,
                qa.answer_text as selected_answer_text,
                qa.is_correct as selected_is_correct,
                (SELECT qa2.answer_text 
                 FROM question_answers qa2 
                 WHERE qa2.question_id = tq.id 
                 AND qa2.is_correct = 1 
                 LIMIT 1) as correct_answer_text
            FROM student_test_answers stans
            JOIN test_questions tq ON stans.question_id = tq.id
            LEFT JOIN question_answers qa ON stans.selected_answer_id = qa.id
            WHERE stans.attempt_id = :attempt_id
            ORDER BY tq.order_index
        ";

        $answersStmt = $db->prepare($answersQuery);
        $answersStmt->bindParam(':attempt_id', $attempt['attempt_id'], PDO::PARAM_INT);
        $answersStmt->execute();
        $answers = $answersStmt->fetchAll(PDO::FETCH_ASSOC);

        // Process answers to determine correctness
        $processedAnswers = [];
        $totalPoints = 0;
        $earnedPoints = 0;

        foreach ($answers as $answer) {
            $totalPoints += (int)$answer['max_points'];
            
            $isCorrect = false;
            if ($answer['question_type'] === 'multiple_choice' || $answer['question_type'] === 'true_false') {
                $isCorrect = (int)$answer['selected_is_correct'] === 1;
            } elseif ($answer['question_type'] === 'short_answer') {
                // For short answers, we'd need manual grading or keyword matching
                // For now, assume it's correct if score > 0
                $isCorrect = true; // Placeholder
            }

            if ($isCorrect) {
                $earnedPoints += (int)$answer['max_points'];
            }

            // Get all answer options for this question (only for multiple choice)
            $allOptions = [];
            if ($answer['question_type'] === 'multiple_choice') {
                $optionsQuery = "
                    SELECT answer_text, is_correct, order_index
                    FROM question_answers
                    WHERE question_id = :question_id
                    ORDER BY order_index
                ";
                $optionsStmt = $db->prepare($optionsQuery);
                $optionsStmt->bindParam(':question_id', $answer['question_id'], PDO::PARAM_INT);
                $optionsStmt->execute();
                $allOptions = $optionsStmt->fetchAll(PDO::FETCH_ASSOC);
            }

            $processedAnswers[] = [
                'questionNumber' => (int)$answer['order_index'] + 1,
                'question' => $answer['question_text'],
                'type' => $answer['question_type'],
                'studentAnswer' => $answer['selected_answer_text'] ?: $answer['answer_text'],
                'correctAnswer' => $answer['correct_answer_text'],
                'isCorrect' => $isCorrect,
                'points' => (int)$answer['max_points'],
                'allOptions' => $allOptions
            ];
        }

        $testDetails[] = [
            'testType' => ucfirst(str_replace('_', ' ', $attempt['test_type'])),
            'testTitle' => $attempt['test_title'],
            'moduleName' => $attempt['module_name'],
            'score' => (int)$attempt['score'],
            'passed' => (int)$attempt['passed'] === 1,
            'passingScore' => (int)$attempt['passing_score'],
            'startedAt' => $attempt['started_at'],
            'completedAt' => $attempt['completed_at'],
            'timeTaken' => $attempt['time_taken_minutes'] ? (int)$attempt['time_taken_minutes'] : null,
            'totalPoints' => $totalPoints,
            'earnedPoints' => $earnedPoints,
            'answers' => $processedAnswers
        ];
    }

    echo json_encode([
        'success' => true,
        'testDetails' => $testDetails,
        'message' => 'Test details retrieved successfully'
    ]);

} catch (Exception $e) {
    error_log("Error fetching student test details: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error: ' . $e->getMessage()
    ]);
}
?>

