<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/student_guard.php';

$action = (string)($_GET['action'] ?? $_POST['action'] ?? 'list');
$me     = (int)$currentStudent['id'];

// Detect if support_tickets uses user_id or student_id
static $ticketUserCol = null;
if ($ticketUserCol === null) {
    $cols = $pdo->query("SHOW COLUMNS FROM support_tickets")->fetchAll(PDO::FETCH_COLUMN);
    $ticketUserCol = in_array('user_id', $cols) ? 'user_id' : 'student_id';
}

// Detect if support_messages uses 'message' or 'body'
static $msgCol = null;
if ($msgCol === null) {
    $cols2 = $pdo->query("SHOW COLUMNS FROM support_messages")->fetchAll(PDO::FETCH_COLUMN);
    $msgCol = in_array('message', $cols2) ? 'message' : 'body';
}

/* LIST MY TICKETS */
if ($action === 'list') {
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $limit  = 20;
    $offset = ($page - 1) * $limit;

    $total = (int)$pdo->prepare("SELECT COUNT(*) FROM support_tickets WHERE {$ticketUserCol}=?")->execute([$me])
        ? $pdo->prepare("SELECT COUNT(*) FROM support_tickets WHERE {$ticketUserCol}=:m")->execute([':m'=>$me]) : 0;
    $totalStmt = $pdo->prepare("SELECT COUNT(*) FROM support_tickets WHERE {$ticketUserCol}=:m");
    $totalStmt->execute([':m' => $me]);
    $total = (int)$totalStmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT st.id, st.ticket_number, st.subject, st.category,
        st.priority, st.status, st.created_at, st.updated_at,
        (SELECT COUNT(*) FROM support_messages sm WHERE sm.ticket_id=st.id) AS reply_count
        FROM support_tickets st
        WHERE st.{$ticketUserCol}=:m
        ORDER BY st.updated_at DESC
        LIMIT :lim OFFSET :off");
    $stmt->bindValue(':m', $me, PDO::PARAM_INT);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    student_json(200, ['success' => true, 'tickets' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total]);
}

/* GET TICKET WITH MESSAGES */
if ($action === 'get') {
    $ticketId = (int)($_GET['id'] ?? $_GET['ticket_id'] ?? 0);
    if ($ticketId <= 0) student_json(400, ['success' => false, 'message' => 'id التذكرة مطلوب']);

    $t = $pdo->prepare("SELECT * FROM support_tickets WHERE id=? AND {$ticketUserCol}=?");
    $t->execute([$ticketId, $me]);
    $ticket = $t->fetch(PDO::FETCH_ASSOC);
    if (!$ticket) student_json(404, ['success' => false, 'message' => 'التذكرة غير موجودة']);

    $msgs = $pdo->prepare("SELECT sm.id, sm.{$msgCol} AS message, sm.sender_id,
        sm.created_at, u.full_name AS sender_name, u.role AS sender_role
        FROM support_messages sm
        JOIN users u ON u.id=sm.sender_id
        WHERE sm.ticket_id=?
        ORDER BY sm.created_at ASC");
    $msgs->execute([$ticketId]);

    student_json(200, ['success' => true, 'ticket' => $ticket, 'messages' => $msgs->fetchAll(PDO::FETCH_ASSOC)]);
}

/* CREATE TICKET */
if ($action === 'create') {
    $input    = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $subject  = trim((string)($input['subject']     ?? ''));
    $message  = trim((string)($input['description'] ?? $input['message'] ?? ''));
    $category = trim((string)($input['category']    ?? 'technical'));
    $priority = trim((string)($input['priority']    ?? 'medium'));

    if (!$subject || !$message) student_json(400, ['success' => false, 'message' => 'الموضوع والوصف مطلوبان']);

    $validCat  = ['technical', 'payment', 'content', 'certificate', 'other', 'general'];
    $validPrio = ['low', 'medium', 'high', 'urgent'];
    if (!in_array($category, $validCat, true))  $category = 'technical';
    if (!in_array($priority, $validPrio, true)) $priority = 'medium';

    $ticketNumber = 'TKT-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));

    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare("INSERT INTO support_tickets
            ({$ticketUserCol}, ticket_number, subject, category, priority, status, description, created_at, updated_at)
            VALUES (?,?,?,?,?,'open',?,NOW(),NOW())");
        $ins->execute([$me, $ticketNumber, $subject, $category, $priority, $message]);
        $ticketId = (int)$pdo->lastInsertId();

        // Insert first message
        $pdo->prepare("INSERT INTO support_messages (ticket_id, sender_id, {$msgCol}, created_at)
            VALUES (?,?,?,NOW())")
            ->execute([$ticketId, $me, $message]);

        // Notify admins
        try {
            $admins = $pdo->query("SELECT id FROM users WHERE role='admin' AND is_active=1")->fetchAll(PDO::FETCH_COLUMN);
            $notifIns = $pdo->prepare("INSERT INTO notifications (user_id, title, body, type, created_at)
                VALUES (?, ?, ?, 'info', NOW())");
            foreach ($admins as $adminId) {
                $notifIns->execute([$adminId, 'تذكرة دعم جديدة', "موضوع: $subject | من المستخدم #$me"]);
            }
        } catch (Exception $e) {}

        $pdo->commit();
        student_json(200, ['success' => true, 'ticket_id' => $ticketId, 'ticket_number' => $ticketNumber, 'message' => 'تم إرسال التذكرة بنجاح']);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[Support create] ' . $e->getMessage());
        student_json(500, ['success' => false, 'message' => 'خطأ في الخادم: ' . $e->getMessage()]);
    }
}

/* REPLY */
if ($action === 'reply') {
    $input    = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $ticketId = (int)($input['ticket_id'] ?? $input['id'] ?? 0);
    $message  = trim((string)($input['message'] ?? ''));

    if ($ticketId <= 0 || !$message) student_json(400, ['success' => false, 'message' => 'ticket_id والرسالة مطلوبان']);

    $t = $pdo->prepare("SELECT id, status FROM support_tickets WHERE id=? AND {$ticketUserCol}=?");
    $t->execute([$ticketId, $me]);
    $ticket = $t->fetch(PDO::FETCH_ASSOC);
    if (!$ticket) student_json(404, ['success' => false, 'message' => 'التذكرة غير موجودة']);
    if (in_array($ticket['status'], ['resolved', 'closed'], true)) {
        student_json(409, ['success' => false, 'message' => 'التذكرة مغلقة ولا يمكن الرد عليها']);
    }

    $pdo->prepare("INSERT INTO support_messages (ticket_id, sender_id, {$msgCol}, created_at) VALUES (?,?,?,NOW())")
        ->execute([$ticketId, $me, $message]);
    $pdo->prepare("UPDATE support_tickets SET status='open', updated_at=NOW() WHERE id=?")
        ->execute([$ticketId]);

    student_json(200, ['success' => true, 'message' => 'تم إرسال الرد بنجاح']);
}

student_json(400, ['success' => false, 'message' => 'action غير معروف']);
