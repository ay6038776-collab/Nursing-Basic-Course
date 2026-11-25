<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=30');

require_once __DIR__ . '/../config/database.php';

try {
    $sql = "
        SELECT u.id, u.full_name, u.email, u.phone, u.avatar, u.bio AS user_bio,
               d.specialization, d.experience_years, d.workplace, d.experience, d.bio AS detail_bio,
               (SELECT COUNT(*) FROM courses c WHERE c.instructor_id = u.id AND c.status = 'published') AS courses_count,
               (SELECT COUNT(*) FROM enrollments e JOIN courses c ON e.course_id = c.id WHERE c.instructor_id = u.id) AS students_count,
               (SELECT COALESCE(ROUND(AVG(r.rating), 1), 5.0) FROM reviews r JOIN courses c ON r.item_id = c.id WHERE r.item_type = 'course' AND c.instructor_id = u.id AND r.status = 'approved') AS rating
        FROM users u
        LEFT JOIN instructor_details d ON u.id = d.user_id
        WHERE u.status = 'active'
          AND (
              u.role = 'instructor'
              OR u.id IN (SELECT DISTINCT instructor_id FROM courses WHERE status = 'published')
          )
        ORDER BY (u.id = 10) DESC, courses_count DESC, u.id ASC
    ";
    $stmt = $pdo->query($sql);
    $instructors = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($instructors as &$ins) {
        $ins['id'] = (int)$ins['id'];
        $ins['courses_count'] = (int)$ins['courses_count'];
        $ins['students_count'] = (int)$ins['students_count'];
        $ins['rating'] = (float)$ins['rating'];
        $ins['bio'] = !empty($ins['detail_bio']) ? $ins['detail_bio'] : (!empty($ins['user_bio']) ? $ins['user_bio'] : 'محاضر دولي معتمد في العلوم الطبية والتمريضية');
        $ins['specialization'] = !empty($ins['specialization']) ? $ins['specialization'] : 'التمريض السريري والعلوم الطبية';
        $ins['experience_years'] = !empty($ins['experience_years']) ? (int)$ins['experience_years'] : 8;
    }
    unset($ins);

    echo json_encode([
        'success' => true,
        'count' => count($instructors),
        'instructors' => $instructors
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'تعذر جلب قائمة المحاضرين.'
    ], JSON_UNESCAPED_UNICODE);
}
