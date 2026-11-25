(function () {
  'use strict';
  const emojis = {
    overview:'📊',students:'🎓',instructors:'🩺',courses:'📚',centers:'🏥',enrollments:'📝',payments:'💳',
    quizzes:'🧠',certificates:'🏅',skills:'🫀',notifications:'🔔',support:'💬',reports:'📈',progress:'🚀',
    settings:'⚙️','admin-profile':'👤','enrolled-courses':'📖','explore-courses':'🔎',meetings:'🗓️',grades:'⭐',
    notes:'📄',orders:'💰',profile:'🪪',lessons:'🩻',reviews:'🌟',announcements:'📣'
  };
  let timer;
  function overlay() {
    let el=document.getElementById('dashboardTransition');
    if(el)return el;
    el=document.createElement('div');el.id='dashboardTransition';el.className='dashboard-transition';el.hidden=true;el.setAttribute('aria-hidden','true');
    el.innerHTML='<div class="dashboard-transition-card"><span class="dashboard-transition-mark">🩺<i class="dashboard-transition-spinner"></i></span><span>جارٍ فتح القسم</span></div>';
    document.body.appendChild(el);return el;
  }
  function show() {
    const el=overlay();clearTimeout(timer);el.hidden=false;el.setAttribute('aria-hidden','false');
    requestAnimationFrame(()=>el.classList.add('is-active'));
    timer=setTimeout(hide,520);
  }
  function hide() {
    const el=document.getElementById('dashboardTransition');if(!el)return;
    el.classList.remove('is-active');el.setAttribute('aria-hidden','true');
    setTimeout(()=>{if(!el.classList.contains('is-active'))el.hidden=true;},220);
  }
  function enter(panel) {
    if(!panel)return;panel.classList.remove('dashboard-panel-enter');void panel.offsetWidth;panel.classList.add('dashboard-panel-enter');
    setTimeout(()=>panel.classList.remove('dashboard-panel-enter'),460);
  }
  document.addEventListener('DOMContentLoaded',()=>{
    document.querySelectorAll('[data-tab]').forEach(button=>{const emoji=emojis[button.dataset.tab];if(emoji)button.dataset.sectionEmoji=emoji;});
  });
  window.DashboardEffects={show,hide,enter};
})();
