(function(){
  'use strict';
  const presets=['royal-red','royal-gold','european-luxury','modern-dark'];
  const aliases={'royal-teal':'royal-red','royal-sapphire':'royal-gold','royal-jade':'royal-gold','royal-amethyst':'modern-dark','royal-cyan':'european-luxury','clinical-blue':'royal-gold','teal-care':'royal-red','emerald-clinic':'royal-gold','violet-academy':'modern-dark','arctic-care':'european-luxury','custom':'royal-red'};
  const fonts=['Cairo','Tajawal','Almarai','IBM Plex Sans Arabic','Alexandria','Readex Pro','Noto Sans Arabic'];
  const labels={'royal-red':'الأحمر الملكي','royal-gold':'الذهبي الملكي','european-luxury':'الفخامة الأوروبية','modern-dark':'الداكن العصري'};
  let policy={theme_preset:'royal-red',theme_font:'Cairo',theme_font_en:'Inter',theme_mode_default:'light',theme_user_choice_enabled:true,theme_motion:'smooth'};
  const root=document.documentElement;
  const normalize=value=>{const p=aliases[value]||value;return presets.includes(p)?p:'royal-red'};
  window.normalizeMedicalPreset=normalize;
  function userChoice(){try{return localStorage.getItem('nbc_theme_user_choice')==='1'}catch(_){return false}}
  function setPalette(preset,save){preset=normalize(preset);root.dataset.medicalTheme=preset;if(save&&policy.theme_user_choice_enabled){try{localStorage.setItem('nbc_user_medical_theme',preset);localStorage.setItem('nbc_theme_user_choice','1')}catch(_){}}}
  function setMode(mode,save){mode=mode==='dark'?'dark':'light';root.dataset.theme=mode;root.style.colorScheme=mode;if(save&&policy.theme_user_choice_enabled){try{localStorage.setItem('nbc_theme',mode);localStorage.setItem('nbc_theme_user_choice','1')}catch(_){}};document.querySelectorAll('#landingThemeToggle,#themeToggle,#loginThemeToggle,#themeToggleBtn').forEach(btn=>{btn.setAttribute('aria-label',mode==='dark'?'تفعيل الوضع النهاري':'تفعيل الوضع الليلي');btn.title=mode==='dark'?'تفعيل الوضع النهاري':'تفعيل الوضع الليلي';const icon=btn.querySelector('#landingThemeIcon');if(icon)icon.textContent=mode==='dark'?'☀':'☾';const sun=btn.querySelector('.theme-icon-sun');const moon=btn.querySelector('.theme-icon-moon');if(sun)sun.hidden=mode!=='dark';if(moon)moon.hidden=mode==='dark'})}
  function apply(settings){
    if(!settings)return;policy={...policy,...settings};
    const admin=document.body?.classList.contains('admin-body')||location.pathname.endsWith('admin.html');
    const canChoose=!!policy.theme_user_choice_enabled;
    let hasUserPalette=false;
    if(canChoose&&!admin){try{hasUserPalette=userChoice()&&!!localStorage.getItem('nbc_user_medical_theme')}catch(_){}}
    const adminForm=document.getElementById('settingsForm');
    let palette=normalize(adminForm?.elements?.theme_preset?.value||policy.theme_preset);
    if(canChoose&&userChoice()&&!admin){try{palette=normalize(localStorage.getItem('nbc_user_medical_theme')||palette)}catch(_){}}
    setPalette(palette,false);
    const fontValue=adminForm?.elements?.theme_font?.value||policy.theme_font;const font=fonts.includes(fontValue)?fontValue:'Cairo';root.style.setProperty('--font-ar',`"${font}", "IBM Plex Sans Arabic", "Segoe UI", Tahoma, sans-serif`);
    const englishValue=adminForm?.elements?.theme_font_en?.value||policy.theme_font_en;const english=['Inter','Playfair Display','Arial','Georgia'].includes(englishValue)?englishValue:'Inter';root.style.setProperty('--font-en',`"${english}", Arial, sans-serif`);
    const customEnabled=(adminForm?.elements?.theme_custom_enabled?.value??(policy.theme_custom_enabled?'1':'0'))==='1';const customPrimary=adminForm?.elements?.theme_custom_primary?.value||policy.theme_custom_primary;const customSecondary=adminForm?.elements?.theme_custom_secondary?.value||policy.theme_custom_secondary;const customSurface=adminForm?.elements?.theme_custom_surface?.value||policy.theme_custom_surface;const customBackground=adminForm?.elements?.theme_custom_background?.value||policy.theme_custom_background;
    if(customEnabled&&!hasUserPalette){root.dataset.customColors='enabled';if(/^#[0-9a-f]{6}$/i.test(customPrimary||''))root.style.setProperty('--brand-primary',customPrimary);if(/^#[0-9a-f]{6}$/i.test(customSecondary||''))root.style.setProperty('--brand-secondary',customSecondary);if(/^#[0-9a-f]{6}$/i.test(customSurface||''))root.style.setProperty('--theme-custom-surface',customSurface);if(/^#[0-9a-f]{6}$/i.test(customBackground||''))root.style.setProperty('--theme-custom-background',customBackground)}else{delete root.dataset.customColors;root.style.removeProperty('--brand-primary');root.style.removeProperty('--brand-secondary');root.style.removeProperty('--theme-custom-surface');root.style.removeProperty('--theme-custom-background')}
    ['theme_button_style','theme_card_style','theme_header_style','theme_sidebar_style','theme_footer_style','theme_layout'].forEach(key=>{if(policy[key])root.setAttribute(`data-${key.replace('theme_','').replaceAll('_','-')}`,policy[key])});
    root.setAttribute('data-motion', ['off','subtle','smooth','dynamic'].includes(policy.theme_motion) ? policy.theme_motion : 'smooth');
    const modeDefault=adminForm?.elements?.theme_mode_default?.value||policy.theme_mode_default;let mode=modeDefault==='dark'?'dark':'light';if(canChoose&&!admin){try{const saved=localStorage.getItem('nbc_theme');if(saved==='dark'||saved==='light')mode=saved}catch(_){}}
    setMode(mode,false);
    document.querySelectorAll('#landingThemeToggle,#themeToggle,#loginThemeToggle,#themeToggleBtn').forEach(btn=>{btn.hidden=!canChoose&&!admin;btn.setAttribute('aria-hidden',String(!canChoose&&!admin))});
    document.querySelectorAll('[data-medical-preset]').forEach(card=>{const selected=card.dataset.medicalPreset===normalize(policy.theme_preset);card.setAttribute('aria-checked',String(selected))});
    if(!document.getElementById('userThemeSelect')&&canChoose&&!admin){const anchor=document.querySelector('#landingThemeToggle,#themeToggleBtn,#themeToggle');if(anchor){const select=document.createElement('select');select.id='userThemeSelect';select.className='theme-user-select';select.setAttribute('aria-label','اختيار هوية المنصة');select.innerHTML=presets.map(p=>`<option value="${p}">${labels[p]}</option>`).join('');select.value=palette;select.addEventListener('change',()=>{setPalette(select.value,true);apply(policy)});anchor.insertAdjacentElement('afterend',select)}}
    const userSelect=document.getElementById('userThemeSelect');if(userSelect){userSelect.hidden=!canChoose;userSelect.value=palette}
    const mobileSelect=document.getElementById('mobileThemeSelect');if(mobileSelect){mobileSelect.innerHTML=presets.map(p=>`<option value="${p}">${labels[p]}</option>`).join('');mobileSelect.value=palette;mobileSelect.hidden=!canChoose;if(!mobileSelect.dataset.bound){mobileSelect.addEventListener('change',()=>{setPalette(mobileSelect.value,true);apply(policy)});mobileSelect.dataset.bound='1'}}
  }
  window.applyMedicalThemeSettings=apply;
  document.addEventListener('click',event=>{const btn=event.target.closest('#landingThemeToggle,#themeToggle,#loginThemeToggle,#themeToggleBtn');if(!btn)return;if(!policy.theme_user_choice_enabled&&!location.pathname.endsWith('admin.html')){event.preventDefault();event.stopImmediatePropagation();return}event.preventDefault();event.stopImmediatePropagation();setMode(root.dataset.theme==='dark'?'light':'dark',true)},true);
  try{setPalette(normalize(localStorage.getItem('nbc_user_medical_theme')||'royal-red'),false);setMode(localStorage.getItem('nbc_theme')||'light',false)}catch(_){setPalette('royal-red',false);setMode('light',false)}
  const scriptUrl=document.currentScript?.src;if(!scriptUrl)return;
  fetch(new URL('../../api/public/theme.php',scriptUrl).href,{headers:{Accept:'application/json'},cache:'no-store'}).then(r=>r.ok?r.json():null).then(data=>{if(data?.success)apply(data)}).catch(()=>{});
})();
