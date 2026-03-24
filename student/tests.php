<?php
// API endpoint for students to take tests and view results
// This file should be placed in: /xampp/htdocs/charming_api/student/tests.php

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

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            handleGet($db);
            break;
        case 'POST':
            handlePost($db);
            break;
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

function handleGet($db) {
    if (isset($_GET['module_id']) && isset($_GET['student_id'])) {
        // Get tests for a module with student's attempt status
        $moduleId = (int)$_GET['module_id'];
        $studentId = (int)$_GET['student_id'];
        
        $sql = "SELECT mt.*, 
                       (SELECT COUNT(*) FROM student_test_attempts 
                        WHERE test_id = mt.id AND student_id = ? AND completed_at IS NOT NULL) as attempts_count,
                       (SELECT MAX(score) FROM student_test_attempts 
                        WHERE test_id = mt.id AND student_id = ? AND completed_at IS NOT NULL) as best_score,
                       (SELECT id FROM student_test_attempts 
                        WHERE test_id = mt.id AND student_id = ? AND completed_at IS NOT NULL
                        ORDER BY completed_at DESC, id DESC LIMIT 1) as last_completed_attempt_id,
                       (SELECT id FROM student_test_attempts 
                        WHERE test_id = mt.id AND student_id = ? AND completed_at IS NULL 
                        ORDER BY started_at DESC LIMIT 1) as active_attempt_id
                FROM module_tests mt
                WHERE mt.module_id = ? AND mt.is_published = 1
                ORDER BY mt.test_type";
        $stmt = $db->prepare($sql);
        $stmt->execute([$studentId, $studentId, $studentId, $studentId, $moduleId]);
        $tests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'tests' => $tests]);
    } elseif (isset($_GET['test_id']) && isset($_GET['student_id'])) {
        // Get test with questions for student to take
        $testId = (int)$_GET['test_id'];
        $studentId = (int)$_GET['student_id'];
        
        // Get test details
        $sql = "SELECT * FROM module_tests WHERE id = ? AND is_published = 1";
        $stmt = $db->prepare($sql);
        $stmt->execute([$testId]);
        $test = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$test) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Test not found or not published']);
            return;
        }
        
        // Get questions (without showing correct answers)
        $sql = "SELECT id, test_id, question_text, question_type, points, order_index 
                FROM test_questions WHERE test_id = ? ORDER BY order_index";
        $stmt = $db->prepare($sql);
        $stmt->execute([$testId]);
        $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get answer options (without showing which is correct)
        foreach ($questions as &$question) {
            $sql = "SELECT id, question_id, answer_text, order_index 
                    FROM question_answers WHERE question_id = ? ORDER BY order_index";
            $stmt = $db->prepare($sql);
            $stmt->execute([$question['id']]);
            $question['answers'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        $test['questions'] = $questions;
        
        // Check for already-completed attempt (single attempt policy)
        $sql = "SELECT id, started_at, completed_at, score, passed, time_taken_minutes
                FROM student_test_attempts
                WHERE test_id = ? AND student_id = ? AND completed_at IS NOT NULL
                ORDER BY completed_at DESC, id DESC LIMIT 1";
        $stmt = $db->prepare($sql);
        $stmt->execute([$testId, $studentId]);
        $completedAttempt = $stmt->fetch(PDO::FETCH_ASSOC);

        // Check for active attempt
        $sql = "SELECT id, started_at FROM student_test_attempts 
                WHERE test_id = ? AND student_id = ? AND completed_at IS NULL 
                ORDER BY started_at DESC LIMIT 1";
        $stmt = $db->prepare($sql);
        $stmt->execute([$testId, $studentId]);
        $activeAttempt = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($activeAttempt) {
            $test['active_attempt_id'] = $activeAttempt['id'];
            $test['started_at'] = $activeAttempt['started_at'];
        }

        if ($completedAttempt) {
            $test['completed_attempt'] = $completedAttempt;
        }
        
        echo json_encode(['success' => true, 'test' => $test]);
    } elseif (isset($_GET['attempt_id'])) {
        // Get attempt results
        $attemptId = (int)$_GET['attempt_id'];
        
        $sql = "SELECT sta.*, mt.test_title, mt.passing_score, mt.test_type
                FROM student_test_attempts sta
                JOIN module_tests mt ON sta.test_id = mt.id
                WHERE sta.id = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute([$attemptId]);
        $attempt = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$attempt) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Attempt not found']);
            return;
        }
        
        // Get student answers with correct/incorrect info
        $sql = "SELECT stans.*, tq.question_text, tq.points as max_points, qa.answer_text as selected_answer_text
                FROM student_test_answers stans
                JOIN test_questions tq ON stans.question_id = tq.id
                LEFT JOIN question_answers qa ON stans.selected_answer_id = qa.id
                WHERE stans.attempt_id = ?
                ORDER BY tq.order_index";
        $stmt = $db->prepare($sql);
        $stmt->execute([$attemptId]);
        $attempt['answers'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'attempt' => $attempt]);
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    }
}

