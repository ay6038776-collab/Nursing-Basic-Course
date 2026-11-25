<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/admin_guard.php';

$action = (string)($_GET['action'] ?? $_POST['action'] ?? 'list');

if ($action === 'list') {
    $search = trim((string)($_GET['search'] ?? ''));
    $sql = "SELECT b.*, (SELECT COUNT(*) FROM book_access ba WHERE ba.book_id = b.id) AS sales_count FROM books b WHERE 1=1";
    $params = [];
    if ($search !== '') {
        $sql .= " AND (b.title_ar LIKE ? OR b.title_en LIKE ? OR b.author LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    $sql .= " ORDER BY b.id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $books = $stmt->fetchAll(PDO::FETCH_ASSOC);

    admin_json(200, ['success' => true, 'books' => $books]);
}

if ($action === 'save') {
    $id = (int)($_POST['id'] ?? 0);
    $titleAr = trim((string)($_POST['title_ar'] ?? ''));
    $titleEn = trim((string)($_POST['title_en'] ?? ''));
    $author = trim((string)($_POST['author'] ?? 'د. خالد عبد الناصر محمد المرسي'));
    $descriptionAr = trim((string)($_POST['description_ar'] ?? ''));
    $descriptionEn = trim((string)($_POST['description_en'] ?? ''));
    $type = (string)($_POST['type'] ?? 'both');
    $price = (float)($_POST['price'] ?? 0);
    $discountPrice = !empty($_POST['discount_price']) ? (float)$_POST['discount_price'] : null;
    $stock = (int)($_POST['stock'] ?? 50);
    $pagesCount = (int)($_POST['pages_count'] ?? 150);
    $status = (string)($_POST['status'] ?? 'published');
    $isDownloadable = !empty($_POST['is_downloadable']) ? 1 : 0;

    if ($titleAr === '' || $price < 0) {
        admin_json(400, ['success' => false, 'message' => 'يرجى إدخال اسم الكتاب وسعر صحيح.']);
    }

    if (!in_array($type, ['printed', 'digital', 'both'], true)) $type = 'both';
    if (!in_array($status, ['draft', 'published', 'archived'], true)) $status = 'published';

    // Validate and store uploads using server-detected MIME types.
    $coverImage = null;
    $pdfPath = null;
    if (isset($_FILES['cover_file']) && $_FILES['cover_file']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['cover_file'];
        if (!is_uploaded_file($file['tmp_name']) || $file['size'] > 5 * 1024 * 1024) admin_json(422, ['success' => false, 'message' => 'غلاف الكتاب يجب أن يكون صورة حتى 5 ميجابايت.']);
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!isset($extensions[$mime]) || !@getimagesize($file['tmp_name'])) admin_json(422, ['success' => false, 'message' => 'صيغة الغلاف يجب أن تكون JPG أو PNG أو WEBP.']);
        $uploadDir = __DIR__ . '/../../uploads/books';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        $fileName = 'book_cover_' . bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
        if (move_uploaded_file($file['tmp_name'], $uploadDir . '/' . $fileName)) {
            $coverImage = 'uploads/books/' . $fileName;
        }
    }

    if (isset($_FILES['pdf_file']) && $_FILES['pdf_file']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['pdf_file'];
        if (!is_uploaded_file($file['tmp_name']) || $file['size'] > 35 * 1024 * 1024) admin_json(422, ['success' => false, 'message' => 'ملف الكتاب يجب ألا يتجاوز 35 ميجابايت وفق إعدادات الخادم الحالية.']);
        if ((new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) !== 'application/pdf' || strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'pdf') admin_json(422, ['success' => false, 'message' => 'ارفع ملف كتاب بصيغة PDF فقط.']);
        $uploadDir = __DIR__ . '/../../uploads/books/docs';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        $fileName = 'book_doc_' . bin2hex(random_bytes(16)) . '.pdf';
        if (move_uploaded_file($file['tmp_name'], $uploadDir . '/' . $fileName)) {
            $pdfPath = 'uploads/books/docs/' . $fileName;
        }
    }

    try {
        if ($id > 0) {
            $sql = "UPDATE books SET title_ar = ?, title_en = ?, author = ?, description_ar = ?, description_en = ?, type = ?, price = ?, discount_price = ?, stock = ?, pages_count = ?, status = ?, is_downloadable = ?";
            $params = [$titleAr, $titleEn, $author, $descriptionAr, $descriptionEn, $type, $price, $discountPrice, $stock, $pagesCount, $status, $isDownloadable];
            if ($coverImage) {
                $sql .= ", cover_image = ?";
                $params[] = $coverImage;
            }
            if ($pdfPath) {
                $sql .= ", pdf_path = ?";
                $params[] = $pdfPath;
            }
            $sql .= " WHERE id = ?";
            $params[] = $id;

            $pdo->prepare($sql)->execute($params);
            admin_json(200, ['success' => true, 'message' => 'تم تحديث بيانات الكتاب بنجاح.']);
        } else {
            $pdo->prepare("
                INSERT INTO books (title_ar, title_en, author, description_ar, description_en, cover_image, type, price, discount_price, stock, pages_count, pdf_path, status, is_downloadable, created_by, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ")->execute([$titleAr, $titleEn, $author, $descriptionAr, $descriptionEn, $coverImage, $type, $price, $discountPrice, $stock, $pagesCount, $pdfPath, $status, $isDownloadable, $currentUser['id']]);

            admin_json(201, ['success' => true, 'message' => 'تمت إضافة الكتاب الجديد بنجاح.']);
        }
    } catch (Throwable $e) {
        error_log('[Save book error] ' . $e->getMessage());
        admin_json(500, ['success' => false, 'message' => 'حدث خطأ أثناء حفظ الكتاب.']);
    }
}

if ($action === 'delete') {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = (int)($input['id'] ?? 0);
    if ($id <= 0) admin_json(400, ['success' => false, 'message' => 'معرف الكتاب غير صالح.']);

    try {
        $pdo->prepare("UPDATE books SET status = 'archived' WHERE id = ?")->execute([$id]);
        admin_json(200, ['success' => true, 'message' => 'تم أرشفة الكتاب بنجاح.']);
    } catch (Throwable $e) {
        admin_json(500, ['success' => false, 'message' => 'تعذر حذف الكتاب.']);
    }
}

admin_json(400, ['success' => false, 'message' => 'إجراء غير مدعوم.']);
