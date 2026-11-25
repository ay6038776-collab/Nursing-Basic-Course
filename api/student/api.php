<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/student_guard.php';

function s_out(array $data, int $status = 200): never
{
    http_response_code($status);
    $data['success'] = $status >= 200 && $status < 300;
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$uid = (int) $currentStudent['id'];

$input = json_decode(file_get_contents('php://input'), true);
$input = is_array($input) ? $input : $_POST;

$action = (string) ($_GET['a'] ?? ($input['action'] ?? ''));

try {
    switch ($action) {
        /*
         * 1) Fetch Quiz Questions for Taking Quiz
         */
        case 'take_quiz':
            $quizId = (int) ($input['quiz_id'] ?? ($_GET['quiz_id'] ?? 0));
            if ($quizId <= 0) s_out(['message' => 'معرّف الاختبار غير صالح.'], 400);

            // Verify enrollment in course
            $qStmt = $pdo->prepare("
                SELECT q.*, c.title AS course_title
                FROM quizzes q
                JOIN courses c ON c.id = q.course_id
                JOIN enrollments e ON e.course_id = c.id
                WHERE q.id = ? AND e.student_id = ? AND e.status IN ('active', 'completed') AND q.status = 'published'
            ");
            $qStmt->execute([$quizId, $uid]);
            $quiz = $qStmt->fetch(PDO::FETCH_ASSOC);

            if (!$quiz) {
                s_out(['message' => 'الاختبار غير متاح أو لم تسجل في هذا الكورس بعد.'], 403);
            }

            // Check attempts limit
            $maxAttempts = (int) $quiz['max_attempts'];
            if ($maxAttempts > 0) {
                $attCnt = (int) $pdo->query("SELECT COUNT(*) FROM quiz_attempts WHERE quiz_id = $quizId AND student_id = $uid")->fetchColumn();
                if ($attCnt >= $maxAttempts) {
                    s_out(['message' => "لقد استنفدت الحد الأقصى للمحاولات ({$maxAttempts} محاولات)."], 403);
                }
            }

            // Fetch questions
            $qstStmt = $pdo->prepare("SELECT id, question_text, question_type, points, explanation FROM questions WHERE quiz_id = ? ORDER BY sort_order, id");
            $qstStmt->execute([$quizId]);
            $questions = $qstStmt->fetchAll(PDO::FETCH_ASSOC);

            // Fetch options without is_correct
            $optStmt = $pdo->prepare("SELECT id, question_id, option_text, sort_order FROM quiz_options WHERE question_id = ? ORDER BY sort_order, id");
            foreach ($questions as &$qst) {
                $optStmt->execute([$qst['id']]);
                $qst['options'] = $optStmt->fetchAll(PDO::FETCH_ASSOC);
            }
            unset($qst);

            s_out([
                'quiz' => [
                    'id' => (int) $quiz['id'],
                    'title' => $quiz['title'],
                    'course_title' => $quiz['course_title'],
                    'description' => $quiz['description'],
                    'time_limit_minutes' => (int) $quiz['time_limit_minutes'],
                    'pass_percent' => (float) $quiz['pass_percent'],
                    'questions' => $questions
                ]
            ]);

        /*
         * 2) Submit Quiz Answers & Grade
         */
        case 'submit_quiz':
            $quizId = (int) ($input['quiz_id'] ?? 0);
            $answers = $input['answers'] ?? []; // [question_id => selected_option_id]

            if ($quizId <= 0 || !is_array($answers)) {
                s_out(['message' => 'بيانات الإجابات غير صالحة.'], 400);
            }

            $qStmt = $pdo->prepare("SELECT * FROM quizzes WHERE id = ? AND status = 'published'");
            $qStmt->execute([$quizId]);
            $quiz = $qStmt->fetch(PDO::FETCH_ASSOC);
            if (!$quiz) s_out(['message' => 'الاختبار غير موجود.'], 404);

            // Calculate total points and earned points
            $qstStmt = $pdo->prepare("SELECT id, points FROM questions WHERE quiz_id = ?");
            $qstStmt->execute([$quizId]);
            $allQ = $qstStmt->fetchAll(PDO::FETCH_ASSOC);

            $maxScore = 0.0;
            $earnedScore = 0.0;

            $optCheck = $pdo->prepare("SELECT is_correct FROM quiz_options WHERE id = ? AND question_id = ?");

            foreach ($allQ as $qRow) {
                $qId = (int) $qRow['id'];
                $pts = (float) $qRow['points'];
                $maxScore += $pts;

                if (isset($answers[$qId])) {
                    $selectedOptId = (int) $answers[$qId];
                    $optCheck->execute([$selectedOptId, $qId]);
                    $isCorrect = (bool) $optCheck->fetchColumn();
                    if ($isCorrect) {
                        $earnedScore += $pts;
                    }
                }
            }

            $maxScore = max(1.0, $maxScore);
            $percentScore = round(($earnedScore / $maxScore) * 100, 2);
            $passPercent = (float) ($quiz['pass_percent'] ?? 70.0);
            $isPassed = $percentScore >= $passPercent ? 1 : 0;

            // Attempt number
            $attNum = (int) $pdo->query("SELECT COALESCE(MAX(attempt_number), 0) + 1 FROM quiz_attempts WHERE quiz_id = $quizId AND student_id = $uid")->fetchColumn();

            $pdo->beginTransaction();

            $insAtt = $pdo->prepare("
                INSERT INTO quiz_attempts (quiz_id, student_id, attempt_number, started_at, submitted_at, score, max_score, percent_score, passed, status)
                VALUES (?, ?, ?, NOW(), NOW(), ?, ?, ?, ?, 'graded')
            ");
            $insAtt->execute([$quizId, $uid, $attNum, $earnedScore, $maxScore, $percentScore, $isPassed]);
            $attemptId = (int) $pdo->lastInsertId();

            // Check if passed and check if course certificate should be generated
            if ($isPassed) {
                $cId = (int) $quiz['course_id'];
                // Check if certificate already exists
                $certCheck = $pdo->prepare("SELECT id FROM certificates WHERE student_id = ? AND course_id = ? AND revoked_at IS NULL");
                $certCheck->execute([$uid, $cId]);
                if (!$certCheck->fetchColumn()) {
                    $cTitle = (string) $pdo->query("SELECT title FROM courses WHERE id = $cId")->fetchColumn();
                    $sName = $currentStudent['full_name'];
                    $certNum = 'CERT-EN-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(4)));

                    $insCert = $pdo->prepare("
                        INSERT INTO certificates (certificate_number, student_id, course_id, student_name_snapshot, course_title_snapshot, issuer_name, issued_at, verification_token_hash, created_at)
                        VALUES (?, ?, ?, ?, ?, 'Edu Nursing Academy - د. خالد عبد الناصر', NOW(), ?, NOW())
                    ");
                    $tokenHash = hash('sha256', $certNum . $uid);
                    $insCert->execute([$certNum, $uid, $cId, $sName, $cTitle, $tokenHash]);
                }
            }

            $pdo->commit();

            s_out([
                'message' => $isPassed ? 'تهانينا! لقد اجتزت الاختبار بنجاح 🎉' : 'للأسف لم تجتز الاختبار هذه المرة. يمكنك مراجعة الشروحات وإعادة المحاولة.',
                'passed' => (bool) $isPassed,
                'score' => $earnedScore,
                'max_score' => $maxScore,
                'percent' => $percentScore,
                'pass_percent' => $passPercent,
                'attempt_id' => $attemptId
            ]);

        /*
         * 3) Create Support Ticket
         */
        case 'create_ticket':
            $subject = trim((string) ($input['subject'] ?? ''));
            $category = (string) ($input['category'] ?? 'technical');
            $description = trim((string) ($input['description'] ?? ''));

            if ($subject === '' || $description === '') {
                s_out(['message' => 'عنوان التذكرة وتفاصيل المشكلة مطلوبان.'], 422);
            }

            if (!in_array($category, ['account', 'course', 'payment', 'technical', 'other'], true)) {
                $category = 'technical';
            }

            $ticketNum = 'TCK-' . strtoupper(bin2hex(random_bytes(3)));

            $pdo->beginTransaction();
            $insTick = $pdo->prepare("
                INSERT INTO support_tickets (ticket_number, user_id, subject, description, category, priority, status, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, 'normal', 'open', NOW(), NOW())
            ");
            $insTick->execute([$ticketNum, $uid, $subject, $description, $category]);
            $ticketId = (int) $pdo->lastInsertId();

            $insMsg = $pdo->prepare("
                INSERT INTO support_messages (ticket_id, sender_id, message, is_internal_note, created_at)
                VALUES (?, ?, ?, 0, NOW())
            ");
            $insMsg->execute([$ticketId, $uid, $description]);

            $pdo->commit();

            s_out([
                'message' => 'تم إنشاء تذكرة الدعم الفني بنجاح، وسيقوم فريق الدعم بالرد قريباً.',
                'ticket_id' => $ticketId,
                'ticket_number' => $ticketNum
            ]);

        /*
         * 4) Get Ticket Messages
         */
        case 'ticket_messages':
            $ticketId = (int) ($input['ticket_id'] ?? ($_GET['ticket_id'] ?? 0));
            $tStmt = $pdo->prepare("SELECT * FROM support_tickets WHERE id = ? AND user_id = ?");
            $tStmt->execute([$ticketId, $uid]);
            $ticket = $tStmt->fetch(PDO::FETCH_ASSOC);

            if (!$ticket) s_out(['message' => 'التذكرة غير موجودة.'], 404);

            $mStmt = $pdo->prepare("
                SELECT sm.*, u.full_name AS sender_name, u.role AS sender_role
                FROM support_messages sm
                JOIN users u ON u.id = sm.sender_id
                WHERE sm.ticket_id = ?
                ORDER BY sm.id ASC
            ");
            $mStmt->execute([$ticketId]);
            $messages = $mStmt->fetchAll(PDO::FETCH_ASSOC);

            s_out(['ticket' => $ticket, 'messages' => $messages]);

        /*
         * 5) Reply to Ticket
         */
        case 'reply_ticket':
            $ticketId = (int) ($input['ticket_id'] ?? 0);
            $message = trim((string) ($input['message'] ?? ''));

            if ($ticketId <= 0 || $message === '') {
                s_out(['message' => 'نص الرسالة مطلوب.'], 422);
            }

            // Verify ownership
            $tStmt = $pdo->prepare("SELECT id FROM support_tickets WHERE id = ? AND user_id = ?");
            $tStmt->execute([$ticketId, $uid]);
            if (!$tStmt->fetchColumn()) s_out(['message' => 'التذكرة غير موجودة.'], 404);

            $pdo->beginTransaction();
            $pdo->prepare("
                INSERT INTO support_messages (ticket_id, sender_id, message, is_internal_note, created_at)
                VALUES (?, ?, ?, 0, NOW())
            ")->execute([$ticketId, $uid, $message]);

            $pdo->prepare("UPDATE support_tickets SET status = 'open', updated_at = NOW() WHERE id = ?")->execute([$ticketId]);
            $pdo->commit();

            s_out(['message' => 'تم إرسال ردك بنجاح.']);

        /*
         * 6) Mark Notifications as Read
         */
        case 'mark_notifs_read':
            $pdo->prepare("UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL")->execute([$uid]);
            s_out(['message' => 'تم تعليم الإشعارات كمقروءة.']);

        /*
         * 7) Update Profile
         */
        case 'update_profile':
            $fullName = trim((string) ($input['full_name'] ?? ''));
            $phone = trim((string) ($input['phone'] ?? ''));

            if ($fullName === '') s_out(['message' => 'الاسم مطلوب.'], 422);

            $pdo->prepare("UPDATE users SET full_name = ?, phone = ? WHERE id = ?")->execute([$fullName, $phone !== '' ? $phone : null, $uid]);
            $pdo->prepare("UPDATE profiles SET display_name = ? WHERE user_id = ?")->execute([$fullName, $uid]);

            $_SESSION['user_name'] = $fullName;

            s_out(['message' => 'تم تحديث بيانات ملفك الشخصي بنجاح.']);

        /*
         * 8) Change Password
         */
        case 'change_password':
            $oldP = (string) ($input['old_password'] ?? '');
            $newP = (string) ($input['new_password'] ?? '');

            if ($oldP === '' || $newP === '') s_out(['message' => 'أدخل كلمة المرور الحالية والجديدة.'], 422);
            if (strlen($newP) < 8) s_out(['message' => 'كلمة المرور الجديدة يجب ألا تقل عن 8 خانات.'], 422);

            $currHash = $pdo->query("SELECT password_hash FROM users WHERE id = $uid")->fetchColumn();
            if (!$currHash || !password_verify($oldP, $currHash)) {
                s_out(['message' => 'كلمة المرور الحالية غير صحيحة.'], 400);
            }

            $newHash = password_hash($newP, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([$newHash, $uid]);

            s_out(['message' => 'تم تغيير كلمة المرور بنجاح.']);

        /*
         * 9) Upload Student Avatar
         */
        case 'upload_avatar':
            $dataUri = (string)($input['image_data'] ?? '');
            if ($dataUri !== '' && preg_match('/^data:image\/(jpeg|png|webp|jpg);base64,(.+)$/', $dataUri, $matches)) {
                $ext = ($matches[1] === 'jpeg' || $matches[1] === 'jpg') ? 'jpg' : $matches[1];
                $dir = __DIR__ . '/../../uploads/avatars';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $filename = 'stu_' . $uid . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                file_put_contents($dir . '/' . $filename, base64_decode($matches[2]));
                $avatarUrl = 'uploads/avatars/' . $filename;
                $pdo->prepare("UPDATE users SET avatar = ? WHERE id = ?")->execute([$avatarUrl, $uid]);
                try {
                    $pdo->prepare("UPDATE profiles SET avatar = ? WHERE user_id = ?")->execute([$avatarUrl, $uid]);
                } catch (Throwable $e) {}
                s_out(['message' => 'تم تحديث الصورة الشخصية بنجاح.', 'avatar_url' => $avatarUrl]);
            }
            s_out(['message' => 'بيانات الصورة غير صالحة.'], 422);

        /*
         * 10) Create Order & Purchase Request
         */
        case 'create_order':
            $itemType = (string)($input['item_type'] ?? 'course');
            if (!in_array($itemType, ['course', 'book'], true)) $itemType = 'course';
            $itemId = (int)($input['item_id'] ?? 0);
            $payMethod = (string)($input['payment_method'] ?? 'vodafone_cash');
            if (!in_array($payMethod, ['vodafone_cash', 'instapay', 'card', 'manual_transfer'], true)) {
                $payMethod = 'vodafone_cash';
            }
            $txRef = trim((string)($input['transaction_reference'] ?? ''));
            $notes = trim((string)($input['notes'] ?? ''));
            $receiptData = (string)($input['receipt_data'] ?? '');

            if ($itemId <= 0) {
                s_out(['message' => 'العنصر المطلوب غير صالح.'], 400);
            }

            // Check item & price
            $itemTitle = '';
            $itemPrice = 0.0;
            if ($itemType === 'course') {
                $cStmt = $pdo->prepare("SELECT id, title, price, discounted_price FROM courses WHERE id = ? AND status = 'published'");
                $cStmt->execute([$itemId]);
                $course = $cStmt->fetch(PDO::FETCH_ASSOC);
                if (!$course) s_out(['message' => 'الكورس التدريبي غير متاح للشراء.'], 404);

                // Check if already actively enrolled
                $enrCheck = $pdo->prepare("SELECT id FROM enrollments WHERE student_id = ? AND course_id = ? AND status IN ('active', 'completed')");
                $enrCheck->execute([$uid, $itemId]);
                if ($enrCheck->fetchColumn()) {
                    s_out(['message' => 'أنت مسجل بالفعل في هذا الكورس التدريبي.'], 400);
                }

                $itemTitle = $course['title'];
                $itemPrice = !empty($course['discounted_price']) ? (float)$course['discounted_price'] : (float)$course['price'];
            } else {
                $bStmt = $pdo->prepare("SELECT id, title_ar, title_en, price FROM books WHERE id = ? AND status = 'published'");
                $bStmt->execute([$itemId]);
                $book = $bStmt->fetch(PDO::FETCH_ASSOC);
                if (!$book) s_out(['message' => 'المذكرة / الكتاب غير متاح حالياً.'], 404);

                $itemTitle = $book['title_ar'] ?: $book['title_en'];
                $itemPrice = (float)($book['price'] ?? 0.0);
            }

            // Save receipt image if provided
            $proofPath = null;
            if ($receiptData !== '' && preg_match('/^data:image\/(jpeg|png|webp|jpg);base64,(.+)$/', $receiptData, $rMatches)) {
                $rExt = ($rMatches[1] === 'jpeg' || $rMatches[1] === 'jpg') ? 'jpg' : $rMatches[1];
                $rDir = __DIR__ . '/../../uploads/receipts';
                if (!is_dir($rDir)) mkdir($rDir, 0755, true);
                $rFilename = 'receipt_' . $uid . '_' . bin2hex(random_bytes(8)) . '.' . $rExt;
                file_put_contents($rDir . '/' . $rFilename, base64_decode($rMatches[2]));
                $proofPath = 'uploads/receipts/' . $rFilename;
            }

            $orderNum = 'ORD-EN-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));

            $pdo->beginTransaction();

            $insOrd = $pdo->prepare("
                INSERT INTO orders (order_number, student_id, subtotal, total_amount, currency, payment_method, status, transaction_reference, proof_path, admin_notes, created_at, updated_at)
                VALUES (?, ?, ?, ?, 'EGP', ?, 'pending_review', ?, ?, ?, NOW(), NOW())
            ");
            $insOrd->execute([$orderNum, $uid, $itemPrice, $itemPrice, $payMethod, $txRef ?: null, $proofPath, $notes ?: null]);
            $orderId = (int)$pdo->lastInsertId();

            $insItem = $pdo->prepare("
                INSERT INTO order_items (order_id, item_type, item_id, title_snapshot, price_snapshot, quantity, subtotal, created_at)
                VALUES (?, ?, ?, ?, ?, 1, ?, NOW())
            ");
            $insItem->execute([$orderId, $itemType, $itemId, $itemTitle, $itemPrice, $itemPrice]);

            // Notify Admin
            $adminIds = $pdo->query("SELECT id FROM users WHERE role = 'admin'")->fetchAll(PDO::FETCH_COLUMN);
            $stName = $currentStudent['full_name'];
            $notifAdmin = $pdo->prepare("
                INSERT INTO notifications (user_id, title, message, type, action_url, created_at)
                VALUES (?, ?, ?, 'order', 'admin.html#orders', NOW())
            ");
            foreach ($adminIds as $admId) {
                $notifAdmin->execute([
                    $admId,
                    'طلب شراء جديد #' . $orderNum,
                    "قام الطالب {$stName} بطلب اشتراك في ({$itemTitle}) بمبلغ {$itemPrice} ج.م وهو قيد المراجعة."
                ]);
            }

            // Notify Student
            $pdo->prepare("
                INSERT INTO notifications (user_id, title, message, type, action_url, created_at)
                VALUES (?, ?, ?, 'order', 'student.html', NOW())
            ")->execute([
                $uid,
                'تم تسجيل طلبك بنجاح #' . $orderNum,
                "تم استلام طلب اشتراكك في ({$itemTitle}) بنجاح. سيقوم الفريق بمراجعة التحويل وتفعيل الكورس في أقرب وقت."
            ]);

            $pdo->commit();

            s_out([
                'message' => 'تم تسجيل طلبك بنجاح وجارٍ مراجعة عملية التحويل لتفعيل الكورس فوراً.',
                'order_id' => $orderId,
                'order_number' => $orderNum
            ]);

        /*
         * 11) Submit Course/Book Review
         */
        case 'submit_review':
            $courseId = (int)($input['course_id'] ?? ($input['item_id'] ?? 0));
            $rating = max(1, min(5, (int)($input['rating'] ?? 5)));
            $comment = trim((string)($input['comment'] ?? ''));

            if ($courseId <= 0) s_out(['message' => 'معرّف الكورس غير صالح.'], 400);
            if ($comment === '') s_out(['message' => 'اكتب تعليقك قبل إرسال التقييم.'], 400);
            if (strlen($comment) > 2000) s_out(['message' => 'التعليق طويل جدًا، الحد الأقصى 2000 حرف.'], 400);

            // Verify active enrollment
            $enrCheck = $pdo->prepare("SELECT id FROM enrollments WHERE student_id = ? AND course_id = ? AND status IN ('active', 'completed')");
            $enrCheck->execute([$uid, $courseId]);
            if (!$enrCheck->fetchColumn()) {
                s_out(['message' => 'يمكنك فقط تقييم الكورسات التي أنت مسجل بها.'], 403);
            }

            // Check if review already exists
            $existing = $pdo->prepare("SELECT id FROM reviews WHERE user_id = ? AND item_type = 'course' AND item_id = ?");
            $existing->execute([$uid, $courseId]);
            $revId = (int)$existing->fetchColumn();

            if ($revId > 0) {
                $pdo->prepare("UPDATE reviews SET rating = ?, comment = ?, status = 'pending', reviewed_by = NULL, reviewed_at = NULL, created_at = NOW() WHERE id = ?")->execute([$rating, $comment, $revId]);
            } else {
                $pdo->prepare("INSERT INTO reviews (user_id, item_type, item_id, rating, comment, status, created_at) VALUES (?, 'course', ?, ?, ?, 'pending', NOW())")->execute([$uid, $courseId, $rating, $comment]);
            }

            s_out(['message' => 'شكرًا لك! تم إرسال تقييمك وتعليقك للإدارة للمراجعة ⭐']);

        /*
         * 12) Order Details
         */
        case 'order_details':
            $orderId = (int)($input['order_id'] ?? ($_GET['order_id'] ?? 0));
            if ($orderId <= 0) s_out(['message' => 'رقم الطلب غير صالح.'], 400);

            $ordStmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? AND student_id = ?");
            $ordStmt->execute([$orderId, $uid]);
            $order = $ordStmt->fetch(PDO::FETCH_ASSOC);
            if (!$order) s_out(['message' => 'الطلب غير موجود.'], 404);

            $itemsStmt = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ?");
            $itemsStmt->execute([$orderId]);
            $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

            s_out(['order' => $order, 'items' => $items]);

        default:
            s_out(['message' => 'إجراء غير معروف.'], 400);
    }
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[Student API Error] ' . $e->getMessage());
    s_out(['message' => 'حدث خطأ في معالجة طلبك.'], 500);
}
