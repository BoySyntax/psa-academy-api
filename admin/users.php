<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, ngrok-skip-browser-warning");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';

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
            $userId = $_GET['id'];
            $query = "SELECT * FROM users WHERE id = :id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':id', $userId);
            $stmt->execute();
            
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user) {
                unset($user['password']);
                http_response_code(200);
                echo json_encode(['user' => $user]);
            } else {
                http_response_code(404);
                echo json_encode(['message' => 'User not found']);
            }
        } elseif (isset($_GET['user_type'])) {
            $userType = $_GET['user_type'];
            $query = "SELECT * FROM users WHERE user_type = :user_type ORDER BY created_at DESC";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':user_type', $userType);
            $stmt->execute();
            
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($users as &$user) {
                unset($user['password']);
            }
            
            http_response_code(200);
            echo json_encode(['users' => $users]);
        } else {
            $query = "SELECT * FROM users ORDER BY created_at DESC";
            $stmt = $db->prepare($query);
            $stmt->execute();
            
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($users as &$user) {
                unset($user['password']);
            }
            
            http_response_code(200);
            echo json_encode(['users' => $users]);
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['message' => 'Database error: ' . $e->getMessage()]);
    }
}

function handlePost($db) {
    try {
        $data = json_decode(file_get_contents("php://input"), true);
        
        if (!isset($data['username']) || !isset($data['email']) || !isset($data['password'])) {
            http_response_code(400);
            echo json_encode(['message' => 'Missing required fields']);
            return;
        }

        $userType = isset($data['user_type']) && $data['user_type'] !== '' ? $data['user_type'] : 'student';
        
        $checkQuery = "SELECT id FROM users WHERE username = :username OR email = :email";
        $checkStmt = $db->prepare($checkQuery);
        $checkStmt->bindValue(':username', $data['username']);
        $checkStmt->bindValue(':email', $data['email']);
        $checkStmt->execute();
        
        if ($checkStmt->fetch()) {
            http_response_code(409);
            echo json_encode(['message' => 'Username or email already exists']);
            return;
        }
        
        $userId = generateUUID();
        $hashedPassword = password_hash($data['password'], PASSWORD_DEFAULT);
        
        $query = "INSERT INTO users (
            id, username, email, password, user_type,
            first_name, middle_name, last_name, suffix,
            date_of_birth, sex, blood_type, civil_status,
            type_of_disability, religion, educational_attainment,
            house_no_and_street, barangay, municipality, province, region,
            cellphone_number, type_of_employment, civil_service_eligibility_level,
            salary_grade, present_position, office, service, division_province,
            emergency_contact_name, emergency_contact_relationship,
            emergency_contact_address, emergency_contact_number, emergency_contact_email
        ) VALUES (
            :id, :username, :email, :password, :user_type,
            :first_name, :middle_name, :last_name, :suffix,
            :date_of_birth, :sex, :blood_type, :civil_status,
            :type_of_disability, :religion, :educational_attainment,
            :house_no_and_street, :barangay, :municipality, :province, :region,
            :cellphone_number, :type_of_employment, :civil_service_eligibility_level,
            :salary_grade, :present_position, :office, :service, :division_province,
            :emergency_contact_name, :emergency_contact_relationship,
            :emergency_contact_address, :emergency_contact_number, :emergency_contact_email
        )";
        
        $stmt = $db->prepare($query);
        
        $stmt->bindValue(':id', $userId);
        $stmt->bindValue(':username', $data['username']);
        $stmt->bindValue(':email', $data['email']);
        $stmt->bindValue(':password', $hashedPassword);
        $stmt->bindValue(':user_type', $userType);
        $stmt->bindValue(':first_name', $data['first_name'] ?? '');
        $stmt->bindValue(':middle_name', $data['middle_name'] ?? null);
        $stmt->bindValue(':last_name', $data['last_name'] ?? '');
        $stmt->bindValue(':suffix', $data['suffix'] ?? null);
        $stmt->bindValue(':date_of_birth', $data['date_of_birth'] ?? null);
        $stmt->bindValue(':sex', $data['sex'] ?? '');
        $stmt->bindValue(':blood_type', $data['blood_type'] ?? null);
        $stmt->bindValue(':civil_status', $data['civil_status'] ?? '');
        $stmt->bindValue(':type_of_disability', $data['type_of_disability'] ?? null);
        $stmt->bindValue(':religion', $data['religion'] ?? null);
        $stmt->bindValue(':educational_attainment', $data['educational_attainment'] ?? '');
        $stmt->bindValue(':house_no_and_street', $data['house_no_and_street'] ?? '');
        $stmt->bindValue(':barangay', $data['barangay'] ?? '');
        $stmt->bindValue(':municipality', $data['municipality'] ?? '');
        $stmt->bindValue(':province', $data['province'] ?? '');
        $stmt->bindValue(':region', $data['region'] ?? '');
        $stmt->bindValue(':cellphone_number', $data['cellphone_number'] ?? '');
        $stmt->bindValue(':type_of_employment', $data['type_of_employment'] ?? null);
        $stmt->bindValue(':civil_service_eligibility_level', $data['civil_service_eligibility_level'] ?? null);
        $stmt->bindValue(':salary_grade', $data['salary_grade'] ?? null);
        $stmt->bindValue(':present_position', $data['present_position'] ?? null);
        $stmt->bindValue(':office', $data['office'] ?? null);
        $stmt->bindValue(':service', $data['service'] ?? null);
        $stmt->bindValue(':division_province', $data['division_province'] ?? null);
        $stmt->bindValue(':emergency_contact_name', $data['emergency_contact_name'] ?? null);
        $stmt->bindValue(':emergency_contact_relationship', $data['emergency_contact_relationship'] ?? null);
        $stmt->bindValue(':emergency_contact_address', $data['emergency_contact_address'] ?? null);
        $stmt->bindValue(':emergency_contact_number', $data['emergency_contact_number'] ?? null);
        $stmt->bindValue(':emergency_contact_email', $data['emergency_contact_email'] ?? null);
        
        if ($stmt->execute()) {
            http_response_code(201);
            echo json_encode([
                'message' => 'User created successfully',
                'user' => ['id' => $userId]
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['message' => 'Failed to create user']);
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
            echo json_encode(['message' => 'User ID is required']);
            return;
        }
        
        $updateFields = [];
        $params = [':id' => $data['id']];
        
        $allowedFields = [
            'username', 'email', 'user_type', 'first_name', 'middle_name', 'last_name',
            'suffix', 'date_of_birth', 'sex', 'blood_type', 'civil_status',
            'type_of_disability', 'religion', 'educational_attainment',
            'house_no_and_street', 'barangay', 'municipality', 'province', 'region',
            'cellphone_number', 'type_of_employment', 'civil_service_eligibility_level',
            'salary_grade', 'present_position', 'office', 'service', 'division_province',
            'emergency_contact_name', 'emergency_contact_relationship',
            'emergency_contact_address', 'emergency_contact_number', 'emergency_contact_email'
        ];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updateFields[] = "$field = :$field";
                $params[":$field"] = $data[$field];
            }
        }
        
        if (isset($data['password'])) {
            $updateFields[] = "password = :password";
            $params[':password'] = password_hash($data['password'], PASSWORD_DEFAULT);
        }
        
        if (empty($updateFields)) {
            http_response_code(400);
            echo json_encode(['message' => 'No fields to update']);
            return;
        }
        
        $query = "UPDATE users SET " . implode(', ', $updateFields) . " WHERE id = :id";
        $stmt = $db->prepare($query);
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        if ($stmt->execute()) {
            http_response_code(200);
            echo json_encode(['message' => 'User updated successfully']);
        } else {
            http_response_code(500);
            echo json_encode(['message' => 'Failed to update user']);
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
            echo json_encode(['message' => 'User ID is required']);
            return;
        }
        
        $query = "DELETE FROM users WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $data['id']);
        
        if ($stmt->execute()) {
            if ($stmt->rowCount() > 0) {
                http_response_code(200);
                echo json_encode(['message' => 'User deleted successfully']);
            } else {
                http_response_code(404);
                echo json_encode(['message' => 'User not found']);
            }
        } else {
            http_response_code(500);
            echo json_encode(['message' => 'Failed to delete user']);
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['message' => 'Database error: ' . $e->getMessage()]);
    }
}

function generateUUID() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}
?>
