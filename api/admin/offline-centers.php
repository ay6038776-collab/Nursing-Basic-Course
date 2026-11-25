<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/admin_guard.php';

function oc_fail(int $status, string $message): never
{
    admin_json($status, ['success' => false, 'message' => $message]);
}

function oc_require_method(string $method): void
{
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== $method) {
        header('Allow: ' . $method);
        oc_fail(405, 'طريقة الطلب غير مدعومة.');
    }
}

function oc_text_length(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function oc_upload_dir(): string
{
    return dirname(__DIR__, 2) . '/uploads/offline-centers';
}

function oc_prepare_upload_dir(): string
{
    $dir = oc_upload_dir();
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        oc_fail(500, 'تعذر تجهيز مساحة رفع ملفات السناتر.');
    }

    $htaccess = $dir . '/.htaccess';
    if (!is_file($htaccess)) {
        @file_put_contents($htaccess, "Options -Indexes\n<FilesMatch \"\\.(php|phtml|phar|pl|py|cgi|sh)$\">\n  Require all denied\n</FilesMatch>\n");
    }
    $index = $dir . '/index.html';
    if (!is_file($index)) {
        @file_put_contents($index, '');
    }
    return $dir;
}

function oc_mime_extension_map(): array
{
    return [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
        'application/pdf' => ['pdf'],
        'application/msword' => ['doc'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
        'application/vnd.ms-excel' => ['xls'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx'],
        'application/vnd.ms-powerpoint' => ['ppt'],
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => ['pptx'],
        'text/plain' => ['txt'],
        'video/mp4' => ['mp4'],
        'video/webm' => ['webm'],
        'video/quicktime' => ['mov'],
        'video/x-msvideo' => ['avi'],
    ];
}

function oc_validate_upload(array $file, bool $cover = false): array
{
    if (!isset($file['error']) || (int)$file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string)($file['tmp_name'] ?? ''))) {
        $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        $message = $error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE
            ? 'حجم الملف أكبر من الحد المسموح به على الخادم.'
            : 'تعذر استلام الملف المرفوع.';
        oc_fail(400, $message);
    }

    $size = (int)($file['size'] ?? 0);
    $limit = $cover ? 5 * 1024 * 1024 : 100 * 1024 * 1024;
    if ($size <= 0 || $size > $limit) {
        oc_fail(400, $cover ? 'حجم صورة السنتر يجب ألا يتجاوز 5 ميجابايت.' : 'حجم كل ملف يجب ألا يتجاوز 100 ميجابايت.');
    }

    $originalName = basename(str_replace('\\', '/', (string)($file['name'] ?? 'file')));
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($extension === '' || !preg_match('/^[a-z0-9]{1,8}$/', $extension)) {
        oc_fail(400, 'امتداد الملف غير صالح.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = strtolower((string)$finfo->file((string)$file['tmp_name']));
    $allowed = oc_mime_extension_map();
    if (!isset($allowed[$mime]) || !in_array($extension, $allowed[$mime], true)) {
        oc_fail(400, 'نوع الملف غير مدعوم أو لا يطابق امتداده.');
    }

    if ($cover && !str_starts_with($mime, 'image/')) {
        oc_fail(400, 'صورة الغلاف يجب أن تكون JPG أو PNG أو WEBP.');
    }
    if (!$cover && !str_starts_with($mime, 'video/') && !in_array($mime, [
        'application/pdf', 'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'text/plain',
    ], true)) {
        oc_fail(400, 'ارفع ملفًا مستنديًا أو فيديو بصيغة مدعومة.');
    }

    return [
        'original_name' => substr($originalName, 0, 255),
        'mime_type' => $mime,
        'size_bytes' => $size,
        'extension' => $extension,
    ];
}

function oc_store_upload(array $file, bool $cover = false): array
{
    $validated = oc_validate_upload($file, $cover);
    $dir = oc_prepare_upload_dir();
    $fileName = bin2hex(random_bytes(20)) . '.' . $validated['extension'];
    $destination = $dir . '/' . $fileName;
    if (!move_uploaded_file((string)$file['tmp_name'], $destination)) {
        oc_fail(500, 'تعذر حفظ الملف المرفوع.');
    }

    return [
        'path' => 'uploads/offline-centers/' . $fileName,
        'absolute' => $destination,
        'original_name' => $validated['original_name'],
        'mime_type' => $validated['mime_type'],
        'size_bytes' => $validated['size_bytes'],
        'extension' => $validated['extension'],
    ];
}

function oc_remove_upload(?string $path): void
{
    if (!$path || !preg_match('#^uploads/offline-centers/[a-f0-9]{40}\.[a-z0-9]{1,8}$#', $path)) {
        return;
    }
    $file = oc_upload_dir() . '/' . basename($path);
    if (is_file($file)) {
        @unlink($file);
    }
}

function oc_center(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM offline_centers WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $center = $stmt->fetch(PDO::FETCH_ASSOC);
    return $center ?: null;
}

function oc_valid_date(string $value): bool
{
    $date = DateTime::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

$action = (string)($_GET['action'] ?? 'list');

if ($action === 'list') {
    oc_require_method('GET');
    $search = trim((string)($_GET['search'] ?? ''));
    $sql = "SELECT c.*,
        (SELECT COUNT(*) FROM offline_center_media m WHERE m.center_id = c.id) AS media_count,
        (SELECT COUNT(*) FROM offline_center_slots s WHERE s.center_id = c.id AND s.status = 'open' AND s.booking_date >= CURDATE()) AS open_slots
        FROM offline_centers c";
    $params = [];
    if ($search !== '') {
        $sql .= ' WHERE c.name LIKE ? OR c.address LIKE ? OR c.phone LIKE ?';
        $needle = '%' . $search . '%';
        $params = [$needle, $needle, $needle];
    }
    $sql .= ' ORDER BY c.updated_at DESC, c.id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    admin_json(200, ['success' => true, 'centers' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

if ($action === 'detail') {
    oc_require_method('GET');
    $id = (int)($_GET['id'] ?? 0);
    $center = $id > 0 ? oc_center($pdo, $id) : null;
    if (!$center) oc_fail(404, 'السنتر غير موجود.');

    $mediaStmt = $pdo->prepare('SELECT * FROM offline_center_media WHERE center_id = ? ORDER BY id DESC');
    $mediaStmt->execute([$id]);
    $slotStmt = $pdo->prepare('SELECT * FROM offline_center_slots WHERE center_id = ? ORDER BY booking_date ASC, start_time ASC, id ASC');
    $slotStmt->execute([$id]);
    admin_json(200, [
        'success' => true,
        'center' => $center,
        'media' => $mediaStmt->fetchAll(PDO::FETCH_ASSOC),
        'slots' => $slotStmt->fetchAll(PDO::FETCH_ASSOC),
    ]);
}

if ($action === 'save') {
    oc_require_method('POST');
    $id = (int)($_POST['id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $address = trim((string)($_POST['address'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));
    $whatsapp = trim((string)($_POST['whatsapp'] ?? ''));
    $mapUrl = trim((string)($_POST['map_url'] ?? ''));
    $status = (string)($_POST['status'] ?? 'active');

    if ($name === '' || oc_text_length($name) > 190) oc_fail(400, 'اسم السنتر مطلوب ويجب ألا يتجاوز 190 حرفًا.');
    if (oc_text_length($address) > 500 || oc_text_length($phone) > 40 || oc_text_length($whatsapp) > 40) {
        oc_fail(400, 'راجع طول العنوان أو رقم الهاتف.');
    }
    if (oc_text_length($description) > 20000 || oc_text_length($mapUrl) > 1000) oc_fail(400, 'أحد الحقول أطول من المسموح.');
    if ($mapUrl !== '' && (!filter_var($mapUrl, FILTER_VALIDATE_URL) || !in_array(strtolower((string)parse_url($mapUrl, PHP_URL_SCHEME)), ['http', 'https'], true))) {
        oc_fail(400, 'رابط الموقع يجب أن يبدأ بـ HTTP أو HTTPS.');
    }
    if (!in_array($status, ['active', 'inactive'], true)) $status = 'active';
    if ($id > 0 && !oc_center($pdo, $id)) oc_fail(404, 'السنتر المراد تعديله غير موجود.');

    $newCover = null;
    if (isset($_FILES['cover_file']) && (int)($_FILES['cover_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $newCover = oc_store_upload($_FILES['cover_file'], true);
    }
    $oldCover = $id > 0 ? (string)(oc_center($pdo, $id)['cover_image'] ?? '') : '';

    try {
        if ($id > 0) {
            $sql = 'UPDATE offline_centers SET name = ?, description = ?, address = ?, phone = ?, whatsapp = ?, map_url = ?, status = ?';
            $params = [$name, $description !== '' ? $description : null, $address !== '' ? $address : null, $phone !== '' ? $phone : null, $whatsapp !== '' ? $whatsapp : null, $mapUrl !== '' ? $mapUrl : null, $status];
            if ($newCover) {
                $sql .= ', cover_image = ?';
                $params[] = $newCover['path'];
            }
            $sql .= ' WHERE id = ?';
            $params[] = $id;
            $pdo->prepare($sql)->execute($params);
        } else {
            $pdo->prepare('INSERT INTO offline_centers (name, description, address, phone, whatsapp, map_url, cover_image, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$name, $description !== '' ? $description : null, $address !== '' ? $address : null, $phone !== '' ? $phone : null, $whatsapp !== '' ? $whatsapp : null, $mapUrl !== '' ? $mapUrl : null, $newCover['path'] ?? null, $status, (int)($currentUser['id'] ?? 0) ?: null]);
            $id = (int)$pdo->lastInsertId();
        }
    } catch (Throwable $e) {
        if ($newCover) @unlink($newCover['absolute']);
        error_log('[Offline center save] ' . $e->getMessage());
        oc_fail(500, 'تعذر حفظ بيانات السنتر. تأكد من تثبيت تحديث قاعدة البيانات.');
    }

    if ($newCover && $oldCover !== '') oc_remove_upload($oldCover);
    admin_json(200, ['success' => true, 'id' => $id, 'message' => 'تم حفظ بيانات السنتر.']);
}

if ($action === 'delete') {
    oc_require_method('POST');
    $input = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $id = (int)($input['id'] ?? 0);
    $center = $id > 0 ? oc_center($pdo, $id) : null;
    if (!$center) oc_fail(404, 'السنتر غير موجود.');

    $files = [(string)($center['cover_image'] ?? '')];
    $mediaStmt = $pdo->prepare('SELECT file_path FROM offline_center_media WHERE center_id = ?');
    $mediaStmt->execute([$id]);
    foreach ($mediaStmt->fetchAll(PDO::FETCH_COLUMN) as $path) $files[] = (string)$path;
    try {
        $pdo->prepare('DELETE FROM offline_centers WHERE id = ?')->execute([$id]);
    } catch (Throwable $e) {
        error_log('[Offline center delete] ' . $e->getMessage());
        oc_fail(500, 'تعذر حذف السنتر.');
    }
    foreach ($files as $path) oc_remove_upload($path);
    admin_json(200, ['success' => true, 'message' => 'تم حذف السنتر ومواعيده وملفاته.']);
}

if ($action === 'upload_media') {
    oc_require_method('POST');
    $centerId = (int)($_POST['center_id'] ?? 0);
    if ($centerId <= 0 || !oc_center($pdo, $centerId)) oc_fail(404, 'السنتر غير موجود.');
    $files = $_FILES['files'] ?? null;
    if (!$files || !isset($files['name']) || !is_array($files['name'])) oc_fail(400, 'اختر ملفًا واحدًا على الأقل.');
    $count = count($files['name']);
    if ($count < 1 || $count > 10) oc_fail(400, 'يمكن رفع 1 إلى 10 ملفات في المرة الواحدة.');

    // Validate every upload before moving any file so one invalid file cannot
    // leave earlier uploads behind without matching database records.
    $normalizedFiles = [];
    for ($i = 0; $i < $count; $i++) {
        $normalizedFiles[] = [
            'name' => $files['name'][$i] ?? '',
            'type' => $files['type'][$i] ?? '',
            'tmp_name' => $files['tmp_name'][$i] ?? '',
            'error' => $files['error'][$i] ?? UPLOAD_ERR_NO_FILE,
            'size' => $files['size'][$i] ?? 0,
        ];
        oc_validate_upload($normalizedFiles[$i]);
    }

    $stored = [];
    try {
        $pdo->beginTransaction();
        foreach ($normalizedFiles as $file) {
            $saved = oc_store_upload($file, false);
            $stored[] = $saved;
            $mediaType = str_starts_with($saved['mime_type'], 'video/') ? 'video' : 'file';
            $title = pathinfo($saved['original_name'], PATHINFO_FILENAME);
            $pdo->prepare('INSERT INTO offline_center_media (center_id, media_type, title, original_name, file_path, mime_type, size_bytes) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$centerId, $mediaType, substr($title, 0, 255) ?: 'ملف', $saved['original_name'], $saved['path'], $saved['mime_type'], $saved['size_bytes']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        foreach ($stored as $file) @unlink($file['absolute']);
        if ($e instanceof PDOException) {
            error_log('[Offline center media upload] ' . $e->getMessage());
            oc_fail(500, 'تعذر حفظ ملفات السنتر.');
        }
        throw $e;
    }
    admin_json(201, ['success' => true, 'message' => 'تم رفع الملفات والفيديوهات.']);
}

if ($action === 'delete_media') {
    oc_require_method('POST');
    $input = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $id = (int)($input['id'] ?? 0);
    $stmt = $pdo->prepare('SELECT file_path FROM offline_center_media WHERE id = ?');
    $stmt->execute([$id]);
    $path = $stmt->fetchColumn();
    if (!$path) oc_fail(404, 'الملف غير موجود.');
    $pdo->prepare('DELETE FROM offline_center_media WHERE id = ?')->execute([$id]);
    oc_remove_upload((string)$path);
    admin_json(200, ['success' => true, 'message' => 'تم حذف الملف.']);
}

if ($action === 'save_slot') {
    oc_require_method('POST');
    $input = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $id = (int)($input['id'] ?? 0);
    $centerId = (int)($input['center_id'] ?? 0);
    $title = trim((string)($input['title'] ?? ''));
    $date = trim((string)($input['booking_date'] ?? ''));
    $start = trim((string)($input['start_time'] ?? ''));
    $end = trim((string)($input['end_time'] ?? ''));
    $capacity = filter_var($input['capacity'] ?? 1, FILTER_VALIDATE_INT);
    $status = (string)($input['status'] ?? 'open');
    $notes = trim((string)($input['notes'] ?? ''));

    if ($centerId <= 0 || !oc_center($pdo, $centerId)) oc_fail(404, 'السنتر غير موجود.');
    if ($title === '' || oc_text_length($title) > 190) oc_fail(400, 'عنوان موعد الحجز مطلوب.');
    if (!oc_valid_date($date)) oc_fail(400, 'تاريخ الحجز غير صالح.');
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $start) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $end)) {
        oc_fail(400, 'راجع وقت البداية والنهاية، ويجب أن تكون النهاية بعد البداية.');
    }
    $start = strlen($start) === 5 ? $start . ':00' : $start;
    $end = strlen($end) === 5 ? $end . ':00' : $end;
    if ($start >= $end) oc_fail(400, 'راجع وقت البداية والنهاية، ويجب أن تكون النهاية بعد البداية.');
    if ($capacity === false || $capacity < 1 || $capacity > 5000) oc_fail(400, 'السعة يجب أن تكون من 1 إلى 5000.');
    if (!in_array($status, ['open', 'closed'], true)) $status = 'open';
    if (oc_text_length($notes) > 1000) oc_fail(400, 'ملاحظات الموعد طويلة جدًا.');

    if ($id > 0) {
        $stmt = $pdo->prepare('UPDATE offline_center_slots SET title = ?, booking_date = ?, start_time = ?, end_time = ?, capacity = ?, status = ?, notes = ? WHERE id = ? AND center_id = ?');
        $stmt->execute([$title, $date, $start, $end, $capacity, $status, $notes !== '' ? $notes : null, $id, $centerId]);
        if ($stmt->rowCount() === 0) {
            $check = $pdo->prepare('SELECT id FROM offline_center_slots WHERE id = ? AND center_id = ?');
            $check->execute([$id, $centerId]);
            if (!$check->fetchColumn()) oc_fail(404, 'موعد الحجز غير موجود.');
        }
    } else {
        $pdo->prepare('INSERT INTO offline_center_slots (center_id, title, booking_date, start_time, end_time, capacity, status, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$centerId, $title, $date, $start, $end, $capacity, $status, $notes !== '' ? $notes : null]);
        $id = (int)$pdo->lastInsertId();
    }
    admin_json(200, ['success' => true, 'id' => $id, 'message' => 'تم حفظ موعد الحجز.']);
}

if ($action === 'delete_slot') {
    oc_require_method('POST');
    $input = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $id = (int)($input['id'] ?? 0);
    if ($id <= 0) oc_fail(400, 'رقم موعد الحجز غير صالح.');
    $pdo->prepare('DELETE FROM offline_center_slots WHERE id = ?')->execute([$id]);
    admin_json(200, ['success' => true, 'message' => 'تم حذف موعد الحجز.']);
}

oc_fail(400, 'الإجراء غير مدعوم.');
