<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/student_guard.php';

// Student Skills Library API — fixed for correct student_json(int, array) signature
$action = $_GET['action'] ?? '';

switch ($action) {

    case 'categories':
        $catCols = $pdo->query("SHOW COLUMNS FROM nursing_skill_categories")->fetchAll(PDO::FETCH_COLUMN);
        $orderCol = in_array('sort_order', $catCols) ? 'c.sort_order' : (in_array('order_index', $catCols) ? 'c.order_index' : 'c.id');
        $descAr = in_array('description_ar', $catCols) ? 'c.description_ar' : "'' AS description_ar";
        $descEn = in_array('description_en', $catCols) ? 'c.description_en' : "'' AS description_en";
        $colorCol = in_array('color', $catCols) ? 'c.color' : "'#0f766e' AS color";
        $iconCol = in_array('icon', $catCols) ? 'c.icon' : "'🩺' AS icon";

        $skillCols = $pdo->query("SHOW COLUMNS FROM nursing_skills")->fetchAll(PDO::FETCH_COLUMN);
        $pubCond = in_array('is_published', $skillCols) ? "s.is_published = 1" : "1=1";

        $stmt = $pdo->query("SELECT
            c.id, c.name_ar, c.name_en, {$descAr}, {$descEn},
            {$iconCol}, {$colorCol},
            COUNT(s.id) AS skills_count
            FROM nursing_skill_categories c
            LEFT JOIN nursing_skills s ON s.category_id = c.id AND {$pubCond}
            GROUP BY c.id
            ORDER BY {$orderCol} ASC, c.name_ar ASC");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        student_json(200, ['success' => true, 'categories' => $rows]);
        break;

    case 'list':
        $categoryId = (int)($_GET['category_id'] ?? 0);
        $search     = trim($_GET['search'] ?? '');
        $page       = max(1, (int)($_GET['page'] ?? 1));
        $limit      = min(50, max(10, (int)($_GET['limit'] ?? 20)));
        $offset     = ($page - 1) * $limit;

        $catCols = $pdo->query("SHOW COLUMNS FROM nursing_skill_categories")->fetchAll(PDO::FETCH_COLUMN);
        $catIconCol = in_array('icon', $catCols) ? 'c.icon' : "'🩺' AS category_icon";

        $skillCols = $pdo->query("SHOW COLUMNS FROM nursing_skills")->fetchAll(PDO::FETCH_COLUMN);
        $pubCond = in_array('is_published', $skillCols) ? "s.is_published = 1" : "1=1";

        $where  = [$pubCond];
        $params = [];

        if ($categoryId) {
            $where[] = "s.category_id = :cat";
            $params[':cat'] = $categoryId;
        }
        if ($search) {
            $where[] = "(s.title_ar LIKE :s OR s.title_en LIKE :s2)";
            $params[':s']  = "%$search%";
            $params[':s2'] = "%$search%";
        }
        $wClause = implode(' AND ', $where);

        $totalStmt = $pdo->prepare("SELECT COUNT(*) FROM nursing_skills s WHERE $wClause");
        $totalStmt->execute($params);
        $total = (int)$totalStmt->fetchColumn();

        $descExpr = in_array('description_ar', $skillCols) ? 's.description_ar' : (in_array('summary_ar', $skillCols) ? 's.summary_ar' : "''");
        $diffExpr = in_array('difficulty_level', $skillCols) ? 's.difficulty_level' : "'beginner' AS difficulty_level";
        $timeExpr = in_array('estimated_minutes', $skillCols) ? 's.estimated_minutes' : (in_array('duration_minutes', $skillCols) ? 's.duration_minutes' : '30');
        $thumbExpr = in_array('thumbnail', $skillCols) ? 's.thumbnail' : "NULL AS thumbnail";
        $orderExpr = in_array('sort_order', $skillCols) ? 's.sort_order' : (in_array('order_index', $skillCols) ? 's.order_index' : 's.id');

        $stmt = $pdo->prepare("SELECT
            s.id, s.title_ar, s.title_en, {$descExpr} AS summary_ar,
            {$diffExpr}, {$timeExpr} AS duration_minutes, {$thumbExpr},
            c.name_ar AS category_name_ar, c.name_en AS category_name_en, {$catIconCol}
            FROM nursing_skills s
            LEFT JOIN nursing_skill_categories c ON c.id = s.category_id
            WHERE $wClause
            ORDER BY {$orderExpr} ASC, s.title_ar ASC
            LIMIT :lim OFFSET :off");
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $me = $currentStudent['id'];
        foreach ($rows as &$skill) {
            if (!empty($skill['requires_enrollment']) && !empty($skill['course_id'])) {
                $enrolled = $pdo->prepare("SELECT id FROM enrollments
                    WHERE student_id=:sid AND course_id=:cid AND status IN ('active','completed')");
                $enrolled->execute([':sid' => $me, ':cid' => $skill['course_id']]);
                $skill['is_accessible'] = (bool)$enrolled->fetchColumn();
            } else {
                $skill['is_accessible'] = true;
            }
        }
        unset($skill);

        student_json(200, [
            'success' => true,
            'skills'  => $rows,
            'total'   => $total,
            'page'    => $page,
            'pages'   => (int)ceil($total / $limit),
        ]);
        break;

    case 'detail':
        $skillId = (int)($_GET['skill_id'] ?? 0);
        if (!$skillId) {
            student_json(400, ['success' => false, 'error' => 'skill_id مطلوب']);
        }

        $stmt = $pdo->prepare("SELECT s.*, c.name_ar AS category_name_ar, c.name_en AS category_name_en
            FROM nursing_skills s
            JOIN nursing_skill_categories c ON c.id = s.category_id
            WHERE s.id = :id AND s.is_published = 1");
        $stmt->execute([':id' => $skillId]);
        $skill = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$skill) {
            student_json(404, ['success' => false, 'error' => 'المهارة غير موجودة']);
        }

        $me = $currentStudent['id'];
        if (!empty($skill['requires_enrollment']) && !empty($skill['course_id'])) {
            $enrolled = $pdo->prepare("SELECT id FROM enrollments
                WHERE student_id=:sid AND course_id=:cid AND status IN ('active','completed')");
            $enrolled->execute([':sid' => $me, ':cid' => $skill['course_id']]);
            if (!$enrolled->fetchColumn()) {
                student_json(403, ['success' => false, 'error' => 'هذه المهارة تتطلب التسجيل في الكورس أولاً', 'requires_enrollment' => true]);
            }
        }

        // Parse JSON fields
        foreach (['objectives', 'equipment_list', 'steps', 'safety_notes', 'common_mistakes', 'review_questions'] as $field) {
            if (isset($skill[$field]) && is_string($skill[$field])) {
                $decoded = json_decode($skill[$field], true);
                $skill[$field] = is_array($decoded) ? $decoded : [];
            }
        }

        student_json(200, ['success' => true, 'skill' => $skill]);
        break;

    default:
        student_json(400, ['success' => false, 'error' => 'action غير معروف']);
}
