<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/admin_guard.php';

$action = (string)($_GET['action'] ?? 'list');

// Detect support_tickets schema
$stCols       = $pdo->query("SHOW COLUMNS FROM support_tickets")->fetchAll(PDO::FETCH_COLUMN);
$stUserField  = in_array('user_id', $stCols)    ? 'st.user_id'    : 'st.student_id';
$stStuField   = in_array('student_id', $stCols) ? 'st.student_id' : 'st.user_id';
$stJoinField  = $stStuField;  // JOIN users on this field

/* ─── LIST TICKETS ─── */
if ($action === 'list') {
    $page     = max(1, (int)($_GET['page'] ?? 1));
    $limit    = 20; $offset = ($page - 1) * $limit;
    $status   = trim((string)($_GET['status']   ?? ''));
    $priority = trim((string)($_GET['priority'] ?? ''));
    $search   = trim((string)($_GET['search']   ?? ''));

    $where = ['1=1']; $p = [];
    if ($status   !== '') { $where[] = 'st.status=?';   $p[] = $status;   }
    if ($priority !== '') { $where[] = 'st.priority=?'; $p[] = $priority; }
    if ($search   !== '') {
        $where[] = '(u.full_name LIKE ? OR st.subject LIKE ? OR u.email LIKE ?)';
        $p[] = "%$search%"; $p[] = "%$search%"; $p[] = "%$search%";
    }
    $w = implode(' AND ', $where);

    try {
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM support_tickets st JOIN users u ON u.id={$stJoinField} WHERE $w");
        $countStmt->execute($p);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT st.id, st.ticket_number, st.subject, st.status, st.priority, st.category,
                   {$stUserField} AS user_id_val, {$stJoinField} AS student_id_val,
                   st.created_at, st.updated_at,
                   u.full_name AS student_name, u.email AS student_email,
                   (SELECT COUNT(*) FROM support_messages sm WHERE sm.ticket_id=st.id) AS reply_count
            FROM support_tickets st
            JOIN users u ON u.id = {$stJoinField}
            WHERE $w ORDER BY st.updated_at DESC LIMIT $limit OFFSET $offset
        ");
        $stmt->execute($p);
        $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

        admin_json(200, ['success' => true, 'tickets' => $tickets,
            'meta' => ['total' => $total, 'page' => $page, 'pages' => (int)ceil($total/$limit)]]);
    } catch (PDOException $e) {
        error_log('[Admin support list] ' . $e->getMessage());
        admin_json(500, ['success' => false, 'message' => $e->getMessage()]);
    }
}

/* ─── GET SINGLE TICKET ─── */
if ($action === 'get') {
    $id = (int)($_GET['ticket_id'] ?? 0);
    if ($id < 1) admin_json(400, ['success' => false, 'message' => 'ticket_id مطلوب']);

    try {
        $stmt = $pdo->prepare("
            SELECT st.*, u.full_name AS student_name, u.email AS student_email
            FROM support_tickets st
            JOIN users u ON u.id = {$stJoinField}
            WHERE st.id = ?
        ");
        $stmt->execute([$id]);
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$ticket) admin_json(404, ['success' => false, 'message' => 'التذكرة غير موجودة']);

        // Load messages
        $msgStmt = $pdo->prepare("
            SELECT sm.*, u.full_name AS sender_name, u.role AS sender_role
            FROM support_messages sm
            LEFT JOIN users u ON u.id = sm.sender_id
            WHERE sm.ticket_id = ?
            ORDER BY sm.created_at ASC
        ");
        $msgStmt->execute([$id]);
        $ticket['messages'] = $msgStmt->fetchAll(PDO::FETCH_ASSOC);

        admin_json(200, ['success' => true, 'ticket' => $ticket]);
    } catch (PDOException $e) {
        error_log('[Admin support get] ' . $e->getMessage());
        admin_json(500, ['success' => false, 'message' => $e->getMessage()]);
    }
}

/* ─── REPLY ─── */
if ($action === 'reply') {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $id      = (int)($input['ticket_id'] ?? 0);
    $message = trim((string)($input['message'] ?? ''));
    if ($id < 1 || !$message) admin_json(400, ['success' => false, 'message' => 'بيانات ناقصة']);

    try {
        $pdo->prepare("INSERT INTO support_messages (ticket_id, sender_id, sender_role, sender_name, message)
            VALUES (?,?,?,?,?)")->execute([$id, $currentUser['id'], 'admin', $currentUser['full_name'], $message]);

        $pdo->prepare("UPDATE support_tickets SET status=IF(status='open','in_progress',status), updated_at=NOW() WHERE id=?")
            ->execute([$id]);

        admin_json(200, ['success' => true, 'message' => 'تم إرسال الرد']);
    } catch (PDOException $e) {
        error_log('[Admin support reply] ' . $e->getMessage());
        admin_json(500, ['success' => false, 'message' => $e->getMessage()]);
    }
}

/* ─── UPDATE STATUS ─── */
if ($action === 'update_status') {
    $input  = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $id     = (int)($input['ticket_id'] ?? 0);
    $status = trim((string)($input['status'] ?? ''));
    $allowed = ['open','in_progress','waiting','resolved','closed'];
    if ($id < 1 || !in_array($status, $allowed)) admin_json(400, ['success' => false, 'message' => 'بيانات خاطئة']);

    try {
        $pdo->prepare("UPDATE support_tickets SET status=?, updated_at=NOW() WHERE id=?")->execute([$status, $id]);
        admin_json(200, ['success' => true, 'message' => 'تم تحديث الحالة']);
    } catch (PDOException $e) {
        admin_json(500, ['success' => false, 'message' => $e->getMessage()]);
    }
}

admin_json(400, ['success' => false, 'message' => 'action غير معروف']);
