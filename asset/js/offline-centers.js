(function () {
  'use strict';

  const endpoint = new URL('../../api/admin/offline-centers.php', document.currentScript.src);
  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) => Array.from(root.querySelectorAll(selector));
  const state = { centers: [], currentCenterId: 0, detail: null, coverPreviewUrl: '' };

  function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, ch => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    })[ch]);
  }

  function csrf() {
    return window.AdminCore?.csrf?.() || sessionStorage.getItem('nbc_admin_csrf') || '';
  }

  async function request(action, { method = 'GET', body = null } = {}) {
    const url = new URL(endpoint.href);
    url.searchParams.set('action', action);
    const headers = { Accept: 'application/json' };
    const options = { method, credentials: 'same-origin', headers };
    if (method !== 'GET') {
      const token = csrf();
      if (token) headers['X-CSRF-Token'] = token;
    }
    if (body instanceof FormData) {
      options.body = body;
    } else if (body !== null) {
      headers['Content-Type'] = 'application/json';
      options.body = JSON.stringify(body);
    }
    const response = await fetch(url, options);
    const result = await response.json().catch(() => ({ success: false, message: 'تعذر قراءة استجابة الخادم.' }));
    if (!response.ok || !result.success) throw new Error(result.message || 'تعذر تنفيذ العملية.');
    return result;
  }

  function message(target, text, error = false) {
    const element = typeof target === 'string' ? $(target) : target;
    if (!element) return;
    element.textContent = text;
    element.className = `notice ${error ? 'notice-error' : 'notice-success'}`;
    element.hidden = false;
    clearTimeout(element._hideTimer);
    element._hideTimer = setTimeout(() => { element.hidden = true; }, 5000);
  }

  function pathUrl(path) {
    if (!path) return '';
    return new URL(String(path).replace(/^\/+/, ''), location.href).href;
  }

  function renderCenters(centers) {
    const grid = $('#centersGrid');
    if (!grid) return;
    if (!centers.length) {
      grid.innerHTML = '<div class="offline-center-empty"><span>⌖</span><h3>لا توجد سناتر بعد</h3><p>ابدأ بإضافة أول سنتر ومواعيد الحجز المتاحة.</p></div>';
      return;
    }
    grid.innerHTML = centers.map(center => {
      const cover = center.cover_image
        ? `<img src="${escapeHtml(pathUrl(center.cover_image))}" alt="صورة ${escapeHtml(center.name)}" loading="lazy">`
        : '<div class="offline-center-no-cover" aria-hidden="true">⌖</div>';
      const status = center.status === 'active' ? '<span class="center-status active">نشط</span>' : '<span class="center-status inactive">غير نشط</span>';
      return `<article class="offline-center-card" data-center-id="${Number(center.id)}">
        <div class="offline-center-cover">${cover}</div>
        <div class="offline-center-card-body">
          <div class="offline-center-card-title"><h3>${escapeHtml(center.name)}</h3>${status}</div>
          <p class="offline-center-address">${escapeHtml(center.address || 'لم يضاف عنوان بعد')}</p>
          <div class="offline-center-card-meta"><span>📎 ${Number(center.media_count || 0)} ملف</span><span>🗓 ${Number(center.open_slots || 0)} موعد قادم</span></div>
          <div class="offline-center-actions">
            <button type="button" class="btn btn-primary" data-center-action="manage">الملفات والمواعيد</button>
            <button type="button" class="btn btn-outline" data-center-action="edit">تعديل</button>
            <button type="button" class="btn btn-danger" data-center-action="delete">حذف</button>
          </div>
        </div>
      </article>`;
    }).join('');
  }

  async function load(search = $('#centersSearch')?.value || '') {
    const grid = $('#centersGrid');
    if (grid) grid.innerHTML = '<div class="offline-center-empty">جارٍ تحميل السناتر…</div>';
    try {
      const result = await request('list');
      state.centers = Array.isArray(result.centers) ? result.centers : [];
      const term = String(search).trim().toLocaleLowerCase();
      const visible = term ? state.centers.filter(center => [center.name, center.address, center.phone].some(value => String(value || '').toLocaleLowerCase().includes(term))) : state.centers;
      $('#centersTotal').textContent = String(state.centers.length);
      $('#centersActive').textContent = String(state.centers.filter(center => center.status === 'active').length);
      $('#centersSlots').textContent = String(state.centers.reduce((sum, center) => sum + Number(center.open_slots || 0), 0));
      renderCenters(visible);
    } catch (error) {
      if (grid) grid.innerHTML = `<div class="offline-center-empty error">${escapeHtml(error.message)}</div>`;
    }
  }

  function closeForm() {
    $('#centerFormModal').hidden = true;
    $('#centerForm').reset();
    $('#centerId').value = '';
    $('#centerFormTitle').textContent = 'إضافة سنتر';
    $('#centerSaveBtn').textContent = 'حفظ السنتر';
    $('#centerCoverPreview').hidden = true;
    $('#centerCoverPreview').innerHTML = '';
    if (state.coverPreviewUrl) URL.revokeObjectURL(state.coverPreviewUrl);
    state.coverPreviewUrl = '';
  }

  function openNewForm() {
    closeForm();
    $('#centerStatus').value = 'active';
    $('#centerFormModal').hidden = false;
    $('#centerName').focus();
  }

  function openEditForm(center) {
    closeForm();
    $('#centerFormTitle').textContent = 'تعديل بيانات السنتر';
    $('#centerSaveBtn').textContent = 'حفظ التعديلات';
    $('#centerId').value = center.id;
    $('#centerName').value = center.name || '';
    $('#centerAddress').value = center.address || '';
    $('#centerPhone').value = center.phone || '';
    $('#centerWhatsapp').value = center.whatsapp || '';
    $('#centerMapUrl').value = center.map_url || '';
    $('#centerDescription').value = center.description || '';
    $('#centerStatus').value = center.status || 'active';
    if (center.cover_image) {
      $('#centerCoverPreview').innerHTML = `<img src="${escapeHtml(pathUrl(center.cover_image))}" alt="صورة السنتر الحالية"><span>اختر صورة جديدة لاستبدالها</span>`;
      $('#centerCoverPreview').hidden = false;
    }
    $('#centerFormModal').hidden = false;
    $('#centerName').focus();
  }

  function bytesLabel(size) {
    const bytes = Number(size || 0);
    if (bytes < 1024 * 1024) return `${Math.max(1, Math.round(bytes / 1024))} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
  }

  function renderMedia(items) {
    const grid = $('#centerMediaGrid');
    if (!grid) return;
    $('#centerMediaCount').textContent = `${items.length} عنصر`;
    if (!items.length) {
      grid.innerHTML = '<p class="center-empty-note">لا توجد ملفات أو فيديوهات مرفوعة لهذا السنتر.</p>';
      return;
    }
    grid.innerHTML = items.map(item => {
      const url = escapeHtml(pathUrl(item.file_path));
      const preview = item.media_type === 'video'
        ? `<video src="${url}" controls preload="metadata" aria-label="${escapeHtml(item.title)}"></video>`
        : `<div class="center-file-icon" aria-hidden="true">${String(item.mime_type).includes('pdf') ? 'PDF' : 'FILE'}</div>`;
      return `<article class="center-media-item">
        <div class="center-media-preview">${preview}</div>
        <div class="center-media-copy"><strong title="${escapeHtml(item.title)}">${escapeHtml(item.title)}</strong><small>${escapeHtml(item.original_name)} · ${bytesLabel(item.size_bytes)}</small></div>
        <div class="center-media-actions"><a class="btn btn-outline btn-sm" href="${url}" target="_blank" rel="noopener">فتح</a><button type="button" class="btn btn-danger btn-sm" data-media-delete="${Number(item.id)}">حذف</button></div>
      </article>`;
    }).join('');
  }

  function dateLabel(value) {
    const date = new Date(`${value}T12:00:00`);
    return Number.isNaN(date.getTime()) ? value : date.toLocaleDateString(document.documentElement.lang === 'en' ? 'en-US' : 'ar-EG', { weekday: 'short', year: 'numeric', month: 'short', day: 'numeric' });
  }

  function renderSlots(slots) {
    const list = $('#centerSlotsList');
    if (!list) return;
    $('#centerSlotCount').textContent = `${slots.length} موعد`;
    if (!slots.length) {
      list.innerHTML = '<p class="center-empty-note">لا توجد مواعيد. أضف أول موعد متاح للحجز.</p>';
      return;
    }
    list.innerHTML = slots.map(slot => `<article class="center-slot-item" data-slot-id="${Number(slot.id)}">
      <div class="center-slot-date"><strong>${escapeHtml(dateLabel(slot.booking_date))}</strong><span>${escapeHtml(String(slot.start_time).slice(0, 5))} – ${escapeHtml(String(slot.end_time).slice(0, 5))}</span></div>
      <div class="center-slot-info"><strong>${escapeHtml(slot.title)}</strong><small>سعة الحجز: ${Number(slot.capacity)}${slot.notes ? ` · ${escapeHtml(slot.notes)}` : ''}</small></div>
      <span class="center-status ${slot.status === 'open' ? 'active' : 'inactive'}">${slot.status === 'open' ? 'متاح' : 'مغلق'}</span>
      <div class="center-slot-actions"><button type="button" class="btn btn-outline btn-sm" data-slot-edit="${Number(slot.id)}">تعديل</button><button type="button" class="btn btn-danger btn-sm" data-slot-delete="${Number(slot.id)}">حذف</button></div>
    </article>`).join('');
  }

  function resetSlotForm() {
    $('#centerSlotForm').reset();
    $('#centerSlotId').value = '';
    $('#centerSlotCapacity').value = '1';
    $('#centerSlotStatus').value = 'open';
    $('#centerSlotSaveBtn').textContent = 'إضافة الموعد';
    $('#centerSlotCancelEdit').hidden = true;
  }

  async function openManage(center) {
    state.currentCenterId = Number(center.id);
    $('#centerManageTitle').textContent = center.name;
    $('#centerManageSubtitle').textContent = center.address || 'إدارة ملفات السنتر ومواعيد الحجز';
    $('#centerManageModal').hidden = false;
    resetSlotForm();
    await loadManageDetail();
  }

  async function loadManageDetail() {
    if (!state.currentCenterId) return;
    try {
      const url = new URL(endpoint.href);
      url.searchParams.set('action', 'detail');
      url.searchParams.set('id', String(state.currentCenterId));
      const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
      const detail = await response.json();
      if (!response.ok || !detail.success) throw new Error(detail.message || 'تعذر تحميل بيانات السنتر.');
      state.detail = detail;
      $('#centerManageTitle').textContent = detail.center.name;
      $('#centerManageSubtitle').textContent = detail.center.address || 'إدارة ملفات السنتر ومواعيد الحجز';
      renderMedia(detail.media || []);
      renderSlots(detail.slots || []);
    } catch (error) {
      message('#centerManageMessage', error.message, true);
    }
  }

  async function saveCenter(event) {
    event.preventDefault();
    const button = $('#centerSaveBtn');
    button.disabled = true;
    const formData = new FormData(event.currentTarget);
    try {
      const result = await request('save', { method: 'POST', body: formData });
      message('#centersMessage', result.message || 'تم حفظ السنتر.');
      closeForm();
      await load();
    } catch (error) {
      message('#centersMessage', error.message, true);
    } finally {
      button.disabled = false;
    }
  }

  async function uploadMedia(event) {
    event.preventDefault();
    const input = $('#centerMediaFiles');
    if (!input.files.length) return message('#centerManageMessage', 'اختر ملفًا أو فيديو أولًا.', true);
    const formData = new FormData();
    formData.append('center_id', String(state.currentCenterId));
    Array.from(input.files).forEach(file => formData.append('files[]', file));
    const button = $('#centerMediaUploadBtn');
    button.disabled = true;
    try {
      const result = await request('upload_media', { method: 'POST', body: formData });
      input.value = '';
      message('#centerManageMessage', result.message || 'تم رفع الملفات.');
      await loadManageDetail();
      await load();
    } catch (error) {
      message('#centerManageMessage', error.message, true);
    } finally {
      button.disabled = false;
    }
  }

  async function saveSlot(event) {
    event.preventDefault();
    const payload = {
      id: $('#centerSlotId').value || 0,
      center_id: state.currentCenterId,
      title: $('#centerSlotTitle').value.trim(),
      booking_date: $('#centerSlotDate').value,
      start_time: $('#centerSlotStart').value,
      end_time: $('#centerSlotEnd').value,
      capacity: Number($('#centerSlotCapacity').value || 1),
      status: $('#centerSlotStatus').value,
      notes: $('#centerSlotNotes').value.trim(),
    };
    const button = $('#centerSlotSaveBtn');
    button.disabled = true;
    try {
      const result = await request('save_slot', { method: 'POST', body: payload });
      message('#centerManageMessage', result.message || 'تم حفظ موعد الحجز.');
      resetSlotForm();
      await loadManageDetail();
      await load();
    } catch (error) {
      message('#centerManageMessage', error.message, true);
    } finally {
      button.disabled = false;
    }
  }

  async function handleCenterAction(event) {
    const button = event.target.closest('[data-center-action]');
    if (!button) return;
    const card = button.closest('[data-center-id]');
    const center = state.centers.find(item => Number(item.id) === Number(card?.dataset.centerId));
    if (!center) return;
    if (button.dataset.centerAction === 'edit') openEditForm(center);
    if (button.dataset.centerAction === 'manage') await openManage(center);
    if (button.dataset.centerAction === 'delete') {
      if (!confirm(`حذف السنتر «${center.name}»؟ سيتم حذف مواعيده وملفاته أيضًا.`)) return;
      try {
        const result = await request('delete', { method: 'POST', body: { id: center.id } });
        message('#centersMessage', result.message || 'تم حذف السنتر.');
        await load();
      } catch (error) {
        message('#centersMessage', error.message, true);
      }
    }
  }

  async function handleMediaDelete(event) {
    const button = event.target.closest('[data-media-delete]');
    if (!button) return;
    if (!confirm('حذف هذا الملف نهائيًا؟')) return;
    try {
      await request('delete_media', { method: 'POST', body: { id: Number(button.dataset.mediaDelete) } });
      message('#centerManageMessage', 'تم حذف الملف.');
      await loadManageDetail();
      await load();
    } catch (error) {
      message('#centerManageMessage', error.message, true);
    }
  }

  function editSlot(event) {
    const button = event.target.closest('[data-slot-edit]');
    if (!button) return;
    const slot = state.detail?.slots?.find(item => Number(item.id) === Number(button.dataset.slotEdit));
    if (!slot) return;
    $('#centerSlotId').value = slot.id;
    $('#centerSlotTitle').value = slot.title;
    $('#centerSlotDate').value = slot.booking_date;
    $('#centerSlotStart').value = String(slot.start_time).slice(0, 5);
    $('#centerSlotEnd').value = String(slot.end_time).slice(0, 5);
    $('#centerSlotCapacity').value = slot.capacity;
    $('#centerSlotStatus').value = slot.status;
    $('#centerSlotNotes').value = slot.notes || '';
    $('#centerSlotSaveBtn').textContent = 'حفظ الموعد';
    $('#centerSlotCancelEdit').hidden = false;
    $('#centerSlotTitle').focus();
  }

  async function deleteSlot(event) {
    const button = event.target.closest('[data-slot-delete]');
    if (!button) return;
    if (!confirm('حذف موعد الحجز؟')) return;
    try {
      const result = await request('delete_slot', { method: 'POST', body: { id: Number(button.dataset.slotDelete) } });
      message('#centerManageMessage', result.message || 'تم حذف الموعد.');
      await loadManageDetail();
      await load();
    } catch (error) {
      message('#centerManageMessage', error.message, true);
    }
  }

  document.addEventListener('DOMContentLoaded', () => {
    $('#centerAddBtn')?.addEventListener('click', openNewForm);
    $('#centerForm')?.addEventListener('submit', saveCenter);
    $('#centerMediaForm')?.addEventListener('submit', uploadMedia);
    $('#centerSlotForm')?.addEventListener('submit', saveSlot);
    $('#centersGrid')?.addEventListener('click', handleCenterAction);
    $('#centerMediaGrid')?.addEventListener('click', handleMediaDelete);
    $('#centerSlotsList')?.addEventListener('click', event => { editSlot(event); deleteSlot(event); });
    $('#centersSearchBtn')?.addEventListener('click', () => load());
    $('#centersRefreshBtn')?.addEventListener('click', () => load(''));
    $('#centersSearch')?.addEventListener('keydown', event => { if (event.key === 'Enter') { event.preventDefault(); load(); } });
    $('#centerCoverFile')?.addEventListener('change', event => {
      const file = event.currentTarget.files[0];
      if (!file) return;
      if (state.coverPreviewUrl) URL.revokeObjectURL(state.coverPreviewUrl);
      state.coverPreviewUrl = URL.createObjectURL(file);
      $('#centerCoverPreview').innerHTML = `<img src="${state.coverPreviewUrl}" alt="معاينة صورة السنتر"><span>${escapeHtml(file.name)}</span>`;
      $('#centerCoverPreview').hidden = false;
    });
    $$('[data-close-center-form]').forEach(button => button.addEventListener('click', closeForm));
    $$('[data-close-center-manage]').forEach(button => button.addEventListener('click', () => { $('#centerManageModal').hidden = true; }));
    $('#centerFormModal')?.addEventListener('click', event => { if (event.target === event.currentTarget) closeForm(); });
    $('#centerManageModal')?.addEventListener('click', event => { if (event.target === event.currentTarget) event.currentTarget.hidden = true; });
    $('#centerSlotCancelEdit')?.addEventListener('click', resetSlotForm);
  });

  window.OfflineCentersAdmin = { load };
})();