function handlePost($db) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (isset($data['action']) && $data['action'] === 'start') {
        // Start a new test attempt
        if (!isset($data['test_id']) || !isset($data['student_id'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Missing required fields']);
            return;
        }
        
        $testId = (int)$data['test_id'];
        $studentId = (int)$data['student_id'];

        // Single attempt policy: if already completed, do not allow a new attempt
        $sql = "SELECT id, completed_at, score, passed
                FROM student_test_attempts
                WHERE test_id = ? AND student_id = ? AND completed_at IS NOT NULL
                ORDER BY completed_at DESC, id DESC LIMIT 1";
        $stmt = $db->prepare($sql);
        $stmt->execute([$testId, $studentId]);
        $completedAttempt = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($completedAttempt) {
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'message' => 'Test already completed. You can only take this test once.',
                'already_completed' => true,
                'attempt_id' => (int)$completedAttempt['id'],
                'score' => $completedAttempt['score'],
                'passed' => (int)$completedAttempt['passed']
            ]);
            return;
        }
        
        $sql = "INSERT INTO student_test_attempts (test_id, student_id, started_at) 
                VALUES (?, ?, NOW())";
        $stmt = $db->prepare($sql);
        $success = $stmt->execute([$testId, $studentId]);
        
        if ($success) {
            $attemptId = $db->lastInsertId();
            echo json_encode(['success' => true, 'message' => 'Test started', 'attempt_id' => $attemptId]);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to start test']);
        }
    } elseif (isset($data['action']) && $data['action'] === 'submit') {
        // Submit test answers
        if (!isset($data['attempt_id']) || !isset($data['answers'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Missing required fields']);
            return;
        }
        
        $attemptId = (int)$data['attempt_id'];
        $answers = $data['answers'];
        
        $db->beginTransaction();
        
        try {
            // Get test details
            $sql = "SELECT mt.*, sta.started_at 
                    FROM student_test_attempts sta
                    JOIN module_tests mt ON sta.test_id = mt.id
                    WHERE sta.id = ?";
            $stmt = $db->prepare($sql);
            $stmt->execute([$attemptId]);
            $testInfo = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$testInfo) {
                throw new Exception('Test attempt not found');
            }
            
            $totalPoints = 0;
            $earnedPoints = 0;
            
            // Process each answer
            foreach ($answers as $answer) {
                $questionId = (int)$answer['question_id'];
                $selectedAnswerId = isset($answer['selected_answer_id']) ? (int)$answer['selected_answer_id'] : null;
                $answerText = isset($answer['answer_text']) ? $answer['answer_text'] : null;
                
                // Get question details
                $sql = "SELECT points FROM test_questions WHERE id = ?";
                $stmt = $db->prepare($sql);
                $stmt->execute([$questionId]);
                $question = $stmt->fetch(PDO::FETCH_ASSOC);
                $questionPoints = $question['points'];
                $totalPoints += $questionPoints;
                
                $isCorrect = 0;
                $pointsEarned = 0;
                
                if ($selectedAnswerId) {
                    // Check if selected answer is correct
                    $sql = "SELECT is_correct FROM question_answers WHERE id = ?";
                    $stmt = $db->prepare($sql);
                    $stmt->execute([$selectedAnswerId]);
                    $answerInfo = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($answerInfo && $answerInfo['is_correct']) {
                        $isCorrect = 1;
                        $pointsEarned = $questionPoints;
                        $earnedPoints += $pointsEarned;
                    }
                }
                
                // Save student answer
                $sql = "INSERT INTO student_test_answers 
                        (attempt_id, question_id, selected_answer_id, answer_text, is_correct, points_earned) 
                        VALUES (?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE 
                        selected_answer_id = VALUES(selected_answer_id),
                        answer_text = VALUES(answer_text),
                        is_correct = VALUES(is_correct),
                        points_earned = VALUES(points_earned)";
                $stmt = $db->prepare($sql);
                $stmt->execute([$attemptId, $questionId, $selectedAnswerId, $answerText, $isCorrect, $pointsEarned]);
            }
            
            // Calculate score percentage
            $score = $totalPoints > 0 ? ($earnedPoints / $totalPoints) * 100 : 0;
            $passed = $score >= $testInfo['passing_score'] ? 1 : 0;
            
            // Calculate time taken
            $startTime = new DateTime($testInfo['started_at']);
            $endTime = new DateTime();
            $timeTaken = $endTime->diff($startTime)->i; // minutes
            
            // Update attempt with results
            $sql = "UPDATE student_test_attempts 
                    SET completed_at = NOW(), score = ?, passed = ?, time_taken_minutes = ?
                    WHERE id = ?";
            $stmt = $db->prepare($sql);
            $stmt->execute([$score, $passed, $timeTaken, $attemptId]);
            
            $db->commit();
            
            echo json_encode([
                'success' => true, 
                'message' => 'Test submitted successfully',
                'score' => round($score, 2),
                'passed' => $passed,
                'earned_points' => $earnedPoints,
                'total_points' => $totalPoints
            ]);
        } catch (Exception $e) {
            $db->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to submit test: ' . $e->getMessage()]);
        }
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
}
?>

