<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/admin_guard.php';

$action = (string)($_GET['action'] ?? 'export_json');

if ($action === 'export_json') {
    // Export all major non-sensitive tables to structured JSON
    $tables = [
        'platform_settings', 'users', 'courses', 'course_sections', 'lessons', 
        'books', 'orders', 'order_items', 'invoices', 'book_access', 'enrollments', 
        'reviews', 'live_sessions', 'announcements', 'support_tickets', 'support_messages'
    ];

    $dump = [
        'platform' => 'Edu Nursing – Medical Education Academy',
        'exported_at' => date('Y-m-d H:i:s'),
        'version' => '2.0',
        'tables' => []
    ];

    foreach ($tables as $t) {
        try {
            $stmt = $pdo->query("SELECT * FROM `$t`");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Strip sensitive password hashes from export
            if ($t === 'users') {
                foreach ($rows as &$u) {
                    unset($u['password_hash']);
                }
                unset($u);
            }
            $dump['tables'][$t] = $rows;
        } catch (Throwable $e) {
            // Table might not exist or be empty
            $dump['tables'][$t] = [];
        }
    }

    $filename = 'edu_nursing_backup_' . date('Y_m_d_His') . '.json';
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    admin_json(200, $dump);
}

if ($action === 'export_csv') {
    $table = (string)($_GET['table'] ?? 'students');
    $allowed = ['students', 'courses', 'books', 'orders', 'payments'];

    if (!in_array($table, $allowed, true)) {
        admin_json(400, ['success' => false, 'message' => 'الجدول غير صالح للتصدير.']);
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $table . '_' . date('Ymd_His') . '.csv"');

    $out = fopen('php://output', 'w');
    // UTF-8 BOM for Excel
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));

    if ($table === 'students') {
        fputcsv($out, ['ID', 'الاسم الكامل', 'البريد الإلكتروني', 'الهاتف', 'الحالة', 'تاريخ التسجيل']);
        $rows = $pdo->query("SELECT id, full_name, email, phone, status, created_at FROM users WHERE role = 'student' ORDER BY id DESC")->fetchAll(PDO::FETCH_NUM);
        foreach ($rows as $r) fputcsv($out, $r);
    } elseif ($table === 'courses') {
        fputcsv($out, ['ID', 'اسم الكورس', 'السعر', 'المستوى', 'الحالة', 'تاريخ الإنشاء']);
        $rows = $pdo->query("SELECT id, title, price, level, status, created_at FROM courses ORDER BY id DESC")->fetchAll(PDO::FETCH_NUM);
        foreach ($rows as $r) fputcsv($out, $r);
    } elseif ($table === 'books') {
        fputcsv($out, ['ID', 'اسم الكتاب', 'المؤلف', 'النوع', 'السعر', 'المخزون', 'الحالة']);
        $rows = $pdo->query("SELECT id, title_ar, author, type, price, stock, status FROM books ORDER BY id DESC")->fetchAll(PDO::FETCH_NUM);
        foreach ($rows as $r) fputcsv($out, $r);
    } elseif ($table === 'orders') {
        fputcsv($out, ['رقم الطلب', 'معرف الطالب', 'الإجمالي', 'العملة', 'طريقة الدفع', 'الحالة', 'المرجع', 'التاريخ']);
        $rows = $pdo->query("SELECT order_number, student_id, total_amount, currency, payment_method, status, transaction_reference, created_at FROM orders ORDER BY id DESC")->fetchAll(PDO::FETCH_NUM);
        foreach ($rows as $r) fputcsv($out, $r);
    }

    fclose($out);
    exit;
}

if ($action === 'restore_json') {
    if (!isset($_FILES['backup_file']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
        admin_json(400, ['success' => false, 'message' => 'يرجى اختيار ملف النسخة الاحتياطية.']);
    }

    $raw = file_get_contents($_FILES['backup_file']['tmp_name']);
    $data = json_decode($raw, true);

    if (!is_array($data) || empty($data['tables']) || !is_array($data['tables'])) {
        admin_json(400, ['success' => false, 'message' => 'صيغة ملف النسخة الاحتياطية غير صالحة.']);
    }

    try {
        $pdo->beginTransaction();

        $allowedTables = ['platform_settings', 'books', 'courses', 'policy_pages', 'homepage_sections'];
        $restoredCounts = [];

        foreach ($data['tables'] as $tbl => $rows) {
            if (!in_array($tbl, $allowedTables, true) || !is_array($rows)) continue;

            $count = 0;
            foreach ($rows as $row) {
                if (!is_array($row) || empty($row)) continue;
                $keys = array_keys($row);
                // JSON object keys become SQL identifiers below: accept only plain identifier names.
                if (array_filter($keys, static fn($key): bool => !is_string($key) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $key))) continue;
                $cols = implode('`, `', $keys);
                $placeholders = implode(', ', array_fill(0, count($keys), '?'));
                $updates = implode(', ', array_map(fn($k) => "`$k` = VALUES(`$k`)", $keys));

                $stmt = $pdo->prepare("INSERT INTO `$tbl` (`$cols`) VALUES ($placeholders) ON DUPLICATE KEY UPDATE $updates");
                $stmt->execute(array_values($row));
                $count++;
            }
            $restoredCounts[$tbl] = $count;
        }

        $pdo->commit();

        admin_json(200, [
            'success' => true,
            'message' => 'تم التحقق من النسخة واستعادة البيانات بأمان.',
            'restored' => $restoredCounts
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[Restore error] ' . $e->getMessage());
        admin_json(500, ['success' => false, 'message' => 'حدث خطأ أثناء استعادة النسخة الاحتياطية.']);
    }
}

admin_json(400, ['success' => false, 'message' => 'إجراء غير مدعوم.']);
