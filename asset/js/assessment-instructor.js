'use strict';

(() => {
  const apiBase = 'api/instructor/assessments.php';
  const lang = () => localStorage.getItem('nbc_instructor_lang') === 'en' ? 'en' : 'ar';
  const text = (ar, en) => lang() === 'en' ? en : ar;
  const $id = id => document.getElementById(id);
  let bank = [];
  let bankTimer;
  let examGroups=[];
  let activeAttemptsQuizId=0;
  let latestAttempts=[];
  let generatedDrafts=[];
  let aiChatHistory=[];
  const types = {
    multiple_choice: ['اختيار واحد','Single choice'], mcq: ['اختيار واحد','Single choice'], multiple_select: ['اختيارات متعددة','Multiple select'],
    true_false: ['صح أو خطأ','True / False'], short_answer: ['إجابة قصيرة','Short answer'], essay: ['مقالي','Essay'],
    matching: ['توصيل','Matching'], fill_blank: ['أكمل الفراغ','Fill in the blank'], ordering: ['ترتيب وتسلسل','Ordering'],
    clinical_case: ['حالة سريرية','Clinical case'], image_based: ['سؤال بصري','Image question']
  };
  const statuses = { draft:['مسودة','Draft'], approved:['معتمد','Approved'], archived:['مؤرشف','Archived'] };
  const difficultyNames = { easy:['سهل','Easy'], medium:['متوسط','Medium'], hard:['صعب','Hard'] };
  const opts = (arr, selected = '') => arr.map(x => `<option value="${x[0]}" ${x[0]===selected?'selected':''}>${x[1]}</option>`).join('');
  const qs = (selector, root=document) => [...root.querySelectorAll(selector)];

  async function call(action, data = null, method = 'GET') {
    const url = `${apiBase}?action=${encodeURIComponent(action)}`;
    return requestJSON(url, data === null ? { method } : { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({ action, ...data }) });
  }

  function populateCourses() {
    const courses = Array.isArray(cachedCourses) ? cachedCourses : [];
    const filter=$id('bankCourseFilter'), modal=$id('bankQuestionCourse'), quiz=$id('quizModalCourse'), ai=$id('assessmentAiCourse');
    if(filter){const old=filter.value;filter.innerHTML=`<option value="">${text('كل الكورسات','All courses')}</option>`+courses.map(c=>`<option value="${Number(c.id)}">${esc(c.title||c.title_ar||'')}</option>`).join('');filter.value=old;}
    if(modal){const old=modal.value;modal.innerHTML=`<option value="">${text('عام / غير مرتبط بكورس','General / no course')}</option>`+courses.map(c=>`<option value="${Number(c.id)}">${esc(c.title||c.title_ar||'')}</option>`).join('');modal.value=old;}
    if(quiz&&courses.length){const old=quiz.value;quiz.innerHTML=`<option value="">${text('اختر الكورس...','Select a course...')}</option>`+courses.map(c=>`<option value="${Number(c.id)}">${esc(c.title||c.title_ar||'')}</option>`).join('');quiz.value=old;}
    if(ai){const old=ai.value;ai.innerHTML=`<option value="">${text('عام','General')}</option>`+courses.map(c=>`<option value="${Number(c.id)}">${esc(c.title||c.title_ar||'')}</option>`).join('');ai.value=old;}
  }

  function queryParams() {
    const p=new URLSearchParams();
    [['search','bankSearch'],['type','bankTypeFilter'],['difficulty','bankDifficultyFilter'],['status','bankStatusFilter'],['course_id','bankCourseFilter']].forEach(([key,id])=>{const v=$id(id)?.value?.trim();if(v)p.set(key,v);});
    return p.toString();
  }

  async function loadQuestionBank() {
    const list=$id('questionBankList');if(!list)return;
    list.innerHTML=`<div class="surface assessment-empty">${text('جارٍ تحميل الأسئلة...','Loading questions...')}</div>`;
    try {
      if(!Array.isArray(cachedCourses)||!cachedCourses.length) await loadCourses();
      populateCourses();
      const result=await requestJSON(`${apiBase}?action=questions&${queryParams()}`);
      bank=Array.isArray(result.questions)?result.questions:[];
      renderBank();
    } catch(error) {list.innerHTML=`<div class="surface assessment-empty is-error">${esc(error.message||text('تعذر تحميل بنك الأسئلة.','Could not load the question bank.'))}</div>`;}
  }

  function renderBank() {
    const list=$id('questionBankList'),count=$id('questionBankCount');if(!list)return;
    if(count)count.textContent=text(`${bank.length} سؤال في بنك الأسئلة`,`${bank.length} questions in the bank`);
    if(!bank.length){list.innerHTML=`<div class="surface assessment-empty"><strong>${text('لا توجد أسئلة مطابقة','No matching questions')}</strong><span>${text('أضف سؤالًا جديدًا أو غيّر عوامل التصفية.','Add a question or adjust the filters.')}</span></div>`;return;}
    list.innerHTML=bank.map(q=>{
      const type=types[q.question_type]||types.multiple_choice,status=statuses[q.status]||statuses.draft,difficulty=difficultyNames[q.difficulty]||difficultyNames.medium;
      const tags=Array.isArray(q.tags)?q.tags:[];
      const picture=q.image_path?`<img class="assessment-card-image" src="${esc(q.image_path)}" alt="" loading="lazy">`:'';
      return `<article class="assessment-question-card surface">
        <div class="assessment-question-meta"><span class="assessment-chip">${text(...type)}</span><span class="assessment-chip difficulty-${esc(q.difficulty)}">${text(...difficulty)}</span><span class="assessment-state state-${esc(q.status)}">${text(...status)}</span></div>
        ${picture}<h3>${esc(q.question_text)}</h3>
        <div class="assessment-question-sub"><span>${esc(q.course_title||q.course_title_legacy||text('عام','General'))}</span><span>${Number(q.points||1)} ${text('درجة','pts')}</span></div>
        ${q.specialty?`<small class="assessment-specialty">${esc(q.specialty)}</small>`:''}
        ${tags.length?`<div class="assessment-tags">${tags.slice(0,8).map(tag=>`<span>#${esc(tag)}</span>`).join('')}</div>`:''}
        <div class="assessment-question-actions"><button class="btn btn-sm btn-outline" data-question-edit="${Number(q.id)}">${text('تعديل','Edit')}</button><button class="btn btn-sm btn-ghost" data-question-duplicate="${Number(q.id)}">${text('نسخ','Duplicate')}</button><button class="btn btn-sm btn-danger" data-question-archive="${Number(q.id)}">${text('أرشفة','Archive')}</button></div>
      </article>`;
    }).join('');
  }

  function optionRow(value={}, index=0) {
    const type=$id('bankQuestionType')?.value||'multiple_choice';
    const multi=type==='multiple_select';
    const isMatch=type==='matching';
    return `<div class="assessment-option-row" data-option-row>
      <span class="assessment-option-index">${index+1}</span>
      <input class="assessment-option-text" type="text" maxlength="2000" placeholder="${text('نص الاختيار / الطرف الأول','Option / left-side text')}" value="${esc(value.text||'')}">
      ${isMatch?`<input class="assessment-option-match" type="text" placeholder="${text('الطرف المطابق','Matching pair')}" value="${esc(value.match_value||'')}">`:''}
      <label class="assessment-correct"><input class="assessment-option-correct" type="checkbox" ${value.is_correct?'checked':''} ${multi?'':'data-single-correct'}><span>${text('صحيح','Correct')}</span></label>
      <button type="button" class="assessment-remove-option" aria-label="${text('حذف الاختيار','Remove option')}">×</button>
    </div>`;
  }

  function renderOptionEditor(options=[]) {
    const box=$id('bankQuestionOptions');if(!box)return;
    const type=$id('bankQuestionType')?.value||'multiple_choice';
    const usesOptions=['multiple_choice','mcq','multiple_select','true_false','matching','ordering','image_based'].includes(type);
    box.hidden=!usesOptions;
    if(!usesOptions){box.innerHTML='';return;}
    let values=options;
    if(!values.length) values=type==='true_false'?[{text:text('صح','True'),is_correct:true},{text:text('خطأ','False'),is_correct:false}]:[{},{},{},{}];
    box.innerHTML=values.map(optionRow).join('');
  }

  function getAnswerKey() {
    const raw=$id('bankQuestionAnswerKey')?.value?.trim()||'';if(!raw)return null;
    try{return JSON.parse(raw);}catch(_){return {accepted_answers:raw.split(/\r?\n/).map(x=>x.trim()).filter(Boolean)};}
  }

  function readFormOptions() {
    return qs('[data-option-row]',$id('bankQuestionOptions')).map((row,index)=>({
      text:row.querySelector('.assessment-option-text')?.value.trim()||'',
      match_value:row.querySelector('.assessment-option-match')?.value.trim()||'',
      is_correct:!!row.querySelector('.assessment-option-correct')?.checked,
      sort_order:index+1
    })).filter(x=>x.text);
  }

  function openQuestionModal(question=null) {
    const form=$id('bankQuestionForm');form?.reset();
    $id('bankQuestionId').value=question?.id||'';
    $id('bankQuestionModalTitle').textContent=question?text('تعديل سؤال من بنك الأسئلة','Edit question bank item'):text('إضافة سؤال جديد','Add a question');
    $id('bankQuestionType').value=question?.question_type||'multiple_choice';
    $id('bankQuestionCourse').value=question?.course_id||'';
    $id('bankQuestionSpecialty').value=question?.specialty||'';
    $id('bankQuestionDifficulty').value=question?.difficulty||'medium';
    $id('bankQuestionPoints').value=question?.points||1;
    $id('bankQuestionStatus').value=question?.status==='approved'?'approved':'draft';
    $id('bankQuestionText').value=question?.question_text||'';
    $id('bankQuestionAnswerKey').value=question?.answer_key?JSON.stringify(question.answer_key,null,2):'';
    $id('bankQuestionExplanation').value=question?.explanation||'';
    $id('bankQuestionRubric').value=question?.grading_rubric||'';
    $id('bankQuestionTags').value=Array.isArray(question?.tags)?question.tags.join(', '):'';
    $id('bankQuestionImagePath').value=question?.image_path||'';
    $id('bankQuestionImageState').textContent=question?.image_path?text('تم إرفاق صورة','Image attached'):'';
    $id('bankQuestionFormMessage').hidden=true;
    renderOptionEditor(question?.options||[]);
    $id('bankQuestionModal').hidden=false;
  }

  function closeQuestionModal(){if($id('bankQuestionModal'))$id('bankQuestionModal').hidden=true;}

  async function saveQuestion(event) {
    event.preventDefault();const button=$id('bankQuestionForm')?.querySelector('[type="submit"]');if(button)button.disabled=true;
    const payload={id:Number($id('bankQuestionId').value)||0,question_type:$id('bankQuestionType').value,course_id:Number($id('bankQuestionCourse').value)||null,specialty:$id('bankQuestionSpecialty').value.trim(),difficulty:$id('bankQuestionDifficulty').value,points:Number($id('bankQuestionPoints').value)||1,status:$id('bankQuestionStatus').value,question_text:$id('bankQuestionText').value.trim(),options:readFormOptions(),answer_key:getAnswerKey(),explanation:$id('bankQuestionExplanation').value.trim(),grading_rubric:$id('bankQuestionRubric').value.trim(),tags:$id('bankQuestionTags').value.split(',').map(x=>x.trim()).filter(Boolean),image_path:$id('bankQuestionImagePath').value.trim()};
    try{const result=await call('question_save',payload);closeQuestionModal();notify(result.message,'success');await loadQuestionBank();}
    catch(error){const msg=$id('bankQuestionFormMessage');if(msg){msg.textContent=error.message;msg.hidden=false;msg.className='notice notice-error';}else notify(error.message,'error');}
    finally{if(button)button.disabled=false;}
  }

  async function uploadQuestionImage(){const file=$id('bankQuestionImageFile')?.files?.[0];if(!file){notify(text('اختر صورة أولًا.','Choose an image first.'),'error');return;}const form=new FormData();form.append('file',file);form.append('purpose','image');const button=$id('btnUploadQuestionImage');if(button)button.disabled=true;try{const result=await requestJSON('api/instructor/assessment-upload.php',{method:'POST',body:form});$id('bankQuestionImagePath').value=result.path;$id('bankQuestionImageState').textContent=text('تم رفع الصورة','Image uploaded');}catch(error){notify(error.message,'error');}finally{if(button)button.disabled=false;}}

  function populateQuizQuestionSelection(selected=[]) {
    const box=$id('quizQuestionBankSelection');if(!box)return;
    const courseId=Number($id('quizModalCourse')?.value)||0;
    const eligible=bank.filter(q=>q.status==='approved'&&(!courseId||!q.course_id||Number(q.course_id)===courseId));
    box.innerHTML=eligible.length?eligible.map(q=>`<label class="assessment-select-question"><input type="checkbox" value="${Number(q.id)}" ${selected.includes(Number(q.id))?'checked':''}><span>${esc(q.question_text)}</span><small>${text(...(types[q.question_type]||types.multiple_choice))} · ${Number(q.points||1)} ${text('درجة','pts')}</small></label>`).join(''):`<div>${text('لا توجد أسئلة معتمدة لهذا الكورس. أضف سؤالًا واعتمده أولًا.','No approved questions for this course. Add and approve questions first.')}</div>`;
  }

  function selectedExamQuestionIds(){return qs('#quizQuestionBankSelection input:checked').map(el=>Number(el.value)).filter(Boolean);}

  async function loadExam(id){
    if(!bank.length)await loadQuestionBank();
    const result=await requestJSON(`${apiBase}?action=exam&id=${encodeURIComponent(id)}`);const q=result.exam;
    $id('quizModalStartsAt').value=q.starts_at?String(q.starts_at).replace(' ','T').slice(0,16):'';$id('quizModalEndsAt').value=q.ends_at?String(q.ends_at).replace(' ','T').slice(0,16):'';
    $id('quizModalRandomCount').value=q.random_question_count||0;$id('quizModalNegativeMarking').value=q.negative_marking||0;$id('quizModalResultRelease').value=q.result_release||'immediate';$id('quizModalResultsAt').value=q.results_visible_at?String(q.results_visible_at).replace(' ','T').slice(0,16):'';
    $id('quizModalShuffleQuestions').checked=!!Number(q.shuffle_questions||q.randomize_questions);$id('quizModalShuffleOptions').checked=!!Number(q.shuffle_options||q.randomize_options);$id('quizModalAutoSubmit').checked=!!Number(q.auto_submit??1);$id('quizModalSequential').checked=q.navigation_mode==='sequential';$id('quizModalShowAnswers').checked=!!Number(q.show_answers_after_submit);
    populateQuizQuestionSelection((q.questions||[]).map(item=>Number(item.id)));
    await loadExamGroups(q.group_ids||[]);
  }

  async function loadExamGroups(selected=[]){const box=$id('quizGroupSelection');if(!box)return;const courseId=Number($id('quizModalCourse')?.value)||0;if(!courseId){box.innerHTML=`<span>${text('اختر كورسًا لعرض مجموعاته.','Choose a course to see its groups.')}</span>`;return;}try{const result=await requestJSON(`${apiBase}?action=groups&course_id=${courseId}`);examGroups=result.groups||[];box.innerHTML=examGroups.length?examGroups.map(g=>`<label class="assessment-select-question"><input type="checkbox" data-exam-group value="${Number(g.id)}" ${selected.includes(Number(g.id))?'checked':''}><span>${esc(g.name)}</span><small>${Number(g.member_count||0)} ${text('طالب','students')}</small><button type="button" class="btn btn-sm btn-ghost" data-group-members="${Number(g.id)}">${text('إدارة الأعضاء','Manage members')}</button></label>`).join(''):`<span>${text('لا توجد مجموعات بعد؛ ابدأ بإنشاء مجموعة.','No groups yet. Create one first.')}</span>`;}catch(error){box.innerHTML=`<span class="is-error">${esc(error.message)}</span>`;}}
  async function manageGroupMembers(groupId){const panel=$id('assessmentGroupMembers');try{const group=examGroups.find(g=>Number(g.id)===groupId);if(!group)return;const [studentResult,memberResult]=await Promise.all([requestJSON(`${apiBase}?action=course_students&course_id=${Number($id('quizModalCourse').value)}`),requestJSON(`${apiBase}?action=group_members&group_id=${groupId}`)]);const selected=new Set(memberResult.student_ids||[]);panel.hidden=false;panel.innerHTML=`<strong>${text('أعضاء مجموعة: ','Members of: ')}${esc(group.name)}</strong><div class="assessment-group-student-list">${(studentResult.students||[]).map(s=>`<label><input type="checkbox" value="${Number(s.id)}" ${selected.has(Number(s.id))?'checked':''}><span>${esc(s.full_name)}</span></label>`).join('')||`<span>${text('لا يوجد طلاب مسجلون بهذا الكورس.','No enrolled students in this course.')}</span>`}</div><button type="button" class="btn btn-primary" data-save-group-members="${groupId}">${text('حفظ الأعضاء','Save members')}</button>`;}catch(error){notify(error.message,'error');}}
  async function loadAttempts(quizId){activeAttemptsQuizId=Number(quizId);const result=await requestJSON(`${apiBase}?action=attempts&quiz_id=${Number(quizId)}`);latestAttempts=result.attempts||[];const body=$id('quizAttemptsTableBody');if(!body)return;body.innerHTML=latestAttempts.length?latestAttempts.map(a=>`<tr><td><strong>${esc(a.student_name)}</strong><br><small>${esc(a.student_email)}</small></td><td>${Number(a.attempt_number)}</td><td>${a.score===null?'—':`${Number(a.score).toFixed(1)} / ${Number(a.max_score).toFixed(1)}`}</td><td>${a.percent_score===null?'—':`${Number(a.percent_score).toFixed(1)}%`}</td><td>${esc(a.status)}${Number(a.visibility_loss_count)?` · ${text('مغادرة الشاشة','focus loss')} ${Number(a.visibility_loss_count)}`:''}</td><td>${esc(a.submitted_at||a.started_at||'—')}<div class="assessment-row-actions"><button type="button" class="btn btn-sm btn-outline" data-view-attempt="${Number(a.id)}" data-quiz-id="${Number(quizId)}">${text('مراجعة / تصحيح','Review / grade')}</button>${['graded','completed','passed','failed'].includes(a.status)&&!a.results_released_at?`<button type="button" class="btn btn-sm btn-ghost" data-release-attempt="${Number(a.id)}" data-quiz-id="${Number(quizId)}">${text('إتاحة النتيجة','Release result')}</button>`:''}</div></td></tr>`).join(''):`<tr><td colspan="6">${text('لا توجد محاولات حتى الآن.','No attempts yet.')}</td></tr>`;}
  async function loadReport(){const panel=$id('assessmentReportPanel');try{const result=await requestJSON(`${apiBase}?action=report&quiz_id=${activeAttemptsQuizId}`);const r=result.report;panel.hidden=false;panel.innerHTML=`<div class="assessment-report-stats"><span>${text('عدد المحاولات','Attempts')}<b>${Number(r.attempts||0)}</b></span><span>${text('متوسط الدرجات','Average')}<b>${Number(r.average_percent||0)}%</b></span><span>${text('نسبة النجاح','Pass rate')}<b>${Number(r.pass_rate||0)}%</b></span><span>${text('أعلى درجة','Highest')}<b>${Number(r.highest||0)}%</b></span></div><h3>${text('الأسئلة الأكثر صعوبة','Most difficult questions')}</h3>${(r.question_analysis||[]).map(q=>`<div class="assessment-report-question"><span>${esc(q.question_text)}</span><small>${Number(q.incorrect||0)} / ${Number(q.answers||0)} ${text('إجابة خاطئة','incorrect')}</small></div>`).join('')||`<p>${text('لا توجد بيانات كافية للتحليل بعد.','Not enough data for analysis yet.')}</p>`}`;}catch(error){notify(error.message,'error');}}
  function exportAttempts(){const rows=[[text('الطالب','Student'),text('البريد','Email'),text('المحاولة','Attempt'),text('الدرجة','Score'),text('النسبة','Percent'),text('الحالة','Status'),text('التسليم','Submitted')],...latestAttempts.map(a=>[a.student_name,a.student_email,a.attempt_number,a.score,a.percent_score,a.status,a.submitted_at])];const csv='\uFEFF'+rows.map(row=>row.map(cell=>{let value=String(cell??'').replace(/[\r\n]+/g,' ');if(/^[=+@\-]/.test(value))value="'"+value;return '"'+value.replace(/"/g,'""')+'"';}).join(',')).join('\r\n');const link=document.createElement('a');link.href=URL.createObjectURL(new Blob([csv],{type:'text/csv;charset=utf-8'}));link.download=`assessment-${activeAttemptsQuizId}-attempts.csv`;link.click();setTimeout(()=>URL.revokeObjectURL(link.href),1000);}
  async function printAssessmentReport(){const printWindow=window.open('','_blank','width=1000,height=750');if(!printWindow){notify(text('اسمح بفتح نافذة التقرير للطباعة.','Allow the report window to open.'),'error');return;}try{const result=await requestJSON(`${apiBase}?action=report&quiz_id=${activeAttemptsQuizId}`);const r=result.report;const locale=window.PlatformLanguage?.locale()||(lang()==='en'?'en-US':'ar-EG');const fmt=value=>Number(value??0).toLocaleString(locale);printWindow.document.write(`<!doctype html><html lang="${lang()}" dir="${lang()==='ar'?'rtl':'ltr'}"><meta charset="utf-8"><title>${text('تقرير الاختبار','Exam report')}</title><style>body{font:16px Arial,sans-serif;margin:32px;color:#172033}h1,h2{margin:0 0 16px}header{border-bottom:2px solid #0caa9d;padding-bottom:16px;margin-bottom:20px}.stats{display:flex;gap:12px;margin:20px 0}.stats div{padding:14px;border:1px solid #d9e1ea;border-radius:10px;min-width:120px}table{width:100%;border-collapse:collapse;margin:18px 0}th,td{text-align:start;border:1px solid #d9e1ea;padding:9px}th{background:#eff4f8}@media print{button{display:none}}</style><header><h1>${text('تقرير الاختبار','Exam report')}</h1><p>${text('تاريخ التقرير','Report date')}: ${new Date().toLocaleString(locale)}</p></header><div class="stats"><div>${text('المحاولات','Attempts')}<h2>${fmt(r.attempts)}</h2></div><div>${text('متوسط الدرجات','Average')}<h2>${fmt(r.average_percent)}${lang()==='ar'?'٪':'%'}</h2></div><div>${text('نسبة النجاح','Pass rate')}<h2>${fmt(r.pass_rate)}${lang()==='ar'?'٪':'%'}</h2></div><div>${text('أعلى درجة','Highest')}<h2>${fmt(r.highest)}${lang()==='ar'?'٪':'%'}</h2></div></div><h2>${text('نتائج الطلاب','Student results')}</h2><table><thead><tr><th>${text('الطالب','Student')}</th><th>${text('البريد','Email')}</th><th>${text('المحاولة','Attempt')}</th><th>${text('الدرجة','Score')}</th><th>${text('النسبة','Percent')}</th><th>${text('الحالة','Status')}</th></tr></thead><tbody>${latestAttempts.map(a=>`<tr><td>${esc(a.student_name)}</td><td>${esc(a.student_email)}</td><td>${fmt(a.attempt_number)}</td><td>${a.score===null?'—':`${fmt(a.score)}/${fmt(a.max_score)}`}</td><td>${a.percent_score===null?'—':`${fmt(a.percent_score)}${lang()==='ar'?'٪':'%'}`}</td><td>${esc(a.status)}</td></tr>`).join('')}</tbody></table><h2>${text('الأسئلة الأكثر صعوبة','Most difficult questions')}</h2><table><thead><tr><th>${text('السؤال','Question')}</th><th>${text('إجابات خاطئة','Incorrect answers')}</th><th>${text('الإجمالي','Total')}</th></tr></thead><tbody>${(r.question_analysis||[]).map(q=>`<tr><td>${esc(q.question_text)}</td><td>${fmt(q.incorrect)}</td><td>${fmt(q.answers)}</td></tr>`).join('')}</tbody></table><script>window.onload=()=>window.print()<\/script></html>`);printWindow.document.close();}catch(error){printWindow.close();notify(error.message,'error');}}
  async function openAttempt(quizId,attemptId){try{const result=await requestJSON(`${apiBase}?action=attempt&quiz_id=${Number(quizId)}&id=${Number(attemptId)}`);const {attempt,questions}=result;const body=$id('assessmentGradeBody');$id('assessmentGradeTitle').textContent=`${text('مراجعة محاولة: ','Review attempt: ')}${attempt.student_name}`;$id('assessmentGradeMeta').textContent=`${text('المحاولة','Attempt')} #${attempt.attempt_number} · ${attempt.status} · ${attempt.submitted_at||''}`;body.innerHTML=(questions||[]).map(q=>{const answer=q.answer;const answerText=Array.isArray(answer)?JSON.stringify(answer):answer===null||answer===''?text('لم تتم الإجابة','Not answered'):String(answer);const correct=!!q.is_correct;const needs=q.grading_status==='needs_review';return `<article class="assessment-grade-question"><h3>${esc(q.question_text)}</h3><p><strong>${text('إجابة الطالب:','Student answer:')}</strong> ${esc(answerText)}</p><p><strong>${text('الإجابة النموذجية:','Model answer:')}</strong> ${esc(JSON.stringify(q.answer_key||q.options?.filter(o=>Number(o.is_correct)).map(o=>o.option_text)||''))}</p><div>${text('الدرجة:','Score:')} <strong>${Number(q.points_earned??q.awarded_points??0)} / ${Number(q.points)}</strong> · ${needs?text('بانتظار التصحيح اليدوي','Needs manual grading'):correct?text('صحيح','Correct'):text('غير صحيح','Incorrect')}</div>${needs?`<div class="assessment-grade-controls"><label>${text('الدرجة المعتمدة','Approved points')}<input type="number" min="0" max="${Number(q.points)}" step="0.01" value="0" data-grade-points="${Number(q.question_id)}"></label><label>${text('ملاحظة للطالب','Feedback')}<textarea data-grade-feedback="${Number(q.question_id)}"></textarea></label><div class="assessment-grade-buttons"><button class="btn btn-ghost" type="button" data-ai-grade="${Number(q.question_id)}" data-attempt-id="${Number(attempt.id)}">✨ ${text('اقتراح AI','AI suggestion')}</button><button class="btn btn-primary" type="button" data-grade-answer="${Number(q.question_id)}" data-attempt-id="${Number(attempt.id)}" data-quiz-id="${Number(quizId)}">${text('اعتماد الدرجة','Approve grade')}</button></div><div class="assessment-ai-grade-rationale" data-ai-rationale="${Number(q.question_id)}"></div></div>`:q.lecturer_feedback?`<p>${text('ملاحظة المحاضر:','Instructor feedback:')} ${esc(q.lecturer_feedback)}</p>`:''}</article>`;}).join('')||`<p>${text('لا توجد أسئلة في هذه المحاولة.','No questions in this attempt.')}</p>`;$id('assessmentGradeModal').hidden=false;}catch(error){notify(error.message,'error');}}
  function getExamOptions(){return {question_ids:selectedExamQuestionIds(),group_ids:qs('[data-exam-group]:checked',$id('quizGroupSelection')).map(el=>Number(el.value)),starts_at:$id('quizModalStartsAt').value||null,ends_at:$id('quizModalEndsAt').value||null,random_question_count:Number($id('quizModalRandomCount').value)||0,negative_marking:Number($id('quizModalNegativeMarking').value)||0,result_release:$id('quizModalResultRelease').value,results_visible_at:$id('quizModalResultsAt').value||null,shuffle_questions:$id('quizModalShuffleQuestions').checked,shuffle_options:$id('quizModalShuffleOptions').checked,auto_submit:$id('quizModalAutoSubmit').checked,navigation_mode:$id('quizModalSequential').checked?'sequential':'free',show_answers_after_submit:$id('quizModalShowAnswers').checked};}

  async function handleImport(file){try{const parsed=JSON.parse(await file.text());const questions=Array.isArray(parsed)?parsed:parsed.questions;if(!Array.isArray(questions))throw new Error(text('الملف لازم يحتوي على مصفوفة questions.','JSON must contain a questions array.'));const result=await call('question_import',{questions});notify(result.message,'success');await loadQuestionBank();}catch(error){notify(error.message,'error');}finally{$id('questionImportFile').value='';}}
  function exportBank(){const data=JSON.stringify({format:'edu-nursing-question-bank',version:1,exported_at:new Date().toISOString(),questions:bank.map(q=>({question_text:q.question_text,question_type:q.question_type,points:Number(q.points),difficulty:q.difficulty,specialty:q.specialty,course_id:q.course_id,explanation:q.explanation,answer_key:q.answer_key,grading_rubric:q.grading_rubric,tags:q.tags,options:(q.options||[]).map(o=>({text:o.text,match_value:o.match_value,is_correct:!!Number(o.is_correct)})),image_path:q.image_path}))},null,2);const a=document.createElement('a');a.href=URL.createObjectURL(new Blob([data],{type:'application/json'}));a.download='edu-nursing-question-bank.json';a.click();setTimeout(()=>URL.revokeObjectURL(a.href),1000);}
  async function generateQuestions(){const box=$id('assessmentAiResults'),button=$id('btnGenerateAssessmentQuestions'),file=$id('assessmentAiSourceFile')?.files?.[0],source_text=$id('assessmentAiSourceText').value.trim();if(!file&&!source_text){notify(text('الصق نص المحاضرة أو اختر ملفًا.','Paste lecture text or choose a file.'),'error');return;}button.disabled=true;box.hidden=false;box.innerHTML=`<div class="assessment-empty">${text('جارٍ إعداد المادة وتوليد الأسئلة…','Preparing source and generating questions…')}</div>`;try{let source_path=null;if(file){const fd=new FormData();fd.append('file',file);fd.append('purpose','source');const uploaded=await requestJSON('api/instructor/assessment-upload.php',{method:'POST',body:fd});source_path=uploaded.path;}const result=await requestJSON('api/instructor/assessment-ai-generate.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({source_path,source_text,course_id:Number($id('assessmentAiCourse').value)||null,count:Number($id('assessmentAiCount').value)||10,difficulty:$id('assessmentAiDifficulty').value,language:lang()})});generatedDrafts=(result.questions||[]).map(q=>({...q,status:'draft',origin:'ai',course_id:Number($id('assessmentAiCourse').value)||null,options:Array.isArray(q.options)?q.options:[]}));renderGeneratedDrafts(result.similar_questions||[]);notify(result.message,'success');}catch(error){box.innerHTML=`<div class="notice notice-error">${esc(error.message)}</div>`;}finally{button.disabled=false;}}
  function appendChatMessage(role,content){const box=$id('assessmentAiChatMessages');const item=document.createElement('div');item.className=`assessment-ai-chat-message is-${role}`;item.textContent=content;box.appendChild(item);box.scrollTop=box.scrollHeight;}
  async function openAiChat(){const modal=$id('assessmentAiChatModal');modal.hidden=false;$id('assessmentAiChatInput').focus();try{const status=await requestJSON('api/instructor/assistant.php');const configured=!!status.configured;$id('assessmentAiChatNote').textContent=configured?text('إجابات تعليمية فقط؛ لا تستخدم المحادثة لتوجيه علاج مريض.','Educational guidance only; do not use this chat to direct patient treatment.'):text('المحادثة تحتاج مفتاح Gemini مضبوطًا على الخادم. عرّف NBC_GEMINI_API_KEY في بيئة Apache ثم أعد تشغيله.','Chat needs a Gemini key on the server. Set NBC_GEMINI_API_KEY in Apache’s environment and restart it.');$id('assessmentAiChatInput').disabled=!configured;$id('btnSendAssessmentAiChat').disabled=!configured;}catch(error){$id('assessmentAiChatNote').textContent=error.message;}}
  async function sendAiChat(event){event.preventDefault();const input=$id('assessmentAiChatInput'),button=$id('btnSendAssessmentAiChat'),message=input.value.trim();if(!message||button.disabled)return;aiChatHistory.push({role:'user',content:message});appendChatMessage('user',message);input.value='';button.disabled=true;input.disabled=true;const typing=document.createElement('div');typing.className='assessment-ai-chat-message is-assistant is-typing';typing.textContent=text('المساعد يكتب…','Assistant is typing…');$id('assessmentAiChatMessages').appendChild(typing);try{const result=await requestJSON('api/instructor/assistant.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({messages:aiChatHistory.slice(-12)})});typing.remove();aiChatHistory.push({role:'assistant',content:result.reply});aiChatHistory=aiChatHistory.slice(-12);appendChatMessage('assistant',result.reply);}catch(error){typing.remove();appendChatMessage('assistant',`${text('تعذر الرد: ','Could not reply: ')}${error.message}`);}finally{button.disabled=false;input.disabled=false;input.focus();}}
  function renderGeneratedDrafts(similar=[]){const box=$id('assessmentAiResults');box.hidden=false;box.innerHTML=`${similar.length?`<div class="notice notice-warning">${text('احتمال وجود أسئلة مكررة:','Possible duplicates:')} ${similar.map(x=>`<span>#${Number(x.question_id)} (${Number(x.similarity)}%)</span>`).join('، ')}</div>`:''}<div class="assessment-ai-results-list">${generatedDrafts.map((q,i)=>`<label class="assessment-ai-draft"><input type="checkbox" data-ai-draft="${i}" checked><span><strong>${esc(q.question_text||'')}</strong><small>${esc(q.question_type||'multiple_choice')} · ${esc(q.difficulty||'medium')} · ${Number(q.points||1)} ${text('درجة','points')}</small></span></label>`).join('')}</div><button type="button" class="btn btn-primary" id="btnSaveAiDrafts">${text('حفظ المحدد كمسودات','Save selected as drafts')}</button>`;}
  async function saveGeneratedDrafts(){const selected=qs('[data-ai-draft]:checked',$id('assessmentAiResults')).map(el=>generatedDrafts[Number(el.dataset.aiDraft)]).filter(Boolean);if(!selected.length){notify(text('حدد سؤالًا واحدًا على الأقل.','Select at least one question.'),'error');return;}try{const result=await call('question_import',{questions:selected.map(q=>({...q,status:'draft',origin:'ai'}))});notify(result.message,'success');generatedDrafts=[];$id('assessmentAiResults').hidden=true;await loadQuestionBank();}catch(error){notify(error.message,'error');}}

  function bind(){
    $id('btnNewBankQuestion')?.addEventListener('click',()=>openQuestionModal());$id('btnRefreshQuestionBank')?.addEventListener('click',loadQuestionBank);
    ['bankSearch','bankTypeFilter','bankDifficultyFilter','bankStatusFilter','bankCourseFilter'].forEach(id=>{$id(id)?.addEventListener(id==='bankSearch'?'input':'change',()=>{clearTimeout(bankTimer);bankTimer=setTimeout(loadQuestionBank,id==='bankSearch'?300:0);});});
    $id('bankQuestionForm')?.addEventListener('submit',saveQuestion);$id('bankQuestionType')?.addEventListener('change',()=>renderOptionEditor());
    $id('btnAddQuestionOption')?.addEventListener('click',()=>{const box=$id('bankQuestionOptions');const rows=qs('[data-option-row]',box);box.insertAdjacentHTML('beforeend',optionRow({},rows.length));});
    $id('bankQuestionOptions')?.addEventListener('click',e=>{if(e.target.closest('.assessment-remove-option'))e.target.closest('[data-option-row]').remove();});
    $id('bankQuestionOptions')?.addEventListener('change',e=>{if(e.target.matches('[data-single-correct]')&&e.target.checked)qs('[data-single-correct]',$id('bankQuestionOptions')).filter(x=>x!==e.target).forEach(x=>x.checked=false);});
    $id('btnUploadQuestionImage')?.addEventListener('click',uploadQuestionImage);$id('btnExportQuestionBank')?.addEventListener('click',exportBank);
    $id('questionImportFile')?.addEventListener('change',e=>{if(e.target.files?.[0])handleImport(e.target.files[0]);});
    $id('btnGenerateAssessmentQuestions')?.addEventListener('click',generateQuestions);$id('assessmentAiResults')?.addEventListener('click',e=>{if(e.target.closest('#btnSaveAiDrafts'))saveGeneratedDrafts();});
    $id('btnOpenAssessmentAiChat')?.addEventListener('click',openAiChat);$id('assessmentAiChatForm')?.addEventListener('submit',sendAiChat);$id('assessmentAiChatModal')?.addEventListener('click',e=>{if(e.target===e.currentTarget||e.target.closest('[data-close-ai-chat]'))$id('assessmentAiChatModal').hidden=true;});$id('assessmentAiChatInput')?.addEventListener('keydown',e=>{if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();$id('assessmentAiChatForm').requestSubmit();}});
    $id('questionBankList')?.addEventListener('click',async e=>{
      const edit=e.target.closest('[data-question-edit]'),duplicate=e.target.closest('[data-question-duplicate]'),archive=e.target.closest('[data-question-archive]');
      if(edit){const q=bank.find(item=>Number(item.id)===Number(edit.dataset.questionEdit));if(q)openQuestionModal(q);}
      if(duplicate){try{const result=await call('question_duplicate',{id:Number(duplicate.dataset.questionDuplicate)});notify(result.message,'success');await loadQuestionBank();}catch(error){notify(error.message,'error');}}
      if(archive&&confirm(text('أرشفة السؤال؟ سيظل سجل المحاولات محفوظًا.','Archive this question? Existing attempts remain preserved.'))){try{const result=await call('question_archive',{id:Number(archive.dataset.questionArchive)});notify(result.message,'success');await loadQuestionBank();}catch(error){notify(error.message,'error');}}
    });
    $id('quizModalCourse')?.addEventListener('change',()=>populateQuizQuestionSelection());
    $id('quizModalCourse')?.addEventListener('change',()=>loadExamGroups());
    $id('quizGroupSelection')?.addEventListener('click',async e=>{const btn=e.target.closest('[data-group-members]');if(btn)await manageGroupMembers(Number(btn.dataset.groupMembers));});
    $id('assessmentGroupMembers')?.addEventListener('click',async e=>{const btn=e.target.closest('[data-save-group-members]');if(!btn)return;const student_ids=qs('input[type="checkbox"]',$id('assessmentGroupMembers')).filter(x=>x.checked).map(x=>Number(x.value));try{const result=await call('group_members',{group_id:Number(btn.dataset.saveGroupMembers),student_ids});notify(result.message,'success');await loadExamGroups(qs('[data-exam-group]:checked',$id('quizGroupSelection')).map(x=>Number(x.value)));await manageGroupMembers(Number(btn.dataset.saveGroupMembers));}catch(error){notify(error.message,'error');}});
    $id('btnCreateAssessmentGroup')?.addEventListener('click',async()=>{const course_id=Number($id('quizModalCourse')?.value)||0;if(!course_id){notify(text('اختر الكورس أولًا.','Select a course first.'),'error');return;}const name=prompt(text('اسم المجموعة الجديدة:','New group name:'));if(!name?.trim())return;try{const result=await call('group_save',{course_id,name:name.trim()});notify(result.message,'success');await loadExamGroups([Number(result.group_id)]);}catch(error){notify(error.message,'error');}});
    $id('quizAttemptsTableBody')?.addEventListener('click',async e=>{const view=e.target.closest('[data-view-attempt]'),release=e.target.closest('[data-release-attempt]');if(view)await openAttempt(Number(view.dataset.quizId),Number(view.dataset.viewAttempt));if(release){try{const result=await call('release_results',{quiz_id:Number(release.dataset.quizId),attempt_id:Number(release.dataset.releaseAttempt)});notify(result.message,'success');await loadAttempts(Number(release.dataset.quizId));}catch(error){notify(error.message,'error');}}});
    $id('assessmentGradeBody')?.addEventListener('click',async e=>{const ai=e.target.closest('[data-ai-grade]');if(ai){try{ai.disabled=true;const question_id=Number(ai.dataset.aiGrade),attempt_id=Number(ai.dataset.attemptId),result=await requestJSON('api/instructor/assessment-ai-grade.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({question_id,attempt_id})});$id('assessmentGradeBody').querySelector(`[data-grade-points="${question_id}"]`).value=result.suggestion.suggested_score;$id('assessmentGradeBody').querySelector(`[data-grade-feedback="${question_id}"]`).value=result.suggestion.feedback||'';$id('assessmentGradeBody').querySelector(`[data-ai-rationale="${question_id}"]`).textContent=`${result.message} ${result.suggestion.rationale||''}`;}catch(error){notify(error.message,'error');}finally{ai.disabled=false;}return;}const button=e.target.closest('[data-grade-answer]');if(!button)return;const question_id=Number(button.dataset.gradeAnswer),attempt_id=Number(button.dataset.attemptId),quiz_id=Number(button.dataset.quizId),points_earned=Number($id('assessmentGradeBody').querySelector(`[data-grade-points="${question_id}"]`).value),feedback=$id('assessmentGradeBody').querySelector(`[data-grade-feedback="${question_id}"]`).value;try{const result=await call('grade_answer',{question_id,attempt_id,points_earned,feedback});notify(result.message,'success');await openAttempt(quiz_id,attempt_id);await loadAttempts(quiz_id);}catch(error){notify(error.message,'error');}});
    $id('assessmentGradeModal')?.addEventListener('click',e=>{if(e.target===e.currentTarget||e.target.closest('[data-close-grade]'))$id('assessmentGradeModal').hidden=true;});
    $id('btnAssessmentReport')?.addEventListener('click',loadReport);$id('btnExportAssessmentAttempts')?.addEventListener('click',exportAttempts);$id('btnPrintAssessmentReport')?.addEventListener('click',printAssessmentReport);$id('btnReleaseAssessmentResults')?.addEventListener('click',async()=>{if(!activeAttemptsQuizId||!confirm(text('إتاحة النتائج لكل المحاولات المكتملة؟','Release results for all completed attempts?')))return;try{const result=await call('release_results',{quiz_id:activeAttemptsQuizId});notify(result.message,'success');await loadAttempts(activeAttemptsQuizId);}catch(error){notify(error.message,'error');}});
  }

  window.AssessmentInstructor={loadQuestionBank,openQuestionModal,closeQuestionModal,populateQuizQuestionSelection,selectedExamQuestionIds,getExamOptions,loadExam,loadAttempts};
  document.addEventListener('DOMContentLoaded',bind);
})();
