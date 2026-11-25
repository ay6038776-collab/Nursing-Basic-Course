<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=30');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/settings_helper.php';

try {
    // 1. Fetch Platform Settings
    $settings = get_platform_settings($pdo);

    // 0. Active homepage medical slider slides
    $sliderStmt = $pdo->query('SELECT id,title_ar,title_en,subtitle_ar,subtitle_en,image_path,sort_order FROM home_slider_slides WHERE is_active=1 ORDER BY sort_order ASC,id ASC');
    $homeSlider = $sliderStmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. Fetch Published Courses
    $courseSql = "
        SELECT c.id, c.title, c.title_ar, c.title_en, c.slug, c.description, c.description_en,
               c.category, c.level, c.price, c.discounted_price, c.thumbnail,
               c.duration_minutes, c.learning_outcomes, c.prerequisites, c.status,
               u.id AS instructor_id, u.full_name AS instructor_name, u.avatar AS instructor_avatar,
               d.specialization AS instructor_specialization,
               (SELECT COUNT(*) FROM lessons l WHERE l.course_id = c.id AND l.status = 'published') AS lessons_count,
               (SELECT COUNT(*) FROM enrollments e WHERE e.course_id = c.id AND e.status = 'active') AS enrolled_count,
               (SELECT ROUND(AVG(r.rating), 1) FROM reviews r WHERE r.item_type = 'course' AND r.item_id = c.id AND r.status = 'approved') AS rating,
               (SELECT COUNT(*) FROM reviews r WHERE r.item_type = 'course' AND r.item_id = c.id AND r.status = 'approved') AS reviews_count
        FROM courses c
        LEFT JOIN users u ON u.id = c.instructor_id
        LEFT JOIN instructor_details d ON d.user_id = u.id
        WHERE c.status = 'published'
        ORDER BY c.id DESC
    ";
    $coursesStmt = $pdo->query($courseSql);
    $courses = $coursesStmt->fetchAll(PDO::FETCH_ASSOC);

    // Format courses with computed fields
    foreach ($courses as &$c) {
        $c['id'] = (int)$c['id'];
        $c['price'] = (float)$c['price'];
        $c['discounted_price'] = $c['discounted_price'] !== null ? (float)$c['discounted_price'] : null;
        $c['lessons_count'] = (int)$c['lessons_count'];
        $c['enrolled_count'] = (int)$c['enrolled_count'];
        $c['rating'] = $c['rating'] !== null ? (float)$c['rating'] : 4.9;
        $c['reviews_count'] = (int)$c['reviews_count'];
        $c['has_discount'] = ($c['discounted_price'] !== null && $c['discounted_price'] > 0 && $c['discounted_price'] < $c['price']);
        $c['discount_percent'] = $c['has_discount'] ? round((($c['price'] - $c['discounted_price']) / $c['price']) * 100) : 0;
    }
    unset($c);

    // 3. Fetch Published Books
    $bookSql = "
        SELECT b.id, b.title_ar, b.title_en, b.author, b.description_ar, b.description_en,
               b.cover_image, b.preview_images_json, b.type, b.price, b.discount_price,
               b.stock, b.pages_count, b.pdf_path, b.preview_pdf_path, b.is_downloadable, b.status,
               (SELECT ROUND(AVG(r.rating), 1) FROM reviews r WHERE r.item_type = 'book' AND r.item_id = b.id AND r.status = 'approved') AS rating,
               (SELECT COUNT(*) FROM reviews r WHERE r.item_type = 'book' AND r.item_id = b.id AND r.status = 'approved') AS reviews_count
        FROM books b
        WHERE b.status = 'published'
        ORDER BY b.id ASC
    ";
    $booksStmt = $pdo->query($bookSql);
    $books = $booksStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($books as &$b) {
        $b['id'] = (int)$b['id'];
        $b['price'] = (float)$b['price'];
        $b['discount_price'] = $b['discount_price'] !== null ? (float)$b['discount_price'] : null;
        $b['pages_count'] = (int)$b['pages_count'];
        $b['stock'] = (int)$b['stock'];
        $b['rating'] = $b['rating'] !== null ? (float)$b['rating'] : 5.0;
        $b['reviews_count'] = (int)$b['reviews_count'];
        $b['has_discount'] = ($b['discount_price'] !== null && $b['discount_price'] > 0 && $b['discount_price'] < $b['price']);
        $b['discount_percent'] = $b['has_discount'] ? round((($b['price'] - $b['discount_price']) / $b['price']) * 100) : 0;
    }
    unset($b);

    // 4. Fetch Instructors
    $instSql = "
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
    $instStmt = $pdo->query($instSql);
    $instructors = $instStmt->fetchAll(PDO::FETCH_ASSOC);

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

    // 5. Build Offers & Discounts
    $discountedCourses = array_values(array_filter($courses, fn($c) => !empty($c['has_discount'])));
    $discountedBooks = array_values(array_filter($books, fn($b) => !empty($b['has_discount'])));

    // Active Coupons
    $couponSql = "
        SELECT code, discount_type, discount_value, max_redemptions, redeemed_count, expires_at
        FROM course_coupons
        WHERE is_active = 1
          AND (expires_at IS NULL OR expires_at > NOW())
          AND (max_redemptions IS NULL OR redeemed_count < max_redemptions)
        ORDER BY discount_value DESC
    ";
    $coupons = $pdo->query($couponSql)->fetchAll(PDO::FETCH_ASSOC);

    // 6. Platform Real Stats
    $totalStudents = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student'")->fetchColumn();
    $totalEnrollments = (int)$pdo->query("SELECT COUNT(*) FROM enrollments WHERE status = 'active'")->fetchColumn();

    $stats = [
        'students' => $totalStudents,
        'enrollments' => $totalEnrollments,
        'courses_count' => count($courses),
        'books_count' => count($books),
        'instructors_count' => count($instructors),
    ];

    echo json_encode([
        'success' => true,
        'timestamp' => time(),
        'settings' => $settings,
        'home_slider' => $homeSlider,
        'courses' => $courses,
        'books' => $books,
        'instructors' => $instructors,
        'offers' => [
            'courses' => $discountedCourses,
            'books' => $discountedBooks,
            'coupons' => $coupons,
            'total_offers' => count($discountedCourses) + count($discountedBooks)
        ],
        'stats' => $stats
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'تعذر جلب بيانات الصفحة الرئيسية: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
