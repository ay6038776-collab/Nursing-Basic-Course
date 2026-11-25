<?php
declare(strict_types=1);

/** Shared, role-scoped assessment management API. The calling portal guard
 * supplies $assessmentActor and $assessmentAdmin before this file is loaded. */
require_once __DIR__ . '/../config/auth.php';

function assessment_reply(int $status, array $payload): never {
    nbc_json($status, array_merge(['success' => $status >= 200 && $status < 300], $payload));
}

function assessment_body(): array {
    $body = json_decode((string)file_get_contents('php://input'), true);
    return is_array($body) ? $body : [];
}

function assessment_can_course(PDO $pdo, int $courseId, int $actorId, bool $isAdmin): bool {
    if ($isAdmin) return true;
    try {
        $stmt = $pdo->prepare('SELECT 1 FROM courses c WHERE c.id=? AND (c.instructor_id=? OR EXISTS (SELECT 1 FROM course_instructors ci WHERE ci.course_id=c.id AND ci.instructor_id=?)) LIMIT 1');
        $stmt->execute([$courseId, $actorId, $actorId]); return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        $stmt = $pdo->prepare('SELECT 1 FROM courses WHERE id=? AND instructor_id=? LIMIT 1');
        $stmt->execute([$courseId, $actorId]); return (bool)$stmt->fetchColumn();
    }
}

function assessment_question(PDO $pdo, int $id, int $actorId, bool $isAdmin): ?array {
    $stmt = $pdo->prepare('SELECT q.* FROM questions q WHERE q.id=? LIMIT 1');
    $stmt->execute([$id]);
    $question = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$question) return null;
    if ($isAdmin || (int)$question['created_by'] === $actorId) return $question;
    $courseId = (int)($question['course_id'] ?? 0);
    if ($courseId > 0 && assessment_can_course($pdo, $courseId, $actorId, false)) return $question;
    $stmt = $pdo->prepare('SELECT 1 FROM quizzes z LEFT JOIN courses c ON c.id=z.course_id WHERE z.id=? AND (z.created_by=? OR c.instructor_id=?) LIMIT 1');
    $stmt->execute([(int)($question['quiz_id'] ?? 0), $actorId, $actorId]);
    return $stmt->fetchColumn() ? $question : null;
}

function assessment_can_quiz(PDO $pdo, int $quizId, int $actorId, bool $isAdmin): ?array {
    $stmt = $pdo->prepare('SELECT z.* FROM quizzes z WHERE z.id=? LIMIT 1');
    $stmt->execute([$quizId]);
    $quiz = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$quiz) return null;
    if ($isAdmin || (int)$quiz['created_by'] === $actorId || assessment_can_course($pdo, (int)$quiz['course_id'], $actorId, false)) return $quiz;
    return null;
}

function assessment_log(PDO $pdo, int $actorId, string $key, string $type, ?int $entityId, array $details = []): void {
    $stmt = $pdo->prepare('INSERT INTO assessment_event_logs (actor_user_id,event_key,entity_type,entity_id,details_json) VALUES (?,?,?,?,?)');
    $stmt->execute([$actorId, $key, $type, $entityId, $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null]);
}

