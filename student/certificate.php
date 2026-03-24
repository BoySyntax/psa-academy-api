<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, ngrok-skip-browser-warning');
header("Content-Type: application/pdf");
header("Content-Disposition: inline; filename=\"certificate.pdf\"");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';
require_once '../vendor/autoload.php';

use setasign\Fpdi\Fpdi;

$database = new Database();
$db = $database->getConnection();

$user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : null;
$course_id = isset($_GET['course_id']) ? (int)$_GET['course_id'] : null;

if (!$user_id || !$course_id) {
    http_response_code(400);
    echo 'Missing user_id or course_id';
    exit();
}

try {
    // 1) Verify evaluation submitted and get evaluation data
    $evalQuery = "SELECT venue, date_of_conduct, training_program FROM training_evaluations WHERE user_id = :user_id AND course_id = :course_id LIMIT 1";
    $evalStmt = $db->prepare($evalQuery);
    $evalStmt->bindParam(':user_id', $user_id);
    $evalStmt->bindParam(':course_id', $course_id);
    $evalStmt->execute();
    $evaluation = $evalStmt->fetch(PDO::FETCH_ASSOC);
    if (!$evaluation) {
        http_response_code(403);
        echo 'Certificate not available: evaluation not submitted';
        exit();
    }

    // 2) Get user data
    $userQuery = "SELECT first_name, last_name FROM users WHERE id = :user_id LIMIT 1";
    $userStmt = $db->prepare($userQuery);
    $userStmt->bindParam(':user_id', $user_id);
    $userStmt->execute();
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        http_response_code(404);
        echo 'User not found';
        exit();
    }

    // 3) Get course data
    $courseQuery = "SELECT course_name, duration_hours, subcategory FROM courses WHERE id = :course_id LIMIT 1";
    $courseStmt = $db->prepare($courseQuery);
    $courseStmt->bindParam(':course_id', $course_id);
    $courseStmt->execute();
    $course = $courseStmt->fetch(PDO::FETCH_ASSOC);
    if (!$course) {
        http_response_code(404);
        echo 'Course not found';
        exit();
    }

    // 4) Prepare values
    $fullName = trim($user['first_name'] . ' ' . $user['last_name']);
    $trainingTitle = $course['course_name'];
    $durationHours = isset($course['duration_hours']) ? (int)$course['duration_hours'] : 0;
    $subCategory = $course['subcategory'] ?? '';
    $venue = $evaluation['venue'] ?? '';
    $conductDateRaw = $evaluation['date_of_conduct'] ?? null;
    $conductDate = $conductDateRaw ? date('F d, Y', strtotime($conductDateRaw)) : '';
    $givenDay = $conductDateRaw ? date('jS', strtotime($conductDateRaw)) : date('jS');
    $givenMonthYear = $conductDateRaw ? date('F Y', strtotime($conductDateRaw)) : date('F Y');
    $dateCompleted = date('F d, Y');

    // 5) Load template and overlay text
    $templatePath = __DIR__ . '/../assets/certificates/e-cirtificate.pdf';
    if (!file_exists($templatePath)) {
        http_response_code(500);
        echo 'Certificate template not found';
        exit();
    }

    $pdf = new Fpdi();
    $pageCount = $pdf->setSourceFile($templatePath);
    $templateId = $pdf->importPage(1);
    $size = $pdf->getTemplateSize($templateId);

    $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
    $pdf->useTemplate($templateId);

    $pdf->SetTextColor(0, 0, 0);

    $pageW = $size['width'];
    $xMargin = 18;
    $contentW = $pageW - ($xMargin * 2);

    $fitText = function ($pdf, $family, $style, $maxSize, $minSize, $text, $maxW) {
        $size = $maxSize;
        while ($size >= $minSize) {
            $pdf->SetFont($family, $style, $size);
            if ($pdf->GetStringWidth($text) <= $maxW) {
                return $size;
            }
            $size -= 1;
        }
        $pdf->SetFont($family, $style, $minSize);
        return $minSize;
    };

    $whiteOutLine = function ($pdf, $x, $y, $w, $h) {
        $pdf->SetFillColor(255, 255, 255);
        $pdf->Rect($x, $y, $w, $h, 'F');
    };

    // Coordinates tuned for the latest template (units are in mm)
    $yParticipantName = 100;
    $ySecondSentence = 116;
    $yTrainingTitle = 128;
    $yDetailsLine = 165;
    $yDateCompleted = 175;

    // Participant name (38pt Akleila; fallback Times)
    $whiteOutLine($pdf, $xMargin, $yParticipantName - 3, $contentW, 18);
    $akleilaFontFile = __DIR__ . '/../assets/fonts/Akleila.php';
    if (file_exists($akleilaFontFile)) {
        $pdf->AddFont('Akleila', '', 'Akleila.php');
        $fitText($pdf, 'Akleila', '', 38, 24, $fullName, $contentW);
        $pdf->SetXY($xMargin, $yParticipantName);
        $pdf->Cell($contentW, 14, $fullName, 0, 0, 'C');
    } else {
        $fitText($pdf, 'Times', 'B', 38, 24, $fullName, $contentW);
        $pdf->SetXY($xMargin, $yParticipantName);
        $pdf->Cell($contentW, 14, $fullName, 0, 0, 'C');
    }

    // 2nd sentence (16pt Times New Roman)
    $secondSentence = 'for successfully completing the course';
    $whiteOutLine($pdf, $xMargin, $ySecondSentence - 2, $contentW, 10);
    $pdf->SetFont('Times', '', 16);
    $pdf->SetXY($xMargin, $ySecondSentence);
    $pdf->Cell($contentW, 8, $secondSentence, 0, 0, 'C');

    // 3rd sentence / Course title (42pt Times New Roman)
    $whiteOutLine($pdf, $xMargin, $yTrainingTitle - 4, $contentW, 18);
    $fitText($pdf, 'Times', 'B', 42, 24, $trainingTitle, $contentW);
    $pdf->SetXY($xMargin, $yTrainingTitle);
    $pdf->Cell($contentW, 14, $trainingTitle, 0, 0, 'C');

    // Duration hrs + Subcategory content (16pt Times New Roman)
    $detailsParts = [];
    if ($durationHours > 0) {
        $detailsParts[] = 'Duration: ' . $durationHours . ' hour' . ($durationHours === 1 ? '' : 's');
    }
    if (trim($subCategory) !== '') {
        $detailsParts[] = $subCategory;
    }
    $detailsLine = implode(' • ', $detailsParts);
    $whiteOutLine($pdf, $xMargin, $yDetailsLine - 2, $contentW, 10);
    $pdf->SetFont('Times', '', 16);
    $pdf->SetXY($xMargin, $yDetailsLine);
    $pdf->Cell($contentW, 8, $detailsLine, 0, 0, 'C');

    // Date completed (16pt Times New Roman)
    $dateLine = 'Date Completed: ' . $dateCompleted;
    $whiteOutLine($pdf, $xMargin, $yDateCompleted - 2, $contentW, 10);
    $pdf->SetFont('Times', '', 16);
    $pdf->SetXY($xMargin, $yDateCompleted);
    $pdf->Cell($contentW, 8, $dateLine, 0, 0, 'C');

    $pdf->Output('I', 'certificate.pdf');
} catch (Exception $e) {
    http_response_code(500);
    echo 'Error generating certificate: ' . $e->getMessage();
}
?>

