<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, ngrok-skip-browser-warning');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

if (!$db) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);

$required = ['username', 'email', 'password', 'firstName', 'lastName', 'cellphoneNumber'];
foreach ($required as $field) {
    if (!isset($data[$field]) || trim((string)$data[$field]) === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => "Missing required field: $field"]);
        exit();
    }
}

$username = trim($data['username']);
$email = trim($data['email']);
$password = password_hash($data['password'], PASSWORD_BCRYPT);
$firstName = trim($data['firstName']);
$middleName = trim($data['middleName'] ?? '');
$lastName = trim($data['lastName']);
$suffix = trim($data['suffix'] ?? '');
$dateOfBirth = $data['dateOfBirth'] ?? '2000-01-01';
$sex = trim($data['sex'] ?? '');
$bloodType = trim($data['bloodType'] ?? '');
$civilStatus = trim($data['civilStatus'] ?? 'Single');
$typeOfDisability = trim($data['typeOfDisability'] ?? '');
$religion = trim($data['religion'] ?? '');
$educationalAttainment = trim($data['educationalAttainment'] ?? 'Not Specified');
$houseNoAndStreet = trim($data['houseNoAndStreet'] ?? '');
$barangay = trim($data['barangay'] ?? '');
$municipality = trim($data['municipality'] ?? '');
$province = trim($data['province'] ?? '');
$region = trim($data['region'] ?? '');
$cellphoneNumber = trim($data['cellphoneNumber']);
$profileImageUrl = trim($data['profileImageUrl'] ?? '');
$typeOfEmployment = trim($data['typeOfEmployment'] ?? '');
$civilServiceEligibilityLevel = trim($data['civilServiceEligibilityLevel'] ?? '');
$salaryGrade = trim($data['salaryGrade'] ?? '');
$presentPosition = trim($data['presentPosition'] ?? '');
$office = trim($data['office'] ?? '');
$service = trim($data['service'] ?? '');
$divisionProvince = trim($data['divisionProvince'] ?? '');
$emergencyContactName = trim($data['emergencyContactName'] ?? '');
$emergencyContactRelationship = trim($data['emergencyContactRelationship'] ?? '');
$emergencyContactAddress = trim($data['emergencyContactAddress'] ?? '');
$emergencyContactNumber = trim($data['emergencyContactNumber'] ?? '');
$emergencyContactEmail = trim($data['emergencyContactEmail'] ?? '');
$userType = trim($data['userType'] ?? 'student');
$userId = $data['id'] ?? uniqid('user-', true);

try {
    $checkStmt = $db->prepare('SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1');
    $checkStmt->execute([$username, $email]);

    if ($checkStmt->fetch(PDO::FETCH_ASSOC)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Username or email already exists']);
        exit();
    }

    $stmt = $db->prepare(
        'INSERT INTO users (
            id, username, email, password, user_type,
            first_name, middle_name, last_name, suffix, date_of_birth, sex, blood_type,
            civil_status, type_of_disability, religion, educational_attainment,
            house_no_and_street, barangay, municipality, province, region,
            cellphone_number, profile_image_url,
            type_of_employment, civil_service_eligibility_level, salary_grade,
            present_position, office, service, division_province,
            emergency_contact_name, emergency_contact_relationship, emergency_contact_address,
            emergency_contact_number, emergency_contact_email
        ) VALUES (
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?,
            ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?,
            ?, ?
        )'
    );

    $stmt->execute([
        $userId, $username, $email, $password, $userType,
        $firstName, $middleName ?: null, $lastName, $suffix ?: null, $dateOfBirth, $sex ?: 'Not Specified', $bloodType ?: null,
        $civilStatus, $typeOfDisability ?: null, $religion ?: null, $educationalAttainment,
        $houseNoAndStreet, $barangay, $municipality, $province, $region,
        $cellphoneNumber, $profileImageUrl ?: null,
        $typeOfEmployment ?: null, $civilServiceEligibilityLevel ?: null, $salaryGrade ?: null,
        $presentPosition ?: null, $office ?: null, $service ?: null, $divisionProvince ?: null,
        $emergencyContactName ?: null, $emergencyContactRelationship ?: null, $emergencyContactAddress ?: null,
        $emergencyContactNumber ?: null, $emergencyContactEmail ?: null
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Registration successful',
        'userId' => $userId
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