function assessment_save_question(PDO $pdo, array $data, int $actorId, bool $isAdmin, ?int $existingId = null): int {
    $text = trim((string)($data['question_text'] ?? ''));
    $type = (string)($data['question_type'] ?? 'multiple_choice');
    $allowed = ['multiple_choice','mcq','multiple_select','true_false','short_answer','essay','matching','fill_blank','ordering','clinical_case','image_based'];
    $points = max(0.01, min(10000, (float)($data['points'] ?? 1)));
    $courseId = (int)($data['course_id'] ?? 0) ?: null;
    $status = (string)($data['status'] ?? 'draft');
    $difficulty = (string)($data['difficulty'] ?? 'medium');
    if ($text === '' || !in_array($type, $allowed, true)) throw new InvalidArgumentException('نص السؤال ونوعه مطلوبان بشكل صحيح.');
    if (!in_array($status, ['draft','approved','archived'], true)) throw new InvalidArgumentException('حالة السؤال غير صالحة.');
    if (!in_array($difficulty, ['easy','medium','hard'], true)) throw new InvalidArgumentException('مستوى الصعوبة غير صالح.');
    if ($courseId && !assessment_can_course($pdo, $courseId, $actorId, $isAdmin)) throw new DomainException('لا تملك صلاحية إدارة أسئلة هذا الكورس.');
    $options = is_array($data['options'] ?? null) ? $data['options'] : [];
    if (in_array($type, ['multiple_choice','mcq','multiple_select','true_false','image_based'], true)) {
        $validOptions = array_filter($options, static fn($option) => trim((string)($option['text'] ?? '')) !== '');
        if (count($validOptions) < 2) throw new InvalidArgumentException('أضف اختيارين على الأقل لهذا السؤال.');
        if (!array_filter($validOptions, static fn($option) => !empty($option['is_correct']))) throw new InvalidArgumentException('حدد إجابة صحيحة واحدة على الأقل.');
        if (in_array($type, ['multiple_choice','mcq','true_false','image_based'], true) && count(array_filter($validOptions, static fn($option) => !empty($option['is_correct']))) !== 1) throw new InvalidArgumentException('حدد إجابة صحيحة واحدة فقط.');
    }
    if(in_array($type,['matching','ordering'],true)){$validOptions=array_values(array_filter($options,static fn($option)=>trim((string)($option['text']??''))!==''));if(count($validOptions)<2)throw new InvalidArgumentException('أضف عنصرين على الأقل لسؤال التوصيل أو الترتيب.');if($type==='matching'&&count(array_filter($validOptions,static fn($option)=>trim((string)($option['match_value']??''))!==''))<2)throw new InvalidArgumentException('أدخل الطرف المطابق لكل عنصر في سؤال التوصيل.');}
    $answerKeyInput=$data['answer_key']??null;if(is_string($answerKeyInput)){ $parsed=json_decode($answerKeyInput,true);if(is_array($parsed))$answerKeyInput=$parsed; }
    if(in_array($type,['short_answer','fill_blank'],true)){$accepted=is_array($answerKeyInput)?($answerKeyInput['accepted_answers']??$answerKeyInput['answers']??[]):$answerKeyInput;if(!is_array($accepted))$accepted=[$accepted];if(!array_filter($accepted,static fn($value)=>trim((string)$value)!==''))throw new InvalidArgumentException('أدخل إجابة نموذجية واحدة على الأقل للتصحيح الآلي.');}
    $tags = array_values(array_unique(array_filter(array_map('trim', is_array($data['tags'] ?? null) ? $data['tags'] : explode(',', (string)($data['tags'] ?? ''))))));
    $answerKey = $answerKeyInput;
    if (is_array($answerKey)) $answerKey = json_encode($answerKey, JSON_UNESCAPED_UNICODE);
    $attachments = $data['attachments'] ?? [];
    if (!is_array($attachments)) $attachments = [];

    if ($existingId) {
        $existing = assessment_question($pdo, $existingId, $actorId, $isAdmin);
        if (!$existing) throw new DomainException('السؤال غير موجود أو لا تملك صلاحية تعديله.');
        $used = $pdo->prepare('SELECT COUNT(*) FROM quiz_attempt_questions WHERE question_id=?');
        $used->execute([$existingId]);
        if ((int)$used->fetchColumn() > 0) throw new DomainException('هذا السؤال دخل في محاولات طلاب؛ انسخه من بنك الأسئلة ثم عدّل النسخة للحفاظ على سجل النتائج.');
        $stmt = $pdo->prepare('UPDATE questions SET question_text=?, question_type=?, points=?, difficulty=?, explanation=?, course_id=?, specialty=?, tags_json=?, image_path=?, attachments_json=?, answer_key_json=?, grading_rubric=?, status=?, updated_at=NOW() WHERE id=?');
        $stmt->execute([$text,$type,$points,$difficulty,trim((string)($data['explanation'] ?? '')),$courseId,trim((string)($data['specialty'] ?? '')) ?: null,json_encode($tags,JSON_UNESCAPED_UNICODE),trim((string)($data['image_path'] ?? '')) ?: null,json_encode($attachments,JSON_UNESCAPED_UNICODE),$answerKey,trim((string)($data['grading_rubric'] ?? '')) ?: null,$status,$existingId]);
        $questionId = $existingId;
    } else {
        $legacyQuizId = (int)($data['quiz_id'] ?? 0) ?: null;
        if ($legacyQuizId && !assessment_can_quiz($pdo, $legacyQuizId, $actorId, $isAdmin)) throw new DomainException('لا تملك صلاحية إضافة السؤال للاختبار.');
        $stmt = $pdo->prepare('INSERT INTO questions (quiz_id,created_by,course_id,question_text,question_type,points,difficulty,explanation,specialty,tags_json,image_path,attachments_json,answer_key_json,grading_rubric,status,origin,sort_order,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())');
        $stmt->execute([$legacyQuizId,$actorId,$courseId,$text,$type,$points,$difficulty,trim((string)($data['explanation'] ?? '')),$courseId ? (trim((string)($data['specialty'] ?? '')) ?: null) : (trim((string)($data['specialty'] ?? '')) ?: null),json_encode($tags,JSON_UNESCAPED_UNICODE),trim((string)($data['image_path'] ?? '')) ?: null,json_encode($attachments,JSON_UNESCAPED_UNICODE),$answerKey,trim((string)($data['grading_rubric'] ?? '')) ?: null,$status,(string)($data['origin'] ?? 'manual'),(int)($data['sort_order'] ?? 1)]);
        $questionId = (int)$pdo->lastInsertId();
    }

    if (array_key_exists('options', $data)) {
        $pdo->prepare('DELETE FROM quiz_options WHERE question_id=?')->execute([$questionId]);
        $ins = $pdo->prepare('INSERT INTO quiz_options (question_id,option_text,match_value,image_path,metadata_json,is_correct,sort_order) VALUES (?,?,?,?,?,?,?)');
        $order = 1;
        foreach ($options as $option) {
            $optionText = trim((string)($option['text'] ?? ''));
            if ($optionText === '') continue;
            $meta = is_array($option['metadata'] ?? null) ? json_encode($option['metadata'],JSON_UNESCAPED_UNICODE) : null;
            $ins->execute([$questionId,$optionText,trim((string)($option['match_value'] ?? '')) ?: null,trim((string)($option['image_path'] ?? '')) ?: null,$meta,!empty($option['is_correct']) ? 1 : 0,$order++]);
        }
    }
    return $questionId;
}

