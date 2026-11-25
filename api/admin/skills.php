<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/admin_guard.php';

$action = (string)($_GET['action'] ?? 'list_categories');

/* ─── LIST CATEGORIES ─── */
if ($action === 'list_categories') {
    $stmt = $pdo->query("SELECT sc.*, COUNT(s.id) AS skill_count FROM nursing_skill_categories sc LEFT JOIN nursing_skills s ON s.category_id=sc.id GROUP BY sc.id ORDER BY sc.sort_order, sc.id");
    admin_json(200, ['success' => true, 'categories' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

/* ─── CREATE CATEGORY ─── */
if ($action === 'create_category') {
    $input   = json_decode(file_get_contents('php://input'), true) ?? [];
    $nameAr  = trim((string)($input['name_ar'] ?? ''));
    $nameEn  = trim((string)($input['name_en'] ?? ''));
    $icon    = trim((string)($input['icon'] ?? '🩺'));
    $descAr  = trim((string)($input['description_ar'] ?? ''));

    if (!$nameAr) admin_json(400, ['success' => false, 'message' => 'اسم التصنيف مطلوب.']);

    $pdo->prepare("INSERT INTO nursing_skill_categories (name_ar, name_en, icon, description_ar, sort_order, created_at)
        VALUES (?,?,?,?,(SELECT COALESCE(MAX(sort_order),0)+1 FROM nursing_skill_categories sc2),NOW())")
        ->execute([$nameAr, $nameEn, $icon, $descAr]);

    admin_json(200, ['success' => true, 'category_id' => (int)$pdo->lastInsertId()]);
}

/* ─── LIST SKILLS ─── */
if ($action === 'list_skills') {
    $catId = (int)($_GET['category_id'] ?? 0);
    $where = ['1=1']; $p = [];
    if ($catId > 0) { $where[] = 's.category_id=?'; $p[] = $catId; }

    $stmt = $pdo->prepare("
        SELECT s.*, sc.name_ar AS category_name
        FROM nursing_skills s
        LEFT JOIN nursing_skill_categories sc ON sc.id=s.category_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY s.sort_order, s.id
    ");
    $stmt->execute($p);
    admin_json(200, ['success' => true, 'skills' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

/* ─── GET SKILL DETAIL ─── */
if ($action === 'get_skill') {
    $skillId = (int)($_GET['skill_id'] ?? 0);
    if ($skillId <= 0) admin_json(400, ['success' => false, 'message' => 'معرف المهارة مطلوب.']);

    $skill = $pdo->prepare('SELECT * FROM nursing_skills WHERE id=?');
    $skill->execute([$skillId]);
    $s = $skill->fetch();
    if (!$s) admin_json(404, ['success' => false, 'message' => 'المهارة غير موجودة.']);

    // Parse JSON fields
    foreach (['objectives', 'equipment', 'steps', 'safety_precautions', 'common_mistakes', 'review_questions'] as $f) {
        if (!empty($s[$f])) $s[$f] = json_decode($s[$f], true);
    }

    admin_json(200, ['success' => true, 'skill' => $s]);
}

/* ─── CREATE SKILL ─── */
if ($action === 'create_skill') {
    $input   = json_decode(file_get_contents('php://input'), true) ?? [];
    $catId   = (int)($input['category_id'] ?? 0);
    $titleAr = trim((string)($input['title_ar'] ?? ''));
    $titleEn = trim((string)($input['title_en'] ?? ''));

    if (!$titleAr || $catId <= 0) admin_json(400, ['success' => false, 'message' => 'اسم المهارة والتصنيف مطلوبان.']);

    $pdo->prepare("INSERT INTO nursing_skills
        (category_id, title_ar, title_en, description_ar, objectives, equipment,
         steps, safety_precautions, common_mistakes, review_questions,
         difficulty_level, estimated_minutes, requires_supervision, is_published, sort_order, created_at, updated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,
            (SELECT COALESCE(MAX(sort_order),0)+1 FROM nursing_skills ns2 WHERE ns2.category_id=?),
            NOW(),NOW())")
        ->execute([
            $catId, $titleAr, $titleEn,
            trim((string)($input['description_ar'] ?? '')),
            json_encode($input['objectives'] ?? [], JSON_UNESCAPED_UNICODE),
            json_encode($input['equipment']  ?? [], JSON_UNESCAPED_UNICODE),
            json_encode($input['steps']      ?? [], JSON_UNESCAPED_UNICODE),
            json_encode($input['safety_precautions'] ?? [], JSON_UNESCAPED_UNICODE),
            json_encode($input['common_mistakes']    ?? [], JSON_UNESCAPED_UNICODE),
            json_encode($input['review_questions']   ?? [], JSON_UNESCAPED_UNICODE),
            trim((string)($input['difficulty_level'] ?? 'beginner')),
            (int)($input['estimated_minutes'] ?? 30),
            (int)(bool)($input['requires_supervision'] ?? true),
            (int)(bool)($input['is_published'] ?? false),
            $catId
        ]);

    admin_json(200, ['success' => true, 'skill_id' => (int)$pdo->lastInsertId(), 'message' => 'تم إنشاء المهارة.']);
}

/* ─── UPDATE SKILL ─── */
if ($action === 'update_skill') {
    $input   = json_decode(file_get_contents('php://input'), true) ?? [];
    $skillId = (int)($input['skill_id'] ?? 0);
    if ($skillId <= 0) admin_json(400, ['success' => false, 'message' => 'معرف المهارة مطلوب.']);

    $pdo->prepare("UPDATE nursing_skills SET
        category_id=?, title_ar=?, title_en=?, description_ar=?,
        objectives=?, equipment=?, steps=?, safety_precautions=?,
        common_mistakes=?, review_questions=?, difficulty_level=?,
        estimated_minutes=?, requires_supervision=?, is_published=?, updated_at=NOW()
        WHERE id=?")
        ->execute([
            (int)($input['category_id'] ?? 0),
            trim((string)($input['title_ar'] ?? '')),
            trim((string)($input['title_en'] ?? '')),
            trim((string)($input['description_ar'] ?? '')),
            json_encode($input['objectives'] ?? [], JSON_UNESCAPED_UNICODE),
            json_encode($input['equipment']  ?? [], JSON_UNESCAPED_UNICODE),
            json_encode($input['steps']      ?? [], JSON_UNESCAPED_UNICODE),
            json_encode($input['safety_precautions'] ?? [], JSON_UNESCAPED_UNICODE),
            json_encode($input['common_mistakes']    ?? [], JSON_UNESCAPED_UNICODE),
            json_encode($input['review_questions']   ?? [], JSON_UNESCAPED_UNICODE),
            trim((string)($input['difficulty_level'] ?? 'beginner')),
            (int)($input['estimated_minutes'] ?? 30),
            (int)(bool)($input['requires_supervision'] ?? true),
            (int)(bool)($input['is_published'] ?? false),
            $skillId
        ]);

    admin_json(200, ['success' => true, 'message' => 'تم تحديث المهارة.']);
}

/* ─── DELETE SKILL ─── */
if ($action === 'delete_skill') {
    $input   = json_decode(file_get_contents('php://input'), true) ?? [];
    $skillId = (int)($input['skill_id'] ?? 0);
    if ($skillId <= 0) admin_json(400, ['success' => false, 'message' => 'معرف المهارة مطلوب.']);

    $pdo->prepare('DELETE FROM nursing_skills WHERE id=?')->execute([$skillId]);
    admin_json(200, ['success' => true, 'message' => 'تم حذف المهارة.']);
}

admin_json(400, ['success' => false, 'message' => 'إجراء غير معروف.']);
