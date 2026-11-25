(function () {
  'use strict';
  const $ = selector => document.querySelector(selector);
  const endpoint = 'api/admin/founder-photos.php';
  const state = { photos: [], previewUrl: '' };
  const esc = value => String(value ?? '').replace(/[&<>"']/g, ch => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' })[ch]);
  const imageUrl = path => new URL(String(path || '').replace(/^\/+/, ''), location.href).href;

  function message(text, error = false) {
    const el = $('#founderPhotosMessage');
    if (!el) return;
    el.textContent = text;
    el.className = `notice ${error ? 'notice-error' : 'notice-success'}`;
    el.hidden = false;
    clearTimeout(el._timer);
    el._timer = setTimeout(() => { el.hidden = true; }, 4500);
  }

  function render() {
    const list = $('#founderPhotosList');
    if (!list) return;
    if (!state.photos.length) {
      list.innerHTML = '<div class="home-slider-empty">لا توجد صور لمالك المنصة حتى الآن. أضف الصور من جهازك لتظهر في قسمه بالصفحة الرئيسية.</div>';
      return;
    }
    list.innerHTML = state.photos.map(photo => `<article class="home-slider-admin-card">
      <div class="home-slider-admin-image"><img src="${esc(imageUrl(photo.image_path))}" alt="${esc(photo.caption_ar || 'صورة مالك المنصة')}" loading="lazy"><span class="home-slider-order">ترتيب ${Number(photo.sort_order)} · ${Number(photo.is_active) ? 'نشطة' : 'مخفية'}</span></div>
      <div class="home-slider-admin-copy"><strong>${esc(photo.caption_ar || photo.caption_en || 'صورة مالك المنصة')}</strong>
        <div class="home-slider-admin-actions"><button type="button" class="btn btn-outline btn-sm" data-founder-edit="${Number(photo.id)}">تعديل</button><button type="button" class="btn btn-danger btn-sm" data-founder-delete="${Number(photo.id)}">حذف</button></div>
      </div>
    </article>`).join('');
  }

  async function load() {
    const list = $('#founderPhotosList');
    if (list && !state.photos.length) list.innerHTML = '<div class="home-slider-empty">جارٍ تحميل الصور…</div>';
    try {
      const response = await fetch(endpoint, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
      const data = await response.json();
      if (!response.ok || !data.success) throw new Error(data.message || 'تعذر تحميل صور المالك.');
      state.photos = data.photos || [];
      render();
    } catch (error) { if (list) list.innerHTML = `<div class="home-slider-empty">${esc(error.message)}</div>`; }
  }

  function openForm(photo = null) {
    const form = $('#founderPhotoForm');
    form.reset();
    if (state.previewUrl) URL.revokeObjectURL(state.previewUrl);
    state.previewUrl = '';
    form.elements.id.value = photo?.id || '';
    form.elements.caption_ar.value = photo?.caption_ar || '';
    form.elements.caption_en.value = photo?.caption_en || '';
    form.elements.sort_order.value = photo?.sort_order || 0;
    form.elements.is_active.checked = photo ? Number(photo.is_active) === 1 : true;
    form.elements.image_file.multiple = !photo;
    form.elements.image_file.value = '';
    form.dataset.existingImage = photo?.image_path || '';
    const preview = $('#founderPhotoPreview');
    preview.hidden = !photo?.image_path;
    preview.innerHTML = photo?.image_path ? `<img src="${esc(imageUrl(photo.image_path))}" alt="معاينة الصورة الحالية"><span>الصورة الحالية محفوظة، ويمكن اختيار صورة بديلة.</span>` : '';
    form.hidden = false;
    form.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }

  async function submit(event) {
    event.preventDefault();
    const form = event.currentTarget;
    const files = Array.from(form.elements.image_file.files || []);
    const isEdit = Boolean(form.elements.id.value);
    if (!isEdit && !files.length) { message('اختر صورة واحدة على الأقل من جهازك.', true); return; }
    const button = form.querySelector('[type="submit"]');
    button.disabled = true;
    try {
      const token = window.AdminCore?.csrf?.() || sessionStorage.getItem('nbc_admin_csrf') || '';
      const queue = isEdit ? [files[0] || null] : files;
      const baseOrder = Math.max(0, Number(form.elements.sort_order.value) || 0);
      for (let index = 0; index < queue.length; index++) {
        const data = new FormData();
        data.set('id', isEdit ? form.elements.id.value : '');
        data.set('caption_ar', form.elements.caption_ar.value);
        data.set('caption_en', form.elements.caption_en.value);
        data.set('sort_order', String(baseOrder + index));
        data.set('is_active', form.elements.is_active.checked ? '1' : '0');
        if (queue[index]) data.set('image_file', queue[index]);
        const response = await fetch(`${endpoint}?action=save`, { method: 'POST', body: data, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-Token': token } });
        const result = await response.json().catch(() => ({}));
        if (!response.ok || !result.success) throw new Error(result.message || `تعذر حفظ الصورة رقم ${index + 1}.`);
      }
      form.hidden = true;
      form.reset();
      form.elements.image_file.multiple = true;
      $('#founderPhotoPreview').hidden = true;
      $('#founderPhotoPreview').innerHTML = '';
      if (state.previewUrl) URL.revokeObjectURL(state.previewUrl);
      state.previewUrl = '';
      message(queue.length > 1 ? `تمت إضافة ${queue.length} صور لمالك المنصة.` : 'تم حفظ الصورة.');
      await load();
    } catch (error) { message(error.message, true); }
    finally { button.disabled = false; }
  }

  document.addEventListener('DOMContentLoaded', () => {
    $('#founderPhotoAddBtn')?.addEventListener('click', () => openForm());
    $('#founderPhotoCancelBtn')?.addEventListener('click', () => {
      const form = $('#founderPhotoForm');
      form.hidden = true;
      form.reset();
      form.elements.image_file.multiple = true;
      $('#founderPhotoPreview').hidden = true;
      $('#founderPhotoPreview').innerHTML = '';
      if (state.previewUrl) URL.revokeObjectURL(state.previewUrl);
      state.previewUrl = '';
    });
    $('#founderPhotoForm')?.addEventListener('submit', submit);
    $('#founderPhotoForm [name="image_file"]')?.addEventListener('change', event => {
      const files = Array.from(event.currentTarget.files || []);
      if (!files.length) return;
      if (state.previewUrl) URL.revokeObjectURL(state.previewUrl);
      state.previewUrl = URL.createObjectURL(files[0]);
      $('#founderPhotoPreview').innerHTML = `<img src="${state.previewUrl}" alt="معاينة الصورة المحددة"><span>${files.length > 1 ? `تم اختيار ${files.length} صور، بدءًا من ${esc(files[0].name)}.` : esc(files[0].name)}</span>`;
      $('#founderPhotoPreview').hidden = false;
    });
    $('#founderPhotosList')?.addEventListener('click', async event => {
      const edit = event.target.closest('[data-founder-edit]');
      const remove = event.target.closest('[data-founder-delete]');
      if (edit) {
        const photo = state.photos.find(item => Number(item.id) === Number(edit.dataset.founderEdit));
        if (photo) openForm(photo);
      }
      if (remove && confirm('حذف هذه الصورة من قسم مالك المنصة؟')) {
        try {
          const token = window.AdminCore?.csrf?.() || sessionStorage.getItem('nbc_admin_csrf') || '';
          const response = await fetch(`${endpoint}?action=delete`, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': token }, body: JSON.stringify({ id: Number(remove.dataset.founderDelete) }) });
          const result = await response.json();
          if (!response.ok || !result.success) throw new Error(result.message || 'تعذر حذف الصورة.');
          message(result.message || 'تم حذف الصورة.');
          await load();
        } catch (error) { message(error.message, true); }
      }
    });
  });

  window.FounderPhotosAdmin = { load };
})();
