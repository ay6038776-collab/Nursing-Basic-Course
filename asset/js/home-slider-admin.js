(function () {
  'use strict';
  const $ = selector => document.querySelector(selector);
  const endpoint = 'api/admin/home-slider.php';
  const state = { slides: [], previewUrl: '' };
  const esc = value => String(value ?? '').replace(/[&<>"']/g, ch => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' })[ch]);

  function showMessage(text, error = false) {
    const el = $('#homeSliderMessage');
    if (!el) return;
    el.textContent = text;
    el.className = `notice ${error ? 'notice-error' : 'notice-success'}`;
    el.hidden = false;
    clearTimeout(el._timer);
    el._timer = setTimeout(() => { el.hidden = true; }, 4500);
  }

  function imageUrl(path) { return new URL(String(path || '').replace(/^\/+/, ''), location.href).href; }

  function render() {
    const list = $('#homeSliderList');
    if (!list) return;
    if (!state.slides.length) {
      list.innerHTML = '<div class="home-slider-empty">لا توجد صور في السلايدر حتى الآن. أضف صورتين أو أكثر لعرضها بالتتابع في الصفحة الرئيسية.</div>';
      return;
    }
    list.innerHTML = state.slides.map(slide => `<article class="home-slider-admin-card">
      <div class="home-slider-admin-image"><img src="${esc(imageUrl(slide.image_path))}" alt="${esc(slide.title_ar || 'صورة السلايدر')}" loading="lazy"><span class="home-slider-order">ترتيب ${Number(slide.sort_order)} · ${Number(slide.is_active) ? 'نشطة' : 'مخفية'}</span></div>
      <div class="home-slider-admin-copy"><strong>${esc(slide.title_ar || slide.title_en || 'بدون عنوان')}</strong><p>${esc(slide.subtitle_ar || slide.subtitle_en || 'لا يوجد وصف')}</p>
        <div class="home-slider-admin-actions"><button type="button" class="btn btn-outline btn-sm" data-slide-edit="${Number(slide.id)}">تعديل</button><button type="button" class="btn btn-danger btn-sm" data-slide-delete="${Number(slide.id)}">حذف</button></div>
      </div>
    </article>`).join('');
  }

  async function load() {
    const list = $('#homeSliderList');
    if (list && !state.slides.length) list.innerHTML = '<div class="home-slider-empty">جارٍ تحميل الصور…</div>';
    try {
      const response = await fetch(endpoint, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
      const data = await response.json();
      if (!response.ok || !data.success) throw new Error(data.message || 'تعذر تحميل السلايدر.');
      state.slides = data.slides || [];
      render();
    } catch (error) { if (list) list.innerHTML = `<div class="home-slider-empty">${esc(error.message)}</div>`; }
  }

  function resetForm(slide = null) {
    const form = $('#homeSliderForm');
    form.reset();
    if (state.previewUrl) URL.revokeObjectURL(state.previewUrl);
    state.previewUrl = '';
    form.elements.id.value = slide?.id || '';
    form.elements.title_ar.value = slide?.title_ar || '';
    form.elements.title_en.value = slide?.title_en || '';
    form.elements.subtitle_ar.value = slide?.subtitle_ar || '';
    form.elements.subtitle_en.value = slide?.subtitle_en || '';
    form.elements.sort_order.value = slide?.sort_order || 0;
    form.elements.is_active.checked = slide ? Number(slide.is_active) === 1 : true;
    form.elements.image_file.multiple = !slide;
    form.elements.image_file.value = '';
    form.dataset.existingImage = slide?.image_path || '';
    const preview = $('#homeSliderPreview');
    preview.hidden = !slide?.image_path;
    preview.innerHTML = slide?.image_path ? `<img src="${esc(imageUrl(slide.image_path))}" alt="معاينة الصورة الحالية"><span>الصورة الحالية محفوظة، ويمكنك اختيار صورة أخرى لاستبدالها.</span>` : '';
    $('#homeSliderForm').hidden = false;
    form.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }

  async function submit(event) {
    event.preventDefault();
    const form = event.currentTarget;
    const files = Array.from(form.elements.image_file.files || []);
    const isEdit = Boolean(form.elements.id.value);
    if (!isEdit && !files.length) { showMessage('اختر صورة واحدة على الأقل من جهازك.', true); return; }
    const button = form.querySelector('[type="submit"]');
    button.disabled = true;
    try {
      const token = window.AdminCore?.csrf?.() || sessionStorage.getItem('nbc_admin_csrf') || '';
      const queue = isEdit ? [files[0] || null] : files;
      const baseOrder = Math.max(0, Number(form.elements.sort_order.value) || 0);
      for (let index = 0; index < queue.length; index++) {
        const data = new FormData();
        data.set('id', isEdit ? form.elements.id.value : '');
        for (const name of ['title_ar', 'title_en', 'subtitle_ar', 'subtitle_en']) data.set(name, form.elements[name].value);
        data.set('sort_order', String(baseOrder + index));
        data.set('is_active', form.elements.is_active.checked ? '1' : '0');
        if (queue[index]) data.set('image_file', queue[index]);
        const response = await fetch(`${endpoint}?action=save`, { method: 'POST', body: data, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-Token': token } });
        const result = await response.json().catch(() => ({}));
        if (!response.ok || !result.success) throw new Error(result.message || `تعذر حفظ الصورة رقم ${index + 1}.`);
      }
      form.hidden = true; form.reset();
      form.elements.image_file.multiple = true;
      $('#homeSliderPreview').hidden = true; $('#homeSliderPreview').innerHTML = '';
      if (state.previewUrl) URL.revokeObjectURL(state.previewUrl); state.previewUrl = '';
      showMessage(queue.length > 1 ? `تمت إضافة ${queue.length} صور إلى السلايدر.` : 'تم حفظ صورة السلايدر.');
      await load();
    } catch (error) { showMessage(error.message, true); }
    finally { button.disabled = false; }
  }

  document.addEventListener('DOMContentLoaded', () => {
    $('#homeSliderAddBtn')?.addEventListener('click', () => resetForm());
    $('#homeSliderCancelBtn')?.addEventListener('click', () => { const form = $('#homeSliderForm'); form.hidden = true; form.reset(); form.elements.image_file.multiple = true; $('#homeSliderPreview').hidden = true; $('#homeSliderPreview').innerHTML = ''; if (state.previewUrl) URL.revokeObjectURL(state.previewUrl); state.previewUrl = ''; });
    $('#homeSliderForm')?.addEventListener('submit', submit);
    $('#homeSliderForm [name="image_file"]')?.addEventListener('change', event => {
      const files = Array.from(event.currentTarget.files || []); const file = files[0]; if (!file) return;
      if (state.previewUrl) URL.revokeObjectURL(state.previewUrl);
      state.previewUrl = URL.createObjectURL(file);
      $('#homeSliderPreview').innerHTML = `<img src="${state.previewUrl}" alt="معاينة الصورة الجديدة"><span>${files.length > 1 ? `تم اختيار ${files.length} صور، بدءًا من ${esc(file.name)}.` : `${esc(file.name)} — معاينة الصورة المحددة`}</span>`;
      $('#homeSliderPreview').hidden = false;
    });
    $('#homeSliderList')?.addEventListener('click', async event => {
      const edit = event.target.closest('[data-slide-edit]');
      const remove = event.target.closest('[data-slide-delete]');
      if (edit) {
        const slide = state.slides.find(item => Number(item.id) === Number(edit.dataset.slideEdit));
        if (slide) resetForm(slide);
      }
      if (remove && confirm('حذف هذه الصورة من السلايدر؟')) {
        try {
          const token = window.AdminCore?.csrf?.() || sessionStorage.getItem('nbc_admin_csrf') || '';
          const response = await fetch(`${endpoint}?action=delete`, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': token }, body: JSON.stringify({ id: Number(remove.dataset.slideDelete) }) });
          const result = await response.json();
          if (!response.ok || !result.success) throw new Error(result.message || 'تعذر حذف الصورة.');
          showMessage(result.message || 'تم حذف الصورة.'); await load();
        } catch (error) { showMessage(error.message, true); }
      }
    });
  });

  window.HomeSliderAdmin = { load };
})();