$pdo = $pdo ?? null;
$assessmentActor = $assessmentActor ?? [];
$assessmentAdmin = (bool)($assessmentAdmin ?? false);
$actorId = (int)($assessmentActor['id'] ?? 0);
$action = (string)($_GET['action'] ?? '');
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$input = $method === 'POST' ? assessment_body() : [];
if ($action === '' && isset($input['action'])) $action = (string)$input['action'];
if ($actorId <= 0) assessment_reply(401, ['message' => 'انتهت الجلسة. سجّل الدخول مرة أخرى.']);

try {
    if ($method === 'GET' && $action === 'questions') {
        $where = ["q.status <> 'archived'"];
        $params = [];
        if (!$assessmentAdmin) { $where[] = '(q.created_by=? OR EXISTS (SELECT 1 FROM courses c WHERE c.id=q.course_id AND (c.instructor_id=? OR EXISTS (SELECT 1 FROM course_instructors ci WHERE ci.course_id=c.id AND ci.instructor_id=?))))'; array_push($params,$actorId,$actorId,$actorId); }
        if (!empty($_GET['course_id'])) { $where[] = 'q.course_id=?'; $params[] = (int)$_GET['course_id']; }
        if (!empty($_GET['type'])) { $where[] = 'q.question_type=?'; $params[] = (string)$_GET['type']; }
        if (!empty($_GET['difficulty'])) { $where[] = 'q.difficulty=?'; $params[] = (string)$_GET['difficulty']; }
        if (!empty($_GET['status'])) { $where[] = 'q.status=?'; $params[] = (string)$_GET['status']; }
        if (!empty($_GET['search'])) { $where[] = '(q.question_text LIKE ? OR q.specialty LIKE ? OR q.tags_json LIKE ?)'; $term='%'.trim((string)$_GET['search']).'%'; array_push($params,$term,$term,$term); }
        $stmt = $pdo->prepare('SELECT q.*,c.title_ar AS course_title,c.title AS course_title_legacy FROM questions q LEFT JOIN courses c ON c.id=q.course_id WHERE '.implode(' AND ',$where).' ORDER BY q.updated_at DESC,q.id DESC LIMIT 250');
        $stmt->execute($params); $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
        $optionStmt=$pdo->prepare('SELECT id,option_text AS text,match_value,image_path,is_correct,sort_order,metadata_json FROM quiz_options WHERE question_id=? ORDER BY sort_order,id');
        foreach($rows as &$row){$optionStmt->execute([$row['id']]);$row['options']=$optionStmt->fetchAll(PDO::FETCH_ASSOC);$row['tags']=json_decode((string)$row['tags_json'],true) ?: []; $row['answer_key']=json_decode((string)$row['answer_key_json'],true); $row['attachments']=json_decode((string)$row['attachments_json'],true) ?: [];}
        unset($row); assessment_reply(200,['questions'=>$rows]);
    }

    if ($method === 'POST' && $action === 'question_save') {
        $questionId=(int)($input['id'] ?? 0); $pdo->beginTransaction();
        $id=assessment_save_question($pdo,$input,$actorId,$assessmentAdmin,$questionId ?: null);
        assessment_log($pdo,$actorId,$questionId?'question.updated':'question.created','question',$id,['type'=>$input['question_type'] ?? '']);
        $pdo->commit(); assessment_reply(200,['question_id'=>$id,'message'=>'تم حفظ السؤال في بنك الأسئلة.']);
    }

    if ($method === 'POST' && $action === 'question_duplicate') {
        $source=assessment_question($pdo,(int)($input['id']??0),$actorId,$assessmentAdmin);
        if(!$source) throw new DomainException('السؤال غير موجود أو لا تملك صلاحية نسخه.');
        $options=$pdo->prepare('SELECT option_text AS text,match_value,image_path,is_correct,sort_order FROM quiz_options WHERE question_id=? ORDER BY sort_order,id');$options->execute([$source['id']]);
        $copy=$source; unset($copy['id'],$copy['quiz_id']); $copy['quiz_id']=null;$copy['status']='draft';$copy['origin']='manual';$copy['question_text']=$source['question_text'].' (نسخة)';$copy['options']=$options->fetchAll(PDO::FETCH_ASSOC);$copy['tags']=json_decode((string)$source['tags_json'],true) ?: [];$copy['attachments']=json_decode((string)$source['attachments_json'],true) ?: [];$copy['answer_key']=json_decode((string)$source['answer_key_json'],true);
        $pdo->beginTransaction();$id=assessment_save_question($pdo,$copy,$actorId,$assessmentAdmin);assessment_log($pdo,$actorId,'question.duplicated','question',$id,['source_id'=>(int)$source['id']]);$pdo->commit();assessment_reply(201,['question_id'=>$id,'message'=>'تم إنشاء نسخة كمسودة.']);
    }

    if ($method === 'POST' && $action === 'question_archive') {
        $id=(int)($input['id']??0);if(!assessment_question($pdo,$id,$actorId,$assessmentAdmin))throw new DomainException('السؤال غير موجود أو لا تملك صلاحية أرشفته.');
        $pdo->prepare("UPDATE questions SET status='archived',updated_at=NOW() WHERE id=?")->execute([$id]);assessment_log($pdo,$actorId,'question.archived','question',$id);assessment_reply(200,['message'=>'تمت أرشفة السؤال مع الحفاظ على محاولات الطلاب.']);
    }

    if ($method === 'POST' && $action === 'question_import') {
        $items=is_array($input['questions']??null)?$input['questions']:[];if(!$items||count($items)>200)throw new InvalidArgumentException('اختر ملف أسئلة صالحًا يحتوي على 1 إلى 200 سؤال.');$pdo->beginTransaction();$created=[];foreach($items as $item){if(!is_array($item))continue;$item['status']='draft';$item['origin']=in_array(($item['origin']??''),['ai','manual'],true)?$item['origin']:'import';$created[]=assessment_save_question($pdo,$item,$actorId,$assessmentAdmin); }assessment_log($pdo,$actorId,'question.imported','question',null,['count'=>count($created)]);$pdo->commit();assessment_reply(201,['question_ids'=>$created,'message'=>'تم استيراد الأسئلة كمسودات؛ راجعها قبل إضافتها لاختبار منشور.']);
    }

    if ($method === 'GET' && $action === 'exams') {
        $params=[];$where='1=1';if(!$assessmentAdmin){$where='(z.created_by=? OR EXISTS (SELECT 1 FROM courses c WHERE c.id=z.course_id AND c.instructor_id=?))';$params=[$actorId,$actorId];}
        $stmt=$pdo->prepare("SELECT z.*,COALESCE(c.title_ar,c.title) AS course_title,(SELECT COUNT(*) FROM quiz_question_map m WHERE m.quiz_id=z.id) AS mapped_question_count,(SELECT COUNT(*) FROM quiz_attempts a WHERE a.quiz_id=z.id) AS attempt_count FROM quizzes z JOIN courses c ON c.id=z.course_id WHERE $where ORDER BY z.updated_at DESC,z.id DESC");$stmt->execute($params);assessment_reply(200,['exams'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($method === 'GET' && $action === 'exam') {
        $quiz=assessment_can_quiz($pdo,(int)($_GET['id']??0),$actorId,$assessmentAdmin);if(!$quiz)throw new DomainException('الاختبار غير موجود أو لا تملك صلاحية عرضه.');
        $q=$pdo->prepare('SELECT q.*,m.sort_order AS exam_sort_order,m.points_override FROM quiz_question_map m JOIN questions q ON q.id=m.question_id WHERE m.quiz_id=? ORDER BY m.sort_order,m.id');$q->execute([$quiz['id']]);$quiz['questions']=$q->fetchAll(PDO::FETCH_ASSOC);
        $g=$pdo->prepare('SELECT group_id FROM quiz_group_map WHERE quiz_id=?');$g->execute([$quiz['id']]);$quiz['group_ids']=array_map('intval',$g->fetchAll(PDO::FETCH_COLUMN));
        assessment_reply(200,['exam'=>$quiz]);
    }

    if ($method === 'POST' && $action === 'exam_save') {
        $dateValue=static function($value): ?string { $value=trim((string)$value);if($value==='')return null;$time=strtotime(str_replace('T',' ',$value));if($time===false)throw new InvalidArgumentException('أحد مواعيد الاختبار غير صالح.');return date('Y-m-d H:i:s',$time); };
        $id=(int)($input['id']??0);$courseId=(int)($input['course_id']??0);$title=trim((string)($input['title_ar']??$input['title']??''));$titleEn=trim((string)($input['title_en']??''));$description=trim((string)($input['description_ar']??$input['description']??''));$status=(string)($input['status']??'draft');$duration=(int)($input['time_limit_minutes']??0);$maxAttempts=max(1,min(20,(int)($input['max_attempts']??1)));$pass=(float)($input['pass_percent']??60);$release=(string)($input['result_release']??'immediate');$start=$dateValue($input['starts_at']??'');$end=$dateValue($input['ends_at']??'');$resultsAt=$dateValue($input['results_visible_at']??'');
        if(!$courseId||!assessment_can_course($pdo,$courseId,$actorId,$assessmentAdmin))throw new DomainException('اختر كورسًا تملك صلاحية إدارته.');if($title===''||strlen($title)>500)throw new InvalidArgumentException('عنوان الاختبار مطلوب وبحد أقصى 500 حرف.');if($duration<0||$duration>600)throw new InvalidArgumentException('مدة الاختبار يجب أن تكون من 0 إلى 600 دقيقة.');if($pass<0||$pass>100)throw new InvalidArgumentException('درجة النجاح غير صالحة.');if(!in_array($status,['draft','published','archived'],true)||!in_array($release,['immediate','manual','scheduled'],true))throw new InvalidArgumentException('إعدادات نشر الاختبار غير صالحة.');if($start&&$end&&strtotime($end)<=strtotime($start))throw new InvalidArgumentException('موعد نهاية الاختبار يجب أن يأتي بعد بدايته.');
        if($id){$old=assessment_can_quiz($pdo,$id,$actorId,$assessmentAdmin);if(!$old)throw new DomainException('الاختبار غير موجود أو لا تملك صلاحية تعديله.');}
        $questionIds=array_values(array_unique(array_map('intval',is_array($input['question_ids']??null)?$input['question_ids']:[])));
        $pdo->beginTransaction();
        if($id){$stmt=$pdo->prepare('UPDATE quizzes SET course_id=?,title=?,title_ar=?,title_en=?,description=?,description_ar=?,time_limit_minutes=?,max_attempts=?,pass_percent=?,pass_score=?,total_points=0,randomize_questions=?,shuffle_questions=?,randomize_options=?,shuffle_options=?,random_question_count=?,negative_marking=?,auto_submit=?,navigation_mode=?,show_answers_after_submit=?,result_release=?,results_visible_at=?,starts_at=?,ends_at=?,status=?,is_published=?,updated_at=NOW() WHERE id=?');$stmt->execute([$courseId,$title,$title,$titleEn,$description,$description,$duration?:null,$maxAttempts,$pass,$pass,(int)!empty($input['shuffle_questions']),(int)!empty($input['shuffle_questions']),(int)!empty($input['shuffle_options']),(int)!empty($input['shuffle_options']),(int)($input['random_question_count']??0),max(0,(float)($input['negative_marking']??0)),isset($input['auto_submit'])?(int)(bool)$input['auto_submit']:1,($input['navigation_mode']??'free')==='sequential'?'sequential':'free',(int)!empty($input['show_answers_after_submit']),$release,$resultsAt,$start,$end,$status,(int)($status==='published'),$id]);$quizId=$id;
        }else{$stmt=$pdo->prepare('INSERT INTO quizzes (course_id,title,title_ar,title_en,description,description_ar,time_limit_minutes,max_attempts,pass_percent,pass_score,total_points,randomize_questions,shuffle_questions,randomize_options,shuffle_options,random_question_count,negative_marking,auto_submit,navigation_mode,show_answers_after_submit,result_release,results_visible_at,starts_at,ends_at,status,is_published,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,0,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())');$stmt->execute([$courseId,$title,$title,$titleEn,$description,$description,$duration?:null,$maxAttempts,$pass,$pass,(int)!empty($input['shuffle_questions']),(int)!empty($input['shuffle_questions']),(int)!empty($input['shuffle_options']),(int)!empty($input['shuffle_options']),(int)($input['random_question_count']??0),max(0,(float)($input['negative_marking']??0)),isset($input['auto_submit'])?(int)(bool)$input['auto_submit']:1,($input['navigation_mode']??'free')==='sequential'?'sequential':'free',(int)!empty($input['show_answers_after_submit']),$release,$resultsAt,$start,$end,$status,(int)($status==='published'),$actorId]);$quizId=(int)$pdo->lastInsertId();}
        if(array_key_exists('question_ids',$input)){
            $pdo->prepare('DELETE FROM quiz_question_map WHERE quiz_id=?')->execute([$quizId]);$sort=1;$total=0;
            foreach($questionIds as $questionId){$question=assessment_question($pdo,$questionId,$actorId,$assessmentAdmin);if(!$question||($question['status']==='archived'))throw new DomainException('يوجد سؤال غير متاح ضمن الاختيارات.');if($question['course_id']!==null&&(int)$question['course_id']!==$courseId)throw new DomainException('لا يمكن إضافة سؤال تابع لكورس مختلف.');if($status==='published'&&$question['status']!=='approved')throw new DomainException('لا يمكن نشر الاختبار قبل اعتماد كل أسئلته.');if(!assessment_can_course($pdo,$courseId,$actorId,$assessmentAdmin))throw new DomainException('لا تملك صلاحية هذا الكورس.');$points=max(.01,(float)($question['points']??1));$pdo->prepare('INSERT INTO quiz_question_map (quiz_id,question_id,sort_order,points_override) VALUES (?,?,?,?)')->execute([$quizId,$questionId,$sort,null]);$total+=$points;$sort++;}
            $pdo->prepare('UPDATE quizzes SET total_points=? WHERE id=?')->execute([$total,$quizId]);
        }
        if($status==='published'){$count=$pdo->prepare('SELECT COUNT(*) FROM quiz_question_map WHERE quiz_id=?');$count->execute([$quizId]);if((int)$count->fetchColumn()<1)throw new DomainException('أضف سؤالًا معتمدًا واحدًا على الأقل قبل نشر الاختبار.');}
        $groupIds=array_values(array_unique(array_map('intval',is_array($input['group_ids']??null)?$input['group_ids']:[])));$pdo->prepare('DELETE FROM quiz_group_map WHERE quiz_id=?')->execute([$quizId]);foreach($groupIds as $groupId){$check=$pdo->prepare('SELECT id FROM assessment_groups WHERE id=? AND course_id=?');$check->execute([$groupId,$courseId]);if(!$check->fetchColumn())throw new DomainException('مجموعة الطلاب المحددة غير تابعة لهذا الكورس.');$pdo->prepare('INSERT INTO quiz_group_map (quiz_id,group_id) VALUES (?,?)')->execute([$quizId,$groupId]);}
        assessment_log($pdo,$actorId,$id?'exam.updated':'exam.created','quiz',$quizId,['status'=>$status,'questions'=>count($questionIds)]);$pdo->commit();assessment_reply($id?200:201,['quiz_id'=>$quizId,'message'=>$status==='published'?'تم حفظ الاختبار ونشره.':'تم حفظ الاختبار كمسودة.']);
    }

    if ($method === 'POST' && $action === 'exam_archive') {
        $id=(int)($input['id']??0);if(!assessment_can_quiz($pdo,$id,$actorId,$assessmentAdmin))throw new DomainException('الاختبار غير موجود أو لا تملك صلاحية أرشفته.');$pdo->prepare("UPDATE quizzes SET status='archived',is_published=0,updated_at=NOW() WHERE id=?")->execute([$id]);assessment_log($pdo,$actorId,'exam.archived','quiz',$id);assessment_reply(200,['message'=>'تمت أرشفة الاختبار مع الحفاظ على محاولاته.']);
    }

    if ($method === 'GET' && in_array($action, ['attempts','attempt'], true)) {
        $quizId=(int)($_GET['quiz_id']??0);$quiz=assessment_can_quiz($pdo,$quizId,$actorId,$assessmentAdmin);if(!$quiz)throw new DomainException('الاختبار غير موجود أو لا تملك صلاحية عرضه.');
        if($action==='attempts'){$stmt=$pdo->prepare('SELECT a.id,a.quiz_id,a.student_id,a.attempt_number,a.started_at,a.submitted_at,a.time_spent_seconds,a.score,a.max_score,a.percent_score,a.passed,a.status,a.results_released_at,a.visibility_loss_count,u.full_name AS student_name,u.email AS student_email FROM quiz_attempts a JOIN users u ON u.id=a.student_id WHERE a.quiz_id=? ORDER BY a.id DESC LIMIT 500');$stmt->execute([$quizId]);$attempts=$stmt->fetchAll(PDO::FETCH_ASSOC);$stats=$pdo->prepare("SELECT COUNT(*) AS attempts,ROUND(AVG(percent_score),1) AS average_score,SUM(CASE WHEN passed=1 THEN 1 ELSE 0 END) AS passed FROM quiz_attempts WHERE quiz_id=? AND status IN ('graded','completed','passed','failed')");$stats->execute([$quizId]);assessment_reply(200,['attempts'=>$attempts,'stats'=>$stats->fetch(PDO::FETCH_ASSOC)]);}
        $attemptId=(int)($_GET['id']??0);$att=$pdo->prepare('SELECT a.*,u.full_name AS student_name,u.email AS student_email FROM quiz_attempts a JOIN users u ON u.id=a.student_id WHERE a.id=? AND a.quiz_id=?');$att->execute([$attemptId,$quizId]);$attempt=$att->fetch(PDO::FETCH_ASSOC);if(!$attempt)throw new DomainException('المحاولة غير موجودة.');$items=$pdo->prepare('SELECT s.question_id,s.sort_order,s.points,s.marked_for_review,q.question_text,q.question_type,q.answer_key_json,q.grading_rubric,ans.answer_json,ans.answer_text,ans.selected_option_id,ans.is_correct,ans.awarded_points,ans.points_earned,ans.lecturer_feedback,ans.grading_status FROM quiz_attempt_questions s JOIN questions q ON q.id=s.question_id LEFT JOIN quiz_answers ans ON ans.attempt_id=s.attempt_id AND ans.question_id=s.question_id WHERE s.attempt_id=? ORDER BY s.sort_order');$items->execute([$attemptId]);$questions=$items->fetchAll(PDO::FETCH_ASSOC);$optionStmt=$pdo->prepare('SELECT id,option_text,match_value,is_correct,sort_order FROM quiz_options WHERE question_id=? ORDER BY sort_order,id');foreach($questions as &$question){$optionStmt->execute([$question['question_id']]);$question['options']=$optionStmt->fetchAll(PDO::FETCH_ASSOC);$question['answer']=json_decode((string)$question['answer_json'],true);$question['answer_key']=json_decode((string)$question['answer_key_json'],true);}unset($question);assessment_reply(200,['attempt'=>$attempt,'questions'=>$questions,'exam'=>$quiz]);
    }

    if ($method === 'POST' && $action === 'grade_answer') {
        $attemptId=(int)($input['attempt_id']??0);$questionId=(int)($input['question_id']??0);$score=(float)($input['points_earned']??0);$feedback=trim((string)($input['feedback']??''));$stmt=$pdo->prepare("SELECT a.quiz_id,a.status,s.points,ans.grading_status FROM quiz_attempts a JOIN quiz_attempt_questions s ON s.attempt_id=a.id LEFT JOIN quiz_answers ans ON ans.attempt_id=a.id AND ans.question_id=s.question_id WHERE a.id=? AND s.question_id=?");$stmt->execute([$attemptId,$questionId]);$row=$stmt->fetch(PDO::FETCH_ASSOC);if(!$row||$row['status']!=='submitted'||$row['grading_status']!=='needs_review'||!assessment_can_quiz($pdo,(int)$row['quiz_id'],$actorId,$assessmentAdmin))throw new DomainException('السؤال لا ينتظر تصحيحًا يدويًا أو لا تملك صلاحية المحاولة.');if($score<0||$score>(float)$row['points'])throw new InvalidArgumentException('الدرجة يجب أن تكون بين صفر ودرجة السؤال.');
        $pdo->beginTransaction();$pdo->prepare("UPDATE quiz_answers SET awarded_points=?,points_earned=?,lecturer_feedback=?,grading_status='approved',graded_by=?,graded_at=NOW() WHERE attempt_id=? AND question_id=? AND grading_status='needs_review'")->execute([$score,$score,$feedback?:null,$actorId,$attemptId,$questionId]);$pending=$pdo->prepare("SELECT COUNT(*) FROM quiz_answers WHERE attempt_id=? AND grading_status='needs_review'");$pending->execute([$attemptId]);$finalized=false;
        if((int)$pending->fetchColumn()===0){$totals=$pdo->prepare('SELECT COALESCE(SUM(s.points),0) AS total,COALESCE(SUM(a.points_earned),0) AS earned FROM quiz_attempt_questions s LEFT JOIN quiz_answers a ON a.attempt_id=s.attempt_id AND a.question_id=s.question_id WHERE s.attempt_id=?');$totals->execute([$attemptId]);$grade=$totals->fetch(PDO::FETCH_ASSOC);$pct=(float)$grade['total']>0?round((float)$grade['earned']/(float)$grade['total']*100,2):0;$pass=$pdo->prepare('SELECT z.pass_percent,z.result_release FROM quiz_attempts a JOIN quizzes z ON z.id=a.quiz_id WHERE a.id=?');$pass->execute([$attemptId]);$policy=$pass->fetch(PDO::FETCH_ASSOC);$passed=(int)($pct>=(float)$policy['pass_percent']);$released=$policy['result_release']==='immediate'?date('Y-m-d H:i:s'):null;$pdo->prepare("UPDATE quiz_attempts SET status='graded',score=?,max_score=?,percent_score=?,passed=?,results_released_at=? WHERE id=?")->execute([$grade['earned'],$grade['total'],$pct,$passed,$released,$attemptId]);$finalized=true;}
        assessment_log($pdo,$actorId,'answer.manually_graded','attempt',$attemptId,['question_id'=>$questionId,'points'=>$score]);$pdo->commit();assessment_reply(200,['message'=>$finalized?'اكتمل التصحيح وتم تحديث النتيجة.':'تم حفظ درجة السؤال وملاحظات المحاضر.','attempt_graded'=>$finalized]);
    }

    if ($method === 'POST' && $action === 'release_results') {
        $quizId=(int)($input['quiz_id']??0);if(!assessment_can_quiz($pdo,$quizId,$actorId,$assessmentAdmin))throw new DomainException('الاختبار غير موجود أو لا تملك صلاحية نشر نتائجه.');$attemptId=(int)($input['attempt_id']??0);if($attemptId){$stmt=$pdo->prepare("UPDATE quiz_attempts SET results_released_at=NOW() WHERE id=? AND quiz_id=? AND status IN ('graded','completed','passed','failed')");$stmt->execute([$attemptId,$quizId]);}else{$stmt=$pdo->prepare("UPDATE quiz_attempts SET results_released_at=NOW() WHERE quiz_id=? AND status IN ('graded','completed','passed','failed')");$stmt->execute([$quizId]);}$count=$stmt->rowCount();assessment_log($pdo,$actorId,'results.released','quiz',$quizId,['attempt_id'=>$attemptId?:null,'updated'=>$count]);assessment_reply(200,['released'=>$count,'message'=>'تم إتاحة النتائج للطلاب.']);
    }

    if ($method === 'GET' && $action === 'report') {
        $quizId=(int)($_GET['quiz_id']??0);if(!assessment_can_quiz($pdo,$quizId,$actorId,$assessmentAdmin))throw new DomainException('الاختبار غير موجود أو لا تملك صلاحية عرضه.');$stmt=$pdo->prepare("SELECT COUNT(*) AS attempts,ROUND(AVG(percent_score),2) AS average_percent,SUM(CASE WHEN passed=1 THEN 1 ELSE 0 END) AS passed,SUM(CASE WHEN passed=0 THEN 1 ELSE 0 END) AS failed,MAX(percent_score) AS highest,MIN(percent_score) AS lowest FROM quiz_attempts WHERE quiz_id=? AND status IN ('graded','completed','passed','failed')");$stmt->execute([$quizId]);$report=$stmt->fetch(PDO::FETCH_ASSOC);$report['pass_rate']=(int)$report['attempts']?round((int)$report['passed']/(int)$report['attempts']*100,1):0;$hard=$pdo->prepare('SELECT q.id,q.question_text,COUNT(a.id) AS answers,SUM(CASE WHEN a.is_correct=0 THEN 1 ELSE 0 END) AS incorrect FROM quiz_question_map m JOIN questions q ON q.id=m.question_id LEFT JOIN quiz_attempt_questions s ON s.question_id=q.id LEFT JOIN quiz_attempts att ON att.id=s.attempt_id AND att.quiz_id=? LEFT JOIN quiz_answers a ON a.attempt_id=att.id AND a.question_id=q.id WHERE m.quiz_id=? GROUP BY q.id ORDER BY incorrect DESC,answers DESC LIMIT 20');$hard->execute([$quizId,$quizId]);$report['question_analysis']=$hard->fetchAll(PDO::FETCH_ASSOC);assessment_reply(200,['report'=>$report]);
    }

    if ($method === 'GET' && $action === 'groups') {
        $courseId=(int)($_GET['course_id']??0);if(!$courseId||!assessment_can_course($pdo,$courseId,$actorId,$assessmentAdmin))throw new DomainException('لا تملك صلاحية إدارة مجموعات هذا الكورس.');$stmt=$pdo->prepare('SELECT g.*,COUNT(m.student_id) AS member_count FROM assessment_groups g LEFT JOIN assessment_group_members m ON m.group_id=g.id WHERE g.course_id=? GROUP BY g.id ORDER BY g.name');$stmt->execute([$courseId]);assessment_reply(200,['groups'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($method === 'GET' && $action === 'course_students') {
        $courseId=(int)($_GET['course_id']??0);if(!$courseId||!assessment_can_course($pdo,$courseId,$actorId,$assessmentAdmin))throw new DomainException('لا تملك صلاحية عرض طلاب هذا الكورس.');$cols=$pdo->query('SHOW COLUMNS FROM enrollments')->fetchAll(PDO::FETCH_COLUMN);$access=in_array('access_status',$cols,true)?"AND (e.access_status='granted' OR e.access_status IS NULL)":'';$stmt=$pdo->prepare("SELECT DISTINCT u.id,u.full_name,u.email FROM enrollments e JOIN users u ON u.id=e.student_id WHERE e.course_id=? AND e.status IN ('active','completed') $access AND u.role='student' ORDER BY u.full_name LIMIT 1000");$stmt->execute([$courseId]);assessment_reply(200,['students'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($method === 'GET' && $action === 'group_members') {
        $groupId=(int)($_GET['group_id']??0);$stmt=$pdo->prepare('SELECT course_id FROM assessment_groups WHERE id=?');$stmt->execute([$groupId]);$courseId=(int)$stmt->fetchColumn();if(!$courseId||!assessment_can_course($pdo,$courseId,$actorId,$assessmentAdmin))throw new DomainException('المجموعة غير موجودة أو لا تملك صلاحية عرضها.');$members=$pdo->prepare('SELECT student_id FROM assessment_group_members WHERE group_id=?');$members->execute([$groupId]);assessment_reply(200,['student_ids'=>array_map('intval',$members->fetchAll(PDO::FETCH_COLUMN))]);
    }

    if ($method === 'POST' && $action === 'group_save') {
        $id=(int)($input['id']??0);$courseId=(int)($input['course_id']??0);$name=trim((string)($input['name']??''));if(!$courseId||!assessment_can_course($pdo,$courseId,$actorId,$assessmentAdmin))throw new DomainException('لا تملك صلاحية هذا الكورس.');if($name===''||strlen($name)>180)throw new InvalidArgumentException('اسم المجموعة مطلوب.');if($id){$stmt=$pdo->prepare('UPDATE assessment_groups SET name=?,description=? WHERE id=? AND course_id=?');$stmt->execute([$name,trim((string)($input['description']??''))?:null,$id,$courseId]);$groupId=$id;}else{$stmt=$pdo->prepare('INSERT INTO assessment_groups (course_id,name,description,created_by) VALUES (?,?,?,?)');$stmt->execute([$courseId,$name,trim((string)($input['description']??''))?:null,$actorId]);$groupId=(int)$pdo->lastInsertId();}assessment_reply(200,['group_id'=>$groupId,'message'=>'تم حفظ المجموعة.']);
    }

    if ($method === 'POST' && $action === 'group_members') {
        $groupId=(int)($input['group_id']??0);$members=array_values(array_unique(array_filter(array_map('intval',is_array($input['student_ids']??null)?$input['student_ids']:[]))));$stmt=$pdo->prepare('SELECT g.course_id FROM assessment_groups g WHERE g.id=?');$stmt->execute([$groupId]);$courseId=(int)$stmt->fetchColumn();if(!$courseId||!assessment_can_course($pdo,$courseId,$actorId,$assessmentAdmin))throw new DomainException('المجموعة غير موجودة أو لا تملك صلاحية تعديلها.');$cols=$pdo->query('SHOW COLUMNS FROM enrollments')->fetchAll(PDO::FETCH_COLUMN);$access=in_array('access_status',$cols,true)?"AND (e.access_status='granted' OR e.access_status IS NULL)":'';$pdo->beginTransaction();$pdo->prepare('DELETE FROM assessment_group_members WHERE group_id=?')->execute([$groupId]);$ins=$pdo->prepare("INSERT IGNORE INTO assessment_group_members (group_id,student_id) SELECT ?,e.student_id FROM enrollments e JOIN users u ON u.id=e.student_id WHERE e.course_id=? AND e.status IN ('active','completed') $access AND u.role='student' AND e.student_id=?");foreach($members as $studentId)$ins->execute([$groupId,$courseId,$studentId]);$pdo->commit();assessment_reply(200,['message'=>'تم تحديث أعضاء المجموعة.']);
    }

    assessment_reply(404,['message'=>'إجراء إدارة التقييمات غير معروف.']);
} catch (InvalidArgumentException $e) {
    if($pdo->inTransaction())$pdo->rollBack();assessment_reply(422,['message'=>$e->getMessage()]);
} catch (DomainException $e) {
    if($pdo->inTransaction())$pdo->rollBack();assessment_reply(403,['message'=>$e->getMessage()]);
} catch (Throwable $e) {
    if($pdo->inTransaction())$pdo->rollBack();error_log('[Assessment management] '.$e->getMessage());assessment_reply(500,['message'=>'تعذر إكمال العملية حاليًا. راجع سجل الخادم.']);
}
