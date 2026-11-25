'use strict';
(() => {
  const endpoint='api/student/assistant.php';
  const $=id=>document.getElementById(id);
  const t=(ar,en)=>typeof currentLang!=='undefined'&&currentLang==='en'?en:ar;
  let history=[];
  function append(role,message){const node=document.createElement('div');node.className=`student-ai-message is-${role}`;node.textContent=message;$('studentAiAssistantMessages').appendChild(node);$('studentAiAssistantMessages').scrollTop=$('studentAiAssistantMessages').scrollHeight;}
  async function open(){const modal=$('studentAiAssistantModal');modal.hidden=false;$('studentAiAssistantInput').focus();try{const state=await requestJSON(endpoint);const input=$('studentAiAssistantInput'),button=$('studentAiAssistantSend');if(!state.can_chat){$('studentAiAssistantNotice').textContent=state.message||t('المساعد غير متاح أثناء الاختبار.','The assistant is unavailable during an exam.');input.disabled=true;button.disabled=true;return;}const ready=!!state.configured;$('studentAiAssistantNotice').textContent=ready?t('المساعد للدراسة فقط، ولا يقدم إجابات أثناء الاختبارات أو نصائح علاجية.','Study support only; no exam answers or patient-specific treatment advice.'):t('المساعد يحتاج إعداد مفتاح الذكاء الاصطناعي على الخادم.','The assistant requires an AI key configured on the server.');input.disabled=!ready;button.disabled=!ready;}catch(error){$('studentAiAssistantNotice').textContent=error.message;}}
  async function send(event){event.preventDefault();const input=$('studentAiAssistantInput'),button=$('studentAiAssistantSend'),content=input.value.trim();if(!content||button.disabled)return;history.push({role:'user',content});append('user',content);input.value='';input.disabled=true;button.disabled=true;const waiting=document.createElement('div');waiting.className='student-ai-message is-assistant is-typing';waiting.textContent=t('المساعد يكتب…','Assistant is typing…');$('studentAiAssistantMessages').appendChild(waiting);try{const result=await requestJSON(endpoint,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({messages:history.slice(-10)})});waiting.remove();history.push({role:'assistant',content:result.reply});history=history.slice(-10);append('assistant',result.reply);}catch(error){waiting.remove();append('assistant',`${t('تعذر الرد: ','Could not reply: ')}${error.message}`);}finally{input.disabled=false;button.disabled=false;input.focus();}}
  function bind(){
    $('studentAiAssistantButton')?.addEventListener('click',open);
    $('studentAiAssistantForm')?.addEventListener('submit',send);
    $('studentAiAssistantInput')?.addEventListener('keydown',event=>{if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();$('studentAiAssistantForm').requestSubmit();}});
    $('studentAiAssistantModal')?.addEventListener('click',event=>{if(event.target===event.currentTarget||event.target.closest('[data-close-student-ai]'))$('studentAiAssistantModal').hidden=true;});
    document.addEventListener('keydown',event=>{if(event.key==='Escape'&&!$('studentAiAssistantModal').hidden)$('studentAiAssistantModal').hidden=true;});
  }
  document.addEventListener('DOMContentLoaded',bind);
})();
