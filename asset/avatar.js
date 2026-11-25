/* صورة الشخص في موقع الطلاب (تُحمَّل قبل main.js) */
function avatarHtml(u, cls = 'dash-user-avatar') {
  return u.avatar ? `<div class="${cls}"><img src="${esc(u.avatar)}" alt=""></div>` : `<div class="${cls}">${esc((u.name || '?').trim().charAt(0).toUpperCase())}</div>`;
}
function avatarPicker(u) {
  return `<div style="display:flex;gap:18px;align-items:center;flex-wrap:wrap;margin-bottom:20px">
    <div class="dash-user-avatar avatar-lg">${u.avatar ? `<img src="${esc(u.avatar)}" alt="">` : esc((u.name || '?').trim().charAt(0).toUpperCase())}</div>
    <div><label class="btn btn-outline btn-sm" for="avatarFile">${u.avatar ? 'تغيير الصورة' : 'رفع صورة'}</label>
      ${u.avatar ? '<button type="button" class="btn btn-outline btn-sm" onclick="sendAvatar(null)">حذف الصورة</button>' : ''}
      <input type="file" id="avatarFile" accept="image/jpeg,image/png,image/webp" hidden onchange="sendAvatar(this.files[0])">
      <div style="font-size:12px;color:var(--ink-500);margin-top:6px">JPG / PNG / WebP — حتى 2MB</div></div></div>`;
}
async function sendAvatar(file) {
  const fd = new FormData();
  if (file) {
    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) return toast('استخدم صورة JPG أو PNG أو WebP.', 'error');
    if (file.size > 2 * 1048576) return toast('حجم الصورة يجب ألا يتجاوز 2MB.', 'error');
    fd.append('avatar', file);
  } else fd.append('remove', '1');
  try {
    const r = await fetch('api/student/avatar.php', { method: 'POST', credentials: 'same-origin', body: fd });
    const j = await r.json();
    if (!j.success) throw new Error(j.message || 'حدث خطأ.');
    STATE.currentUser.avatar = j.avatar; saveUser(); toast(j.message, 'success'); renderStudentDash();
  } catch (e) { toast(e.message, 'error'); }
}
