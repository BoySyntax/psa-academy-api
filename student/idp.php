<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, ngrok-skip-browser-warning');

ini_set('display_errors', '0');
error_reporting(E_ALL);

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Helper: respond with JSON
function respond($success, $data = null, $message = '') {
    $res = ['success' => $success];
    if ($data !== null) $res['idp'] = $data;
    if ($message) $res['message'] = $message;
    echo json_encode($res);
    exit;
}

require_once '../config/database.php';

try {
    $database = new Database();
    $conn = $database->getConnection();
    if (!$conn) {
        respond(false, null, 'DB connection failed');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $user_id = $_GET['user_id'] ?? null;
        if (!$user_id) {
            respond(false, null, 'Missing user_id');
        }
        // Load existing IDP for this user
        $stmt = $conn->prepare('SELECT i.idp_data, i.status, i.submitted_at, i.approved_by, i.approved_at, i.rejection_reason, i.management_message, approver.first_name AS approver_first_name, approver.last_name AS approver_last_name FROM idp i LEFT JOIN users approver ON i.approved_by = approver.id WHERE i.user_id = ?');
        $stmt->execute([$user_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result) {
            $data = json_decode($result['idp_data'], true);
            if (!is_array($data)) {
                $data = [];
            }
            $approvedByName = null;
            if (!empty($result['approver_first_name']) || !empty($result['approver_last_name'])) {
                $approvedByName = trim(($result['approver_first_name'] ?? '') . ' ' . ($result['approver_last_name'] ?? ''));
            }
            $data['_approval'] = [
                'status' => $result['status'],
                'submitted_at' => $result['submitted_at'],
                'approved_by' => $result['approved_by'],
                'approved_by_name' => $approvedByName,
                'approved_at' => $result['approved_at'],
                'rejection_reason' => $result['rejection_reason'],
                'management_message' => $result['management_message'],
            ];
            respond(true, $data);
        } else {
            // Return empty structure if none exists
            respond(true, [
                'employee_info' => [
                    'surname' => '',
                    'first_name' => '',
                    'middle_name' => '',
                    'section' => '',
                    'current_position' => '',
                    'unit_service' => '',
                    'salary_grade' => '',
                    'office' => '',
                    'years_current_position' => '',
                    'years_in_psa' => '',
                    'employment_status' => '',
                ],
                'purpose' => [
                    'meet_current_position' => false,
                    'meet_next_higher_position' => false,
                    'increase_current_position' => false,
                    'acquire_new_competencies' => false,
                ],
                'career_development_required' => '',
                'employee_goals_2026_2030' => '',
                'long_term_training' => [],
                'long_term_career_goal' => [
                    'long_term_position' => '',
                    'operating_unit' => '',
                    'office' => '',
                    'service' => '',
                    'unit' => '',
                    'division' => '',
                    'functional_type' => '',
                    'ready_in' => '',
                ],
                'experience_during_this_year' => '',
                'short_term_goal' => [
                    'next_step_position' => '',
                    'office' => '',
                    'service' => '',
                    'unit' => '',
                    'division' => '',
                    'functional_type' => '',
                    'ready_in' => '',
                ],
                'short_term_training_next_year' => [],
                'experience_during_past_year' => '',
                'certification' => [
                    'employee_signature' => '',
                    'employee_signature_date' => date('Y-m-d'),
                    'supervisor_signature' => '',
                    'supervisor_signature_date' => '',
                ],
            ]);
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Save/replace IDP for this user
        $input = json_decode(file_get_contents('php://input'), true);
        if (!isset($input['user_id'])) {
            respond(false, null, 'Missing user_id in payload');
        }

        $user_id = $input['user_id'];

        $json = json_encode($input);
        $sql = "INSERT INTO idp (user_id, idp_data, status, submitted_at, approved_by, approved_at, rejection_reason, management_message, updated_at)
                 VALUES (?, ?, 'pending', NOW(), NULL, NULL, NULL, NULL, NOW())
                 ON DUPLICATE KEY UPDATE
                   idp_data = VALUES(idp_data),
                   status = 'pending',
                   submitted_at = NOW(),
                   approved_by = NULL,
                   approved_at = NULL,
                   rejection_reason = NULL,
                   management_message = NULL,
                   updated_at = NOW()";
        $stmt = $conn->prepare($sql);
        if ($stmt->execute([$user_id, $json])) {
            respond(true, $input, 'IDP submitted for approval');
        } else {
            respond(false, null, 'Failed to submit IDP');
        }
    }

    respond(false, null, 'Unsupported request method');
} catch (Throwable $e) {
    respond(false, null, $e->getMessage());
}
?>

