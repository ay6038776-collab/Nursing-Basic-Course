<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/student_guard.php';

try {
    $stmt = $pdo->prepare("
        SELECT b.id, b.title_ar, b.title_en, b.author, b.cover_image, b.type, b.pages_count,
               ba.granted_at, ba.status AS access_status
        FROM book_access ba
        JOIN books b ON b.id = ba.book_id
        WHERE ba.student_id = ? AND ba.status = 'active'
        ORDER BY ba.granted_at DESC
    ");
    $stmt->execute([$currentStudent['id']]);
    $books = $stmt->fetchAll(PDO::FETCH_ASSOC);

    student_json(200, [
        'success' => true,
        'books' => $books
    ]);
} catch (Throwable $e) {
    error_log('[My books error] ' . $e->getMessage());
    student_json(500, ['success' => false, 'message' => 'تعذر جلب مكتبة الكتب الرقمية.']);
}
