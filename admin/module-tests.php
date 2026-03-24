<?php
// API endpoint for managing module tests (pre-test and post-test)
// This file should be placed in: /xampp/htdocs/charming_api/admin/module-tests.php

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
    if (isset($_GET['module_id'])) {
        // Get tests for a specific module
        $moduleId = (int)$_GET['module_id'];
        
        $sql = "SELECT * FROM module_tests WHERE module_id = ? ORDER BY test_type";
        $stmt = $db->prepare($sql);
        $stmt->execute([$moduleId]);
        $tests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'tests' => $tests]);
    } elseif (isset($_GET['test_id'])) {
        // Get a specific test with questions
        $testId = (int)$_GET['test_id'];
        
        $sql = "SELECT * FROM module_tests WHERE id = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute([$testId]);
        $test = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$test) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Test not found']);
            return;
        }
        
        // Get questions for this test
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
        
        $test['questions'] = $questions;
        
        echo json_encode(['success' => true, 'test' => $test]);
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    }
}

function handlePost($db) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['module_id']) || !isset($data['test_type'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        return;
    }
    
    $moduleId = (int)$data['module_id'];
    $testType = $data['test_type'];
    $testTitle = $data['test_title'] ?? ($testType === 'pre_test' ? 'Pre-Test' : 'Post-Test');
    $description = $data['description'] ?? null;
    $passingScore = $data['passing_score'] ?? 70;
    $timeLimitMinutes = $data['time_limit_minutes'] ?? null;
    $isPublished = isset($data['is_published']) ? (int)$data['is_published'] : 0;
    
    // Check if test already exists for this module and type
    $sql = "SELECT id FROM module_tests WHERE module_id = ? AND test_type = ?";
    $stmt = $db->prepare($sql);
    $stmt->execute([$moduleId, $testType]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existing) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Test already exists for this module']);
        return;
    }
    
    $sql = "INSERT INTO module_tests (module_id, test_type, test_title, description, passing_score, time_limit_minutes, is_published) 
            VALUES (?, ?, ?, ?, ?, ?, ?)";
    $stmt = $db->prepare($sql);
    $success = $stmt->execute([$moduleId, $testType, $testTitle, $description, $passingScore, $timeLimitMinutes, $isPublished]);
    
    if ($success) {
        $testId = $db->lastInsertId();
        echo json_encode(['success' => true, 'message' => 'Test created successfully', 'test_id' => $testId]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to create test']);
    }
}

function handlePut($db) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing test ID']);
        return;
    }
    
    $testId = (int)$data['id'];
    $updates = [];
    $params = [];
    
    if (isset($data['test_title'])) {
        $updates[] = "test_title = ?";
        $params[] = $data['test_title'];
    }
    if (isset($data['description'])) {
        $updates[] = "description = ?";
        $params[] = $data['description'];
    }
    if (isset($data['passing_score'])) {
        $updates[] = "passing_score = ?";
        $params[] = (int)$data['passing_score'];
    }
    if (isset($data['time_limit_minutes'])) {
        $updates[] = "time_limit_minutes = ?";
        $params[] = $data['time_limit_minutes'] ? (int)$data['time_limit_minutes'] : null;
    }
    if (isset($data['is_published'])) {
        $updates[] = "is_published = ?";
        $params[] = (int)$data['is_published'];
    }
    
    if (empty($updates)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'No fields to update']);
        return;
    }
    
    $params[] = $testId;
    $sql = "UPDATE module_tests SET " . implode(', ', $updates) . " WHERE id = ?";
    $stmt = $db->prepare($sql);
    $success = $stmt->execute($params);
    
    if ($success) {
        echo json_encode(['success' => true, 'message' => 'Test updated successfully']);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to update test']);
    }
}

function handleDelete($db) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing test ID']);
        return;
    }
    
    $testId = (int)$data['id'];
    
    $sql = "DELETE FROM module_tests WHERE id = ?";
    $stmt = $db->prepare($sql);
    $success = $stmt->execute([$testId]);
    
    if ($success) {
        echo json_encode(['success' => true, 'message' => 'Test deleted successfully']);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to delete test']);
    }
}
?>

