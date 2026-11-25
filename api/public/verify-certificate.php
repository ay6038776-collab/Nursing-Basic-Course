<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

$certNumber = trim((string)($_GET['cert'] ?? ''));

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');

if (!$certNumber) {
    echo json_encode(['success' => false, 'message' => 'رقم الشهادة مطلوب.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$stmt = $pdo->prepare("
    SELECT cert.certificate_number, cert.issued_at, cert.issuer_name, cert.status, cert.revoke_reason,
           u.full_name AS student_name,
           c.title_ar AS course_title, c.title_en AS course_title_en
    FROM certificates cert
    JOIN users u ON u.id = cert.student_id
    JOIN courses c ON c.id = cert.course_id
    WHERE cert.certificate_number = ?
");
$stmt->execute([$certNumber]);
$cert = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$cert) {
    http_response_code(404);
    echo json_encode(['success' => false, 'valid' => false, 'message' => 'لم يتم العثور على شهادة بهذا الرقم.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($cert['status'] === 'revoked') {
    echo json_encode([
        'success' => true,
        'valid'   => false,
        'status'  => 'revoked',
        'message' => 'هذه الشهادة مسحوبة.',
        'certificate_number' => $cert['certificate_number'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'success'            => true,
    'valid'              => true,
    'status'             => 'issued',
    'certificate_number' => $cert['certificate_number'],
    'student_name'       => $cert['student_name'],
    'course_title_ar'    => $cert['course_title'],
    'course_title_en'    => $cert['course_title_en'],
    'issued_at'          => $cert['issued_at'],
    'issuer_name'        => $cert['issuer_name'],
    'message'            => 'الشهادة صحيحة ومعتمدة.',
], JSON_UNESCAPED_UNICODE);
