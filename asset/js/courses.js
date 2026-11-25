(() => {
  'use strict';

  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const API = 'api/admin/courses.php';

  const core = () => window.AdminCore;
  const esc = v => core().escapeHtml(v);
  const say = (m, err = false) => core().notice('#globalMessage', m, err);
  const post = body => core().request(API, { method: 'POST', body: JSON.stringify(body) });
  const locale = () => document.documentElement.lang === 'en' ? 'en-US' : 'ar-EG';
  const num = v => Number(v ?? 0).toLocaleString(locale());
  const money = v => Number(v ?? 0).toLocaleString(locale(), { maximumFractionDigits: 2 });
  const has = v => v !== null && v !== undefined && v !== '';

  const FIELDS = [
    'title', 'title_en', 'slug', 'category', 'level', 'status', 'description',
    'price', 'sale_price', 'duration_minutes', 'seats_limit',
    'start_date', 'end_date', 'prerequisites', 'learning_outcomes',
    'completion_min_progress', 'completion_rules', 'instructor_id'
  ];

  let courses = [];
  let instructors = [];
  let units = [];
  let currentId = 0;
  let removeCover = false;
  let pickedFile = null;
  let books = [];

  const form = () => $('#courseForm');

  // ============================================================
  // القائمة
  // ============================================================
  async function loadInstructors() {
    const data = await core().request(API + '?instructors=1');
    instructors = data.data || [];

    $('#courseInstructor').innerHTML =
      '<option value="">اختر المحاضر</option>' +
      instructors.map(i => `<option value="${esc(i.id)}">${esc(i.full_name)}</option>`).join('');

    $('#courseCoList').innerHTML = instructors.length
      ? instructors.map(i => `
          <label data-co="${esc(i.id)}">
            <input type="checkbox" value="${esc(i.id)}"> ${esc(i.full_name)}
          </label>`).join('')
      : '<small>لا يوجد محاضرون نشطون.</small>';
  }

  async function loadCourses() {
    const params = new URLSearchParams({
      q: $('#courseSearch').value.trim(),
      status: $('#courseStatus').value
    });
    const data = await core().request(API + '?' + params);
    courses = data.data || [];

    $('#coursesTable').innerHTML = courses.length ? `
      <table>
        <thead><tr>
          <th>الكورس</th><th>المحاضرون</th><th>السعر</th>
          <th>المسجلون / المقاعد</th><th>المحاضرات</th><th>الحالة</th><th>إجراءات</th>
        </tr></thead>
        <tbody>${courses.map(rowHtml).join('')}</tbody>
      </table>` :
      '<div style="padding:20px;color:#718096">لا توجد كورسات. يمكنك إنشاء أول كورس.</div>';
  }

  function rowHtml(c) {
    const seats = has(c.seats_limit) ? Number(c.seats_limit) : null;
    const reg = Number(c.registered_students || 0);
    const isFull = seats !== null && reg >= seats;
    const onSale = has(c.sale_price);
    const publishLabel = c.status === 'published' ? 'إلغاء النشر' : 'نشر';

    return `
      <tr>
        <td>
          <div class="crs-cell">
            ${c.cover_image
              ? `<img class="crs-thumb" src="${esc(c.cover_image)}" alt="">`
              : '<span class="crs-thumb crs-thumb-empty">▤</span>'}
            <div>
              ${esc(c.title)}
              ${c.title_en ? `<br><small dir="ltr">${esc(c.title_en)}</small>` : ''}
              <br><small dir="ltr">${esc(c.slug)}</small>
            </div>
          </div>
        </td>
        <td>
          ${esc(c.instructor_name)}
          ${c.co_instructor_names ? `<br><small>+ ${esc(c.co_instructor_names)}</small>` : ''}
        </td>
        <td>
          ${onSale ? `<span class="crs-old">${esc(money(c.price))}</span>${esc(money(c.sale_price))}` : esc(money(c.price))} ج.م
        </td>
        <td>
          ${esc(num(reg))}${seats !== null ? ` / ${esc(num(seats))}` : ''}
          ${isFull ? ' <span class="badge full">مكتمل</span>' : ''}
        </td>
        <td>${esc(num(c.lectures_total))}</td>
        <td>${core().badge(c.status)}</td>
        <td>
          <button type="button" class="small-btn" data-edit-course="${esc(c.id)}">تعديل</button>
          <button type="button" class="small-btn" data-dup-course="${esc(c.id)}">نسخ</button>
          <button type="button" class="small-btn" data-course-status="${esc(c.id)}"
            data-next-status="${c.status === 'published' ? 'draft' : 'published'}">${publishLabel}</button>
        </td>
      </tr>`;
  }

  // ============================================================
  // النموذج
  // ============================================================
  function syncCoList() {
    const primary = $('#courseInstructor').value;
    $$('#courseCoList label').forEach(l => {
      const cb = $('input', l);
      const isPrimary = l.dataset.co === primary;
      if (isPrimary) cb.checked = false;
      cb.disabled = isPrimary;
      l.classList.toggle('is-disabled', isPrimary);
    });
  }

  function setCoverPreview(src) {
    $('#coverPreview').innerHTML = src
      ? `<img src="${esc(src)}" alt="صورة الغلاف">`
      : '<span>لا توجد صورة</span>';
    $('#coverRemove').hidden = !src;
  }

  function openForm(course) {
    const f = form();
    f.reset();
    removeCover = false;
    pickedFile = null;
    $('#coverInput').value = '';
    currentId = course ? Number(course.id) : 0;
    $('input[name="id"]', f).value = course ? course.id : '';

    if (course) {
      FIELDS.forEach(k => {
        const el = $(`[name="${k}"]`, f);
        if (el) el.value = course[k] ?? '';
      });
      const ids = String(course.instructor_ids || '').split(',').filter(Boolean);
      $$('#courseCoList input').forEach(cb => { cb.checked = ids.includes(cb.value); });
    } else {
      $$('#courseCoList input').forEach(cb => { cb.checked = false; });
    }
    syncCoList();
    setCoverPreview(course?.cover_image || '');

    $('#courseFormTitle').textContent = course ? 'تعديل الكورس' : 'إنشاء كورس';
    $('#courseMeta').textContent = course
      ? `المسجلون: ${num(course.registered_students)}${has(course.seats_limit) ? ` من ${num(course.seats_limit)} مقعد` : ''}`
      : 'احفظ الكورس أولًا ثم أضف المنهج والكوبونات.';

    f.hidden = false;
    $('#courseExtras').hidden = !course;
    units = [];
    renderCurriculum();
    $('#couponsTable').innerHTML = '';

    if (course) {
      loadCurriculum(course.id).catch(e => say(e.message, true));
      loadCoupons(course.id).catch(e => say(e.message, true));
    }
    f.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  function closeForm() {
    form().hidden = true;
    $('#courseExtras').hidden = true;
    currentId = 0;
  }

  function collect(forceStatus) {
    const f = form();
    const fd = new FormData(f);
    const payload = {};
    FIELDS.forEach(k => { payload[k] = fd.get(k) ?? ''; });
    payload.instructor_id = Number(payload.instructor_id) || 0;
    payload.instructor_ids = $$('#courseCoList input:checked').map(cb => Number(cb.value));
    if (forceStatus) {
      payload.status = forceStatus;
      $('[name="status"]', f).value = forceStatus;
    }
    const id = Number(fd.get('id')) || 0;
    payload.action = id ? 'update' : 'create';
    if (id) payload.id = id;
    return payload;
  }

  async function uploadCover(id, file) {
    const fd = new FormData();
    fd.append('action', 'cover_upload');
    fd.append('id', String(id));
    fd.append('cover', file);
    const res = await fetch(API, {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
      headers: { Accept: 'application/json', 'X-CSRF-Token': core().csrf() }
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || data.success === false) {
      throw new Error(data.message || `تعذر رفع الصورة (خطأ ${res.status}).`);
    }
  }

  async function saveCourse(forceStatus) {
    const wasNew = !currentId;
    const payload = collect(forceStatus);
    const saved = await post(payload);
    const id = saved.id;

    let coverWarning = '';
    try {
      if (removeCover && !pickedFile) await post({ action: 'cover_remove', id });
      if (pickedFile) await uploadCover(id, pickedFile);
    } catch (e) {
      coverWarning = ' (لكن الصورة لم تُحفظ: ' + e.message + ')';
    }

    await loadCourses();
    const fresh = courses.find(c => Number(c.id) === Number(id));
    if (fresh) openForm(fresh);

    say(
      (wasNew ? 'تم إنشاء الكورس. يمكنك الآن إضافة المنهج والكوبونات.' : 'تم حفظ بيانات الكورس.') + coverWarning,
      !!coverWarning
    );
  }

  // ============================================================
  // المنهج
  // ============================================================
  async function loadCurriculum(id) {
    const data = await core().request(API + '?curriculum=' + id);
    units = (data.data || []).map(u => ({
      id: Number(u.id), title: u.title,
      lessons: (u.lessons || []).map(l => ({
        id: Number(l.id), title: l.title,
        duration_minutes: l.duration_minutes ?? '',
        video_url: l.video_url ?? '',
        files: l.files || [], pendingVideo: null, pendingFiles: [],
        is_free_preview: Number(l.is_free_preview) === 1
      }))
    }));
    renderCurriculum();
  }

  function renderCurriculum() {
    const box = $('#curriculumBox');
    const lessonsTotal = units.reduce((a, u) => a + u.lessons.length, 0);
    const free = units.reduce((a, u) => a + u.lessons.filter(l => l.is_free_preview).length, 0);
    $('#curPill').textContent = `${num(units.length)} وحدة • ${num(lessonsTotal)} درس • ${num(free)} معاينة مجانية`;

    if (!units.length) {
      box.innerHTML = '<div class="cur-empty">لا توجد وحدات بعد. اضغط "+ وحدة جديدة" للبدء.</div>';
      return;
    }

    box.innerHTML = units.map((u, ui) => `
      <div class="cur-unit">
        <div class="cur-unit-head">
          <span class="cur-num">${ui + 1}</span>
          <input type="text" maxlength="200" placeholder="عنوان الوحدة / المحور"
                 data-f="unit-title" data-ui="${ui}" value="${esc(u.title)}">
          <div class="cur-tools">
            <button type="button" class="small-btn" title="أعلى" data-act="unit-up" data-ui="${ui}">↑</button>
            <button type="button" class="small-btn" title="أسفل" data-act="unit-down" data-ui="${ui}">↓</button>
            <button type="button" class="small-btn danger" title="حذف الوحدة" data-act="unit-del" data-ui="${ui}">✕</button>
          </div>
        </div>
        <div class="cur-lessons">
          ${u.lessons.map((l, li) => `
            <div class="cur-lesson">
              <span class="ln">${li + 1}</span>
              <input type="text" maxlength="200" placeholder="عنوان الدرس"
                     data-f="lesson-title" data-ui="${ui}" data-li="${li}" value="${esc(l.title)}">
              <input type="number" min="0" max="10000" placeholder="دقائق"
                     data-f="lesson-dur" data-ui="${ui}" data-li="${li}" value="${esc(l.duration_minutes)}">
              <div class="cur-upload"><span>فيديو من الجهاز</span>
                <input type="file" accept="video/mp4,.mp4"
                       data-f="lesson-video-file" data-ui="${ui}" data-li="${li}">
                <small>${l.video_url ? `الفيديو الحالي: <a href="${esc(l.video_url)}" target="_blank" rel="noopener">فتح الفيديو</a> <button type="button" class="small-btn danger" data-act="video-remove" data-ui="${ui}" data-li="${li}">حذف</button>` : 'MP4 — حتى 10 جيجابايت'}</small>
              </div>
              <div class="cur-upload"><span>ملفات الدرس من الجهاز</span>
                <input type="file" multiple accept="application/pdf,.pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.txt" data-f="lesson-files" data-ui="${ui}" data-li="${li}">
                <small>${l.files.length ? l.files.map(f => `<span class="lesson-file-chip"><a href="api/courses/media.php?course_id=${encodeURIComponent(currentId)}&lesson_id=${encodeURIComponent(l.id)}&file_id=${encodeURIComponent(f.id)}" target="_blank" rel="noopener">${esc(f.original_name)}</a><button type="button" class="small-btn danger" data-act="file-remove" data-file="${esc(f.id)}">×</button></span>`).join(' ') : 'PDF وOffice وTXT — حتى 35 ميجابايت للملف'}</small>
              </div>
              ${l.pendingVideo ? `<small class="lesson-pending">سيتم رفع الفيديو: ${esc(l.pendingVideo.name)}</small>` : ''}
              ${l.pendingFiles.length ? `<small class="lesson-pending">ملفات جديدة: ${l.pendingFiles.map(f => esc(f.name)).join('، ')}</small>` : ''}
              <label class="cur-free">
                <input type="checkbox" data-f="lesson-free" data-ui="${ui}" data-li="${li}" ${l.is_free_preview ? 'checked' : ''}>
                معاينة مجانية
              </label>
              <div class="cur-tools">
                <button type="button" class="small-btn" data-act="lesson-up" data-ui="${ui}" data-li="${li}">↑</button>
                <button type="button" class="small-btn" data-act="lesson-down" data-ui="${ui}" data-li="${li}">↓</button>
                <button type="button" class="small-btn danger" data-act="lesson-del" data-ui="${ui}" data-li="${li}">✕</button>
              </div>
            </div>`).join('')}
          <button type="button" class="small-btn" data-act="lesson-add" data-ui="${ui}">+ درس</button>
        </div>
      </div>`).join('');
  }

  const swap = (arr, i, dir) => {
    const j = i + dir;
    if (j < 0 || j >= arr.length) return false;
    [arr[i], arr[j]] = [arr[j], arr[i]];
    return true;
  };

  function onCurriculumInput(e) {
    const t = e.target;
    const f = t.dataset.f;
    if (!f) return;
    const ui = Number(t.dataset.ui);
    const li = Number(t.dataset.li);
    if (f === 'unit-title') units[ui].title = t.value;
    else if (f === 'lesson-title') units[ui].lessons[li].title = t.value;
    else if (f === 'lesson-dur') units[ui].lessons[li].duration_minutes = t.value;
    else if (f === 'lesson-video-file') { units[ui].lessons[li].pendingVideo = t.files[0] || null; return; }
    else if (f === 'lesson-files') { units[ui].lessons[li].pendingFiles = Array.from(t.files || []); return; }
    else if (f === 'lesson-free') {
      units[ui].lessons[li].is_free_preview = t.checked;
      renderCurriculum();
    }
  }

  function onCurriculumClick(e) {
    const b = e.target.closest('[data-act]');
    if (!b) return;
    const ui = Number(b.dataset.ui);
    const li = Number(b.dataset.li);
    let changed = true;

    switch (b.dataset.act) {
      case 'unit-up': changed = swap(units, ui, -1); break;
      case 'unit-down': changed = swap(units, ui, 1); break;
      case 'unit-del':
        if (!confirm('حذف هذه الوحدة وكل دروسها؟ (يتم الحذف عند حفظ المنهج)')) return;
        units.splice(ui, 1);
        break;
      case 'lesson-add':
        units[ui].lessons.push({ id: 0, title: '', duration_minutes: '', video_url: '', files: [], pendingVideo: null, pendingFiles: [], is_free_preview: false });
        break;
      case 'video-remove':
        removeLessonVideo(ui, li).catch(fail); return;
      case 'file-remove':
        removeLessonFile(b.dataset.file).catch(fail); return;
      case 'lesson-up': changed = swap(units[ui].lessons, li, -1); break;
      case 'lesson-down': changed = swap(units[ui].lessons, li, 1); break;
      case 'lesson-del': units[ui].lessons.splice(li, 1); break;
      default: return;
    }
    if (changed) renderCurriculum();
  }

  async function uploadLessonVideo(lessonId, file) {
    const fd = new FormData(); fd.append('action', 'lesson_video_upload'); fd.append('course_id', String(currentId)); fd.append('lesson_id', String(lessonId)); fd.append('video_file', file);
    await uploadRequest(fd);
  }

  async function uploadLessonFiles(lessonId, files) {
    const fd = new FormData(); fd.append('action', 'lesson_file_upload'); fd.append('course_id', String(currentId)); fd.append('lesson_id', String(lessonId));
    files.forEach(file => fd.append('lesson_files[]', file));
    await uploadRequest(fd);
  }

  async function uploadRequest(fd, url = API) {
    const res = await fetch(url, { method: 'POST', body: fd, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-Token': core().csrf() } });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || data.success === false) throw new Error(data.message || 'تعذر رفع الملف من الجهاز.');
    return data;
  }

  async function removeLessonVideo(ui, li) {
    const lesson = units[ui]?.lessons[li];
    if (!lesson) return;
    if (lesson.id && lesson.video_url && !confirm('حذف فيديو هذا الدرس؟')) return;
    if (lesson.id && lesson.video_url) await post({ action: 'lesson_video_remove', course_id: currentId, lesson_id: lesson.id });
    lesson.video_url = ''; lesson.pendingVideo = null; renderCurriculum(); say('تم حذف الفيديو.');
  }

  async function removeLessonFile(fileId) {
    if (!confirm('حذف الملف المرفق بهذا الدرس؟')) return;
    await post({ action: 'lesson_file_delete', file_id: Number(fileId) });
    await loadCurriculum(currentId); say('تم حذف الملف.');
  }

  async function saveCurriculum() {
    for (const u of units) {
      if (!u.title.trim()) return say('اكتب عنوانًا لكل وحدة قبل الحفظ.', true);
      if (u.lessons.some(l => !l.title.trim())) return say('اكتب عنوانًا لكل درس قبل الحفظ.', true);
    }
    const btn = $('#saveCurriculumBtn');
    btn.disabled = true;
    try {
      const pendingUploads = units.map(u => u.lessons.map(l => ({ video: l.pendingVideo, files: l.pendingFiles || [] })));
      const res = await post({
        action: 'curriculum_save',
        course_id: currentId,
        units: units.map(u => ({
          id: u.id, title: u.title.trim(),
          lessons: u.lessons.map(l => ({
            id: l.id, title: l.title.trim(),
            duration_minutes: l.duration_minutes,
            video_url: l.video_url.trim(),
            is_free_preview: l.is_free_preview ? 1 : 0
          }))
        }))
      });
      units = (res.data || []).map(u => ({
        id: Number(u.id), title: u.title,
        lessons: (u.lessons || []).map(l => ({
          id: Number(l.id), title: l.title,
          duration_minutes: l.duration_minutes ?? '',
          video_url: l.video_url ?? '',
          files: l.files || [], pendingVideo: null, pendingFiles: [],
          is_free_preview: Number(l.is_free_preview) === 1
        }))
      }));
      for (let ui = 0; ui < units.length; ui++) {
        for (let li = 0; li < units[ui].lessons.length; li++) {
          const pending = pendingUploads[ui]?.[li];
          const lesson = units[ui].lessons[li];
          if (pending?.video) await uploadLessonVideo(lesson.id, pending.video);
          if (pending?.files?.length) await uploadLessonFiles(lesson.id, pending.files);
        }
      }
      if (pendingUploads.some(unit => unit.some(p => p.video || p.files.length))) await loadCurriculum(currentId);
      renderCurriculum();
      loadCourses().catch(() => {});
      say('تم حفظ المنهج ورفع ملفات الدروس من الجهاز.');
    } catch (err) {
      say(err.message, true);
    } finally {
      btn.disabled = false;
    }
  }

  // ============================================================
  // الكوبونات
  // ============================================================
  async function loadCoupons(id) {
    const data = await core().request(API + '?coupons=' + id);
    const rows = data.data || [];
    const fmt = v => (v ? core().formatDate(v) : '—');

    $('#couponsTable').innerHTML = rows.length ? `
      <table>
        <thead><tr>
          <th>الكود</th><th>الخصم</th><th>الاستخدام</th><th>الفترة</th><th>الحالة</th><th>إجراء</th>
        </tr></thead>
        <tbody>${rows.map(r => `
          <tr>
            <td dir="ltr"><strong>${esc(r.code)}</strong></td>
            <td>${r.discount_type === 'percent' ? esc(money(r.discount_value)) + '٪' : esc(money(r.discount_value)) + ' ج.م'}</td>
            <td>${esc(num(r.used_count))}${has(r.max_uses) ? ' / ' + esc(num(r.max_uses)) : ''}</td>
            <td>${esc(fmt(r.starts_at))} ← ${esc(fmt(r.expires_at))}</td>
            <td>${r.status === 'active' ? '<span class="badge active">فعّال</span>' : '<span class="badge archived">معطّل</span>'}</td>
            <td>
              <button type="button" class="small-btn ${r.status === 'active' ? 'danger' : ''}"
                data-coupon="${esc(r.id)}" data-next="${r.status === 'active' ? 'disabled' : 'active'}">
                ${r.status === 'active' ? 'تعطيل' : 'تفعيل'}
              </button>
            </td>
          </tr>`).join('')}
        </tbody>
      </table>` :
      '<div style="padding:14px;color:#718096">لا توجد كوبونات لهذا الكورس.</div>';
  }

  async function loadBooks() {
    const data = await core().request('api/admin/books.php');
    books = data.books || [];
    $('#adminBooksTable').innerHTML = books.length ? `<table><thead><tr><th>الكتاب</th><th>النوع</th><th>الملف</th><th>السعر</th><th>الحالة</th><th>إجراء</th></tr></thead><tbody>${books.map(book => `<tr>
      <td>${book.cover_image ? `<img class="crs-thumb" src="${esc(book.cover_image)}" alt=""> ` : ''}${esc(book.title_ar)}</td><td>${esc(book.type)}</td>
      <td>${book.pdf_path ? `<a href="${esc(book.pdf_path)}" target="_blank" rel="noopener">فتح PDF</a>` : 'لا يوجد ملف'}</td><td>${esc(money(book.discount_price || book.price))} ج.م</td><td>${core().badge(book.status)}</td>
      <td><button type="button" class="small-btn" data-edit-book="${esc(book.id)}">تعديل</button> <button type="button" class="small-btn danger" data-archive-book="${esc(book.id)}">أرشفة</button></td></tr>`).join('')}</tbody></table>` : '<div style="padding:14px;color:#718096">لا توجد كتب مسجلة.</div>';
  }

  function resetBookForm(book = null) {
    const f = $('#adminBookForm'); f.reset();
    $('[name="id"]', f).value = book?.id || '';
    ['title_ar','title_en','author','type','price','discount_price','pages_count','stock','status','description_ar','description_en'].forEach(key => { if (book) $(`[name="${key}"]`, f).value = book[key] ?? ''; });
    if (book) $('[name="is_downloadable"]', f).checked = Number(book.is_downloadable) === 1;
    f.hidden = false; f.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }

  async function saveBook(event) {
    event.preventDefault();
    const f = event.currentTarget; const fd = new FormData(f); fd.append('action', 'save'); fd.set('is_downloadable', $('[name="is_downloadable"]', f).checked ? '1' : '0');
    const button = $('button[type="submit"]', f); button.disabled = true;
    try {
      await uploadRequest(fd, 'api/admin/books.php');
      f.hidden = true; f.reset(); await loadBooks(); say('تم حفظ بيانات الكتاب والملفات المرفوعة من الجهاز.');
    } catch (err) { fail(err); } finally { button.disabled = false; }
  }

  // ============================================================
  // الأحداث
  // ============================================================
  const fail = e => say(e.message, true);

  $('#newBookBtn').addEventListener('click', () => resetBookForm());
  $('#cancelBookBtn').addEventListener('click', () => { $('#adminBookForm').hidden = true; $('#adminBookForm').reset(); });
  $('#adminBookForm').addEventListener('submit', saveBook);
  $('#adminBooksTable').addEventListener('click', async e => {
    const edit = e.target.closest('[data-edit-book]'); const archive = e.target.closest('[data-archive-book]');
    if (edit) { const book = books.find(item => Number(item.id) === Number(edit.dataset.editBook)); if (book) resetBookForm(book); }
    if (archive && confirm('أرشفة هذا الكتاب؟')) {
      try { await core().request('api/admin/books.php?action=delete', { method: 'POST', body: JSON.stringify({ action: 'delete', id: Number(archive.dataset.archiveBook) }) }); await loadBooks(); say('تمت أرشفة الكتاب.'); }
      catch (err) { fail(err); }
    }
  });

  $('#addCourseBtn').addEventListener('click', () => openForm(null));
  $('#cancelCourse').addEventListener('click', closeForm);
  $('#courseInstructor').addEventListener('change', syncCoList);

  $('#searchCourses').addEventListener('click', () => loadCourses().catch(fail));
  $('#courseSearch').addEventListener('keydown', e => {
    if (e.key === 'Enter') { e.preventDefault(); loadCourses().catch(fail); }
  });
  $('#courseStatus').addEventListener('change', () => loadCourses().catch(fail));

  form().addEventListener('submit', async e => {
    e.preventDefault();
    const btn = $('button[type="submit"]', e.currentTarget);
    btn.disabled = true;
    try { await saveCourse(); } catch (err) { fail(err); } finally { btn.disabled = false; }
  });

  $('#saveDraftBtn').addEventListener('click', async () => {
    const f = form();
    if (!f.reportValidity()) return;
    const btn = $('#saveDraftBtn');
    btn.disabled = true;
    try { await saveCourse('draft'); } catch (err) { fail(err); } finally { btn.disabled = false; }
  });

  $('#coverInput').addEventListener('change', e => {
    const file = e.target.files[0];
    if (!file) return;
    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
      e.target.value = '';
      return say('الصيغة المسموحة: JPG أو PNG أو WEBP.', true);
    }
    if (file.size > 3 * 1024 * 1024) {
      e.target.value = '';
      return say('حجم الصورة أكبر من 3 ميجا.', true);
    }
    pickedFile = file;
    removeCover = false;
    setCoverPreview(URL.createObjectURL(file));
  });

  $('#coverRemove').addEventListener('click', () => {
    pickedFile = null;
    removeCover = true;
    $('#coverInput').value = '';
    setCoverPreview('');
  });

  $('#coursesTable').addEventListener('click', async e => {
    const edit = e.target.closest('[data-edit-course]');
    const dup = e.target.closest('[data-dup-course]');
    const st = e.target.closest('[data-course-status]');

    try {
      if (edit) {
        const c = courses.find(x => Number(x.id) === Number(edit.dataset.editCourse));
        if (c) openForm(c);
        return;
      }

      if (dup) {
        if (!confirm('نسخ هذا الكورس كمسودة جديدة (مع المنهج، بدون الطلاب والكوبونات)؟')) return;
        const res = await post({ action: 'duplicate', id: Number(dup.dataset.dupCourse) });
        await loadCourses();
        const c = courses.find(x => Number(x.id) === Number(res.id));
        if (c) openForm(c);
        say('تم نسخ الكورس كمسودة. عدّل البيانات ثم انشره.');
        return;
      }

      if (st) {
        const next = st.dataset.nextStatus;
        const c = courses.find(x => Number(x.id) === Number(st.dataset.courseStatus));
        if (next === 'draft' && c && Number(c.registered_students) > 0 &&
            !confirm(`الكورس عليه ${num(c.registered_students)} طالب مسجل. إلغاء النشر سيخفيه عن الزوار. متابعة؟`)) return;
        await post({ action: 'status', id: Number(st.dataset.courseStatus), status: next });
        await loadCourses();
        say(next === 'published' ? 'تم نشر الكورس.' : 'تم إلغاء نشر الكورس.');
      }
    } catch (err) { fail(err); }
  });

  $('#curriculumBox').addEventListener('input', onCurriculumInput);
  $('#curriculumBox').addEventListener('change', onCurriculumInput);
  $('#curriculumBox').addEventListener('click', onCurriculumClick);
  $('#addUnitBtn').addEventListener('click', () => {
    units.push({ id: 0, title: '', lessons: [] });
    renderCurriculum();
  });
  $('#saveCurriculumBtn').addEventListener('click', saveCurriculum);

  $('#couponForm').addEventListener('submit', async e => {
    e.preventDefault();
    const fd = new FormData(e.currentTarget);
    try {
      await post({
        action: 'coupon_create',
        course_id: currentId,
        code: fd.get('code'),
        discount_type: fd.get('discount_type'),
        discount_value: fd.get('discount_value'),
        max_uses: fd.get('max_uses'),
        starts_at: fd.get('starts_at'),
        expires_at: fd.get('expires_at')
      });
      e.currentTarget.reset();
      await loadCoupons(currentId);
      say('تم إضافة الكوبون.');
    } catch (err) { fail(err); }
  });

  $('#couponsTable').addEventListener('click', async e => {
    const b = e.target.closest('[data-coupon]');
    if (!b) return;
    try {
      await post({ action: 'coupon_toggle', id: Number(b.dataset.coupon), status: b.dataset.next });
      await loadCoupons(currentId);
    } catch (err) { fail(err); }
  });

  // واجهة تستدعيها admin.js عند فتح تبويب الكورسات
  window.CoursesAdmin = {
    load: () => Promise.all([loadInstructors(), loadCourses(), loadBooks()]).catch(fail)
  };
})();
