<?php
// API endpoint for managing test questions and answers
// This file should be placed in: /xampp/htdocs/charming_api/admin/test-questions.php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
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
        case 'PUT':
            handlePut($db);
            break;
        case 'DELETE':
            handleDelete($db);
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
    if (isset($_GET['test_id'])) {
        // Get all questions for a test
        $testId = (int)$_GET['test_id'];
        
        $sql = "SELECT * FROM test_questions WHERE test_id = ? ORDER BY order_index";
        $stmt = $db->prepare($sql);
        $stmt->execute([$testId]);
        $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get answers for each question
        foreach ($questions as &$question) {
            $sql = "SELECT * FROM question_answers WHERE question_id = ? ORDER BY order_index";
            $stmt = $db->prepare($sql);
            $stmt->execute([$question['id']]);
            $question['answers'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        echo json_encode(['success' => true, 'questions' => $questions]);
    } elseif (isset($_GET['question_id'])) {
        // Get a specific question with answers
        $questionId = (int)$_GET['question_id'];
        
        $sql = "SELECT * FROM test_questions WHERE id = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute([$questionId]);
        $question = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$question) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Question not found']);
            return;
        }
        
        $sql = "SELECT * FROM question_answers WHERE question_id = ? ORDER BY order_index";
        $stmt = $db->prepare($sql);
        $stmt->execute([$questionId]);
        $question['answers'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'question' => $question]);
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    }
}

function handlePost($db) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['test_id']) || !isset($data['question_text'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        return;
    }
    
    $testId = (int)$data['test_id'];
    $questionText = $data['question_text'];
    $questionType = $data['question_type'] ?? 'multiple_choice';
    $points = $data['points'] ?? 1;
    $orderIndex = $data['order_index'] ?? 1;
    $answers = $data['answers'] ?? [];
    
    // Start transaction
    $db->beginTransaction();
    
    try {
        // Insert question
        $sql = "INSERT INTO test_questions (test_id, question_text, question_type, points, order_index) 
                VALUES (?, ?, ?, ?, ?)";
        $stmt = $db->prepare($sql);
        $stmt->execute([$testId, $questionText, $questionType, $points, $orderIndex]);
        $questionId = $db->lastInsertId();
        
        // Insert answers if provided
        if (!empty($answers)) {
            $sql = "INSERT INTO question_answers (question_id, answer_text, is_correct, order_index) 
                    VALUES (?, ?, ?, ?)";
            $stmt = $db->prepare($sql);
            
            foreach ($answers as $index => $answer) {
                $answerText = $answer['answer_text'];
                $isCorrect = isset($answer['is_correct']) ? (int)$answer['is_correct'] : 0;
                $answerOrder = $answer['order_index'] ?? ($index + 1);
                
                $stmt->execute([$questionId, $answerText, $isCorrect, $answerOrder]);
            }
        }
        
        $db->commit();
        echo json_encode(['success' => true, 'message' => 'Question created successfully', 'question_id' => $questionId]);
    } catch (Exception $e) {
        $db->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to create question: ' . $e->getMessage()]);
    }
}

function handlePut($db) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing question ID']);
        return;
    }
    
    $questionId = (int)$data['id'];
    
    // Start transaction
    $db->beginTransaction();
    
    try {
        // Update question
        $updates = [];
        $params = [];
        
        if (isset($data['question_text'])) {
            $updates[] = "question_text = ?";
            $params[] = $data['question_text'];
        }
        if (isset($data['question_type'])) {
            $updates[] = "question_type = ?";
            $params[] = $data['question_type'];
        }
        if (isset($data['points'])) {
            $updates[] = "points = ?";
            $params[] = (int)$data['points'];
        }
        if (isset($data['order_index'])) {
            $updates[] = "order_index = ?";
            $params[] = (int)$data['order_index'];
        }
        
        if (!empty($updates)) {
            $params[] = $questionId;
            $sql = "UPDATE test_questions SET " . implode(', ', $updates) . " WHERE id = ?";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
        }
        
        // Update answers if provided
        if (isset($data['answers'])) {
            // Delete existing answers
            $sql = "DELETE FROM question_answers WHERE question_id = ?";
            $stmt = $db->prepare($sql);
            $stmt->execute([$questionId]);
            
            // Insert new answers
            if (!empty($data['answers'])) {
                $sql = "INSERT INTO question_answers (question_id, answer_text, is_correct, order_index) 
                        VALUES (?, ?, ?, ?)";
                $stmt = $db->prepare($sql);
                
                foreach ($data['answers'] as $index => $answer) {
                    $answerText = $answer['answer_text'];
                    $isCorrect = isset($answer['is_correct']) ? (int)$answer['is_correct'] : 0;
                    $answerOrder = $answer['order_index'] ?? ($index + 1);
                    
                    $stmt->execute([$questionId, $answerText, $isCorrect, $answerOrder]);
                }
            }
        }
        
        $db->commit();
        echo json_encode(['success' => true, 'message' => 'Question updated successfully']);
    } catch (Exception $e) {
        $db->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to update question: ' . $e->getMessage()]);
    }
}

function handleDelete($db) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing question ID']);
        return;
    }
    
    $questionId = (int)$data['id'];
    
    $sql = "DELETE FROM test_questions WHERE id = ?";
    $stmt = $db->prepare($sql);
    $success = $stmt->execute([$questionId]);
    
    if ($success) {
        echo json_encode(['success' => true, 'message' => 'Question deleted successfully']);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to delete question']);
    }
}
?>

