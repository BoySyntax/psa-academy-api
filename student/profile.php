<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, ngrok-skip-browser-warning");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';
require_once '../config/url_helper.php';

$database = new Database();
$db = $database->getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header("Content-Type: application/json");

    $user_id = isset($_GET['user_id']) ? $_GET['user_id'] : null;

    if (!$user_id) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'User ID is required'
        ]);
        exit();
    }

    try {
        $query = "SELECT 
                    id,
                    username,
                    email,
                    user_type,
                    first_name,
                    middle_name,
                    last_name,
                    suffix,
                    date_of_birth,
                    sex,
                    blood_type,
                    civil_status,
                    type_of_disability,
                    religion,
                    educational_attainment,
                    house_no_and_street,
                    barangay,
                    municipality,
                    province,
                    region,
                    cellphone_number,
                    type_of_employment,
                    civil_service_eligibility_level,
                    salary_grade,
                    present_position,
                    office,
                    service,
                    division_province,
                    emergency_contact_name,
                    emergency_contact_relationship,
                    emergency_contact_address,
                    emergency_contact_number,
                    emergency_contact_email,
                    profile_image_url,
                    created_at,
                    updated_at
                  FROM users 
                  WHERE id = :id 
                  LIMIT 1";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $user_id);
        $stmt->execute();

        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'User not found'
            ]);
            exit();
        }

        if (!empty($user['profile_image_url'])) {
            $user['profile_image_url'] = normalize_public_file_url($user['profile_image_url']);
        }

        http_response_code(200);
        echo json_encode([
            'success' => true,
            'profile' => $user
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error: ' . $e->getMessage()
        ]);
    }
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header("Content-Type: application/json");

    $user_id = isset($_POST['user_id']) ? $_POST['user_id'] : null;

    if (!$user_id) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'User ID is required'
        ]);
        exit();
    }

    if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'No file uploaded or upload error occurred'
        ]);
        exit();
    }

    $file = $_FILES['image'];
    $fileName = $file['name'];
    $fileTmpName = $file['tmp_name'];
    $fileSize = $file['size'];

    $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    if (!in_array($fileExt, $allowedExtensions)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid file type. Allowed: JPG, JPEG, PNG, GIF, WEBP'
        ]);
        exit();
    }

    if ($fileSize > 5 * 1024 * 1024) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'File size too large. Maximum 5MB allowed'
        ]);
        exit();
    }

    try {
        $uploadDir = '../uploads/profile_images/';
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $uniqueFileName = uniqid('profile_', true) . '.' . $fileExt;
        $uploadPath = $uploadDir . $uniqueFileName;

        if (!move_uploaded_file($fileTmpName, $uploadPath)) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Failed to save uploaded file'
            ]);
            exit();
        }

        $fileUrl = make_local_file_url('uploads/profile_images/' . $uniqueFileName);

        $updateQuery = "UPDATE users SET profile_image_url = :profile_image_url WHERE id = :id";
        $updateStmt = $db->prepare($updateQuery);
        $updateStmt->bindParam(':profile_image_url', $fileUrl);
        $updateStmt->bindParam(':id', $user_id);

        if (!$updateStmt->execute()) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Failed to update user profile'
            ]);
            exit();
        }

        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Profile image updated successfully',
            'profile_image_url' => $fileUrl
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error: ' . $e->getMessage()
        ]);
    }
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    header("Content-Type: application/json");

    $data = json_decode(file_get_contents("php://input"), true);
    $user_id = isset($data['user_id']) ? $data['user_id'] : null;

    if (!$user_id) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'User ID is required'
        ]);
        exit();
    }

    try {
        // Build the SET part dynamically based on provided fields
        $allowed_fields = [
            'first_name', 'middle_name', 'last_name', 'suffix',
            'date_of_birth', 'sex', 'blood_type', 'civil_status',
            'type_of_disability', 'religion', 'educational_attainment',
            'house_no_and_street', 'barangay', 'municipality', 'province', 'region',
            'cellphone_number', 'type_of_employment', 'civil_service_eligibility_level',
            'salary_grade', 'present_position', 'office', 'service', 'division_province',
            'emergency_contact_name', 'emergency_contact_relationship',
            'emergency_contact_address', 'emergency_contact_number', 'emergency_contact_email'
        ];

        $set_parts = [];
        $params = [];
        foreach ($allowed_fields as $field) {
            if (isset($data[$field])) {
                $set_parts[] = "$field = :$field";
                $params[":$field"] = $data[$field];
            }
        }

        if (empty($set_parts)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'No valid fields to update'
            ]);
            exit();
        }

        $params[':id'] = $user_id;

        $set_parts[] = "updated_at = NOW()";

        $query = "UPDATE users SET " . implode(', ', $set_parts) . " WHERE id = :id";
        $stmt = $db->prepare($query);

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }

        if ($stmt->execute()) {
            $profileQuery = "SELECT 
                    id,
                    username,
                    email,
                    user_type,
                    first_name,
                    middle_name,
                    last_name,
                    suffix,
                    date_of_birth,
                    sex,
                    blood_type,
                    civil_status,
                    type_of_disability,
                    religion,
                    educational_attainment,
                    house_no_and_street,
                    barangay,
                    municipality,
                    province,
                    region,
                    cellphone_number,
                    type_of_employment,
                    civil_service_eligibility_level,
                    salary_grade,
                    present_position,
                    office,
                    service,
                    division_province,
                    emergency_contact_name,
                    emergency_contact_relationship,
                    emergency_contact_address,
                    emergency_contact_number,
                    emergency_contact_email,
                    profile_image_url,
                    created_at,
                    updated_at
                  FROM users 
                  WHERE id = :id 
                  LIMIT 1";
            $profileStmt = $db->prepare($profileQuery);
            $profileStmt->bindParam(':id', $user_id);
            $profileStmt->execute();
            $updatedProfile = $profileStmt->fetch(PDO::FETCH_ASSOC);

            if (!empty($updatedProfile['profile_image_url'])) {
                $updatedProfile['profile_image_url'] = normalize_public_file_url($updatedProfile['profile_image_url']);
            }

            http_response_code(200);
            echo json_encode([
                'success' => true,
                'message' => 'Profile updated successfully',
                'profile' => $updatedProfile
            ]);
        } else {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Failed to update profile'
            ]);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error: ' . $e->getMessage()
        ]);
    }
    exit();
}

http_response_code(405);
echo json_encode([
    'success' => false,
    'message' => 'Method not allowed'
]);
?>

