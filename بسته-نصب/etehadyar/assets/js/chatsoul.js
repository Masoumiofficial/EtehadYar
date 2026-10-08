document.addEventListener('DOMContentLoaded', ()=>{
  if(!window.EAIW_SOUL) return;
  const s=EAIW_SOUL;
  // mobile toggle
  if(s.mobile==='0' || s.mobile===0 || s.mobile===false){
    if(window.innerWidth<768) return;
  }
  const name=s.name||'اتحادیار';
  const greeting=s.greeting||`سلام! من ${name} هستم؛ چگونه می‌توانم کمک کنم؟`;
  const color=s.color||'#6d28ff';
  const size=s.size||'medium'; // small 340x380, medium 372x460, large 400x540
  const avatar=s.avatar||'';
  const pos=s.position||'bottom-right';
  const ox=parseInt(s.offset_x||22), oy=parseInt(s.offset_y||22);
  const faqs=s.faqs||[];
  // size map
  const dims={small:{w:340,h:380}, medium:{w:372,h:460}, large:{w:400,h:540}}[size]||{w:372,h:460};
  // position
  const isBottom=pos.includes('bottom'), isRight=pos.includes('right');
  const btnPos=isBottom?`bottom:${oy}px;`:`top:${oy}px;`;
  const panelPos=isBottom?`bottom:${oy+66}px;`:`top:${oy+66}px;`;
  const hPos=isRight?`right:${ox}px;`:`left:${ox}px;`;

  const wrap=document.createElement('div');
  wrap.innerHTML=`
    <style>
      #eaiwSoulPanel{max-height:calc(100vh - 110px)!important;box-sizing:border-box!important}
      #eaiwSoulQuick{max-height:54px!important}
      #eaiwSoulLog{color:#263238!important}
      #eaiwSoulPanel>div:last-of-type{flex-shrink:0!important}
      #eaiwSoulPanel{background:rgba(255,255,255,.68)!important;border:1px solid rgba(255,255,255,.75)!important;border-radius:24px!important;box-shadow:0 24px 70px rgba(15,23,42,.22)!important;overflow:hidden!important}
      #eaiwSoulPanel>div:first-of-type{background:linear-gradient(135deg,rgba(36,69,107,.96),rgba(20,143,145,.90))!important}
      #eaiwSoulQuick{background:rgba(255,255,255,.30)!important;border-bottom:1px solid rgba(36,69,107,.12)!important}
      #eaiwSoulLog{background:rgba(247,250,252,.48)!important;color:#263238!important}
      #eaiwSoulPanel>div:last-of-type{background:rgba(255,255,255,.52)!important;border-top:1px solid rgba(255,255,255,.7)!important}
      #eaiwSoulInput{background:rgba(255,255,255,.76)!important;color:#263238!important;border:1px solid rgba(36,69,107,.18)!important;box-shadow:inset 0 1px 2px rgba(15,23,42,.04)!important}
      #eaiwSoulInput::placeholder{color:#77838a!important}
      #eaiwSoulSend{background:#24456b!important;box-shadow:0 5px 14px rgba(36,69,107,.24)!important}
      #eaiwSoulBtn{background:linear-gradient(135deg,#24456b,#148f91)!important;animation:eaiwPulseSoft 2.8s infinite!important}
      @keyframes eaiwPulseSoft{0%,100%{box-shadow:0 8px 24px rgba(36,69,107,.22)}50%{box-shadow:0 10px 30px rgba(20,143,145,.38)}}
      @media(max-width:600px){#eaiwSoulPanel{width:calc(100vw - 24px)!important;height:min(78vh,600px)!important;right:12px!important;left:12px!important;bottom:78px!important;max-width:none!important}#eaiwSoulBtn{right:16px!important;bottom:16px!important}}
      @media(prefers-reduced-motion:reduce){#eaiwSoulBtn{animation:none!important}}
      @keyframes eaiwPulse{0%,100%{box-shadow:0 0 0 0 rgba(109,40,255,.4)} 50%{box-shadow:0 0 0 10px rgba(109,40,255,0)}}
      @keyframes eaiwBounce{0%,100%{transform:translateY(0)} 50%{transform:translateY(-3px)}}
      #eaiwSoulBtn{animation:eaiwPulse 2.2s infinite}
      #eaiwSoulPanel{backdrop-filter:blur(16px); -webkit-backdrop-filter:blur(16px)}
      #eaiwSoulLog::-webkit-scrollbar{width:6px}
      #eaiwSoulLog::-webkit-scrollbar-thumb{background:rgba(255,255,255,.15); border-radius:999px}
    </style>
    <div id="eaiwSoulBtn" style="position:fixed; ${btnPos} ${hPos} z-index:9999; width:58px; height:58px; border-radius:50%; background:conic-gradient(from 0deg,${color},#00e5ff,#ff2e97,${color}); display:grid; place-items:center; cursor:pointer; box-shadow:0 8px 24px rgba(0,0,0,.28); transition:.2s">
      <div style="width:52px; height:52px; border-radius:50%; background:#0a0f1f; display:grid; place-items:center; overflow:hidden; border:2px solid rgba(255,255,255,.9)">
        ${avatar ? `<img src="${avatar}" style="width:100%; height:100%; object-fit:cover">` : `<span style="font-size:1.45rem">💬</span>`}
      </div>
    </div>
    <div id="eaiwSoulPanel" style="position:fixed; ${panelPos} ${hPos} z-index:9999; width:min(${dims.w}px,calc(100vw - 24px)); max-width:calc(100vw - 24px); height:min(${dims.h}px,calc(100vh - 110px)); max-height:calc(100vh - 110px); background:rgba(255,255,255,.72); border:1px solid rgba(255,255,255,.65); border-radius:22px; overflow:hidden; display:none; flex-direction:column; box-shadow:0 18px 55px rgba(31,41,55,.18); font-family:Vazirmatn,Tahoma,sans-serif; backdrop-filter:blur(22px)">
      <div style="padding:12px 14px; background:linear-gradient(90deg,${color},#4f46e5); color:white; display:flex; justify-content:space-between; align-items:center; gap:8px">
        <div style="display:flex; gap:10px; align-items:center">
          <div style="width:32px; height:32px; border-radius:50%; background:rgba(255,255,255,.18); display:grid; place-items:center; overflow:hidden; border:1px solid rgba(255,255,255,.3)">
            ${avatar ? `<img src="${avatar}" style="width:100%; height:100%; object-fit:cover">` : `😎`}
          </div>
          <div><b style="font-size:.95rem">${name}</b><br><span style="font-size:.72rem; opacity:.85">پشتیبان آنلاین</span></div>
        </div>
        <span id="eaiwSoulClose" style="cursor:pointer; background:rgba(255,255,255,.18); width:28px; height:28px; display:grid; place-items:center; border-radius:50%; transition:.15s">✕</span>
      </div>
      <div id="eaiwSoulQuick" style="padding:8px 10px; display:flex; gap:6px; flex-wrap:wrap; border-bottom:1px solid rgba(255,255,255,.06); background:rgba(255,255,255,.03); max-height:70px; overflow:auto"></div>
      <div id="eaiwSoulHandoff" style="display:none; padding:8px 10px; text-align:center; background:rgba(20,143,145,.08); border-top:1px solid rgba(20,143,145,.18); font-size:.78rem"><span>پاسخ خود را پیدا نکردید؟</span><input id="eaiwSoulExpertName" placeholder="نام" style="width:70px;border:1px solid #ccd6df;border-radius:6px;padding:4px;font-family:inherit"><input id="eaiwSoulExpertContact" placeholder="تلفن یا ایمیل" style="width:110px;border:1px solid #ccd6df;border-radius:6px;padding:4px;font-family:inherit"> <button type="button" id="eaiwSoulAskExpert" style="border:0; background:#148f91; color:white; border-radius:8px; padding:5px 9px; cursor:pointer; font-family:inherit">درخواست کارشناس</button></div><div id="eaiwSoulLog" style="flex:1; overflow:auto; padding:12px; font-size:.88rem; color:#E6E8F2; background:rgba(248,250,252,.52)"></div>
      <div style="display:flex; gap:8px; padding:10px; border-top:1px solid rgba(255,255,255,.08); background:rgba(255,255,255,.58)">
        <input id="eaiwSoulInput" aria-label="متن پیام" placeholder="پیامت رو وارد کنید..." style="flex:1; background:rgba(255,255,255,.68); border:1px solid rgba(255,255,255,.8); color:#17324d; border-radius:999px; padding:11px 13px; outline:none; font-family:inherit; font-size:.9rem">
        <select id="eaiwSoulRate" title="سرعت پخش" style="background:rgba(255,255,255,.7);border:1px solid rgba(36,69,107,.18);color:#24456b;border-radius:8px;padding:4px;font-family:inherit"><option value=".85">آرام</option><option value=".95" selected>عادی</option><option value="1.1">سریع</option></select><button id="eaiwSoulStop" type="button" title="توقف پخش" style="background:rgba(255,255,255,.7);border:1px solid rgba(36,69,107,.18);color:#24456b;border-radius:8px;padding:8px;cursor:pointer">■</button><button id="eaiwSoulLive" aria-label="گفت‌وگوی زنده" type="button" title="گفت‌وگوی زنده" style="background:rgba(20,143,145,.1);border:1px solid rgba(20,143,145,.2);color:#176b6c;border-radius:999px;padding:11px 10px;cursor:pointer;font-family:inherit">زنده</button><button id="eaiwSoulRecord" aria-label="ثبت درخواست صوتی" type="button" title="ثبت درخواست صوتی" style="background:rgba(184,91,67,.1);border:1px solid rgba(184,91,67,.2);color:#8c3925;border-radius:999px;padding:11px 12px;cursor:pointer;font-family:inherit">●</button><button id="eaiwSoulMic" aria-label="گفتار فارسی" type="button" title="گفتار فارسی" style="background:rgba(36,69,107,.12); border:1px solid rgba(36,69,107,.18); color:#24456b; border-radius:999px; padding:11px 12px; cursor:pointer; font-family:inherit">🎙️</button><button id="eaiwSoulSend" aria-label="ارسال پیام" style="background:linear-gradient(90deg,${color},#00e5ff); border:none; color:#17324d; border-radius:999px; padding:11px 15px; font-weight:800; cursor:pointer; font-family:inherit; box-shadow:0 4px 12px rgba(109,40,255,.3)">ارسال</button>
      </div>
      <div style="padding:6px 10px; font-size:.70rem; color:#64748B; text-align:center; background:rgba(0,0,0,.18); border-top:1px solid rgba(255,255,255,.04)">قدرت گرفته از اتحاد وردپرس — etehadyar.ir • GapGPT + حافظه هوشمند</div>
    </div>
  `;
  document.body.appendChild(wrap);
  const record=document.getElementById('eaiwSoulRecord');let recorder=null,parts=[];record.onclick=async()=>{if(recorder&&recorder.state==='recording'){recorder.stop();return;}try{const stream=await navigator.mediaDevices.getUserMedia({audio:true});recorder=new MediaRecorder(stream);parts=[];recorder.ondataavailable=e=>{if(e.data.size)parts.push(e.data);};recorder.onstop=async()=>{stream.getTracks().forEach(t=>t.stop());record.textContent='●';const blob=new Blob(parts,{type:'audio/webm'});const fd=new FormData();fd.append('audio',blob,'support-request.webm');fd.append('message','درخواست صوتی کاربر');fd.append('session_id',sid);fd.append('name',document.getElementById('eaiwSoulExpertName')?.value||'');fd.append('contact',document.getElementById('eaiwSoulExpertContact')?.value||'');const res=await fetch(s.rest.replace('/chat','/support/audio'),{method:'POST',body:fd});const data=await res.json();addMsg('assistant',data.ticket?'درخواست صوتی ثبت شد. شناسه پیگیری: '+data.ticket:'ثبت درخواست صوتی انجام نشد.');};recorder.start();record.textContent='■';}catch(e){addMsg('assistant','دسترسی به میکروفون داده نشد؛ می‌توانید درخواست را متنی ثبت کنید.');}}; const live=document.getElementById('eaiwSoulLive'); let liveMode=false; live.onclick=()=>{liveMode=!liveMode;live.classList.toggle('is-live',liveMode);live.textContent=liveMode?'پایان':'زنده';if(recognition){recognition.continuous=liveMode;if(liveMode&&!recognition._active)recognition.start();else if(!liveMode&&recognition._active)recognition.stop();}}; const mic=document.getElementById('eaiwSoulMic'); const rate=document.getElementById('eaiwSoulRate'); const stopVoice=document.getElementById('eaiwSoulStop'); let voiceRate=parseFloat(rate.value)||.95; let faVoice=null; function pickPersianVoice(){const voices=window.speechSynthesis?window.speechSynthesis.getVoices():[];faVoice=voices.find(v=>/^fa(-|_)/i.test(v.lang))||null;} pickPersianVoice(); if(window.speechSynthesis)window.speechSynthesis.onvoiceschanged=pickPersianVoice; rate.onchange=()=>voiceRate=parseFloat(rate.value)||.95; stopVoice.onclick=()=>window.speechSynthesis&&window.speechSynthesis.cancel(); let recognition=null; const SpeechRecognition=window.SpeechRecognition||window.webkitSpeechRecognition; if(SpeechRecognition){recognition=new SpeechRecognition();recognition.lang='fa-IR';recognition.interimResults=true;recognition.continuous=false;recognition._active=false;recognition.onstart=()=>{recognition._active=true;mic.classList.add('is-listening');mic.textContent='⏹️';};recognition.onresult=e=>{let text='';for(let i=e.resultIndex;i<e.results.length;i++)text+=e.results[i][0].transcript;input.value=text;};recognition.onend=()=>{recognition._active=false;mic.classList.remove('is-listening');mic.textContent='🎙️';if(liveMode)setTimeout(()=>{try{recognition.start();}catch(e){}},250);};}else{mic.title='تشخیص گفتار فارسی در این مرورگر پشتیبانی نمی‌شود';mic.style.opacity='.45';} mic.onclick=()=>{if(!recognition){input.focus();return;}if(mic.classList.contains('is-listening'))recognition.stop();else recognition.start();}; const btn=document.getElementById('eaiwSoulBtn'), panel=document.getElementById('eaiwSoulPanel'), close=document.getElementById('eaiwSoulClose'), input=document.getElementById('eaiwSoulInput'), send=document.getElementById('eaiwSoulSend'), log=document.getElementById('eaiwSoulLog'), quick=document.getElementById('eaiwSoulQuick');
  let sid='soul-'+Math.random().toString(36).slice(2,8);
  // FAQ quick buttons
  if(faqs && faqs.length){
    faqs.slice(0,4).forEach(f=>{
      const b=document.createElement('button');
      b.textContent=f.q.slice(0,28);
      b.style.cssText='background:rgba(255,255,255,.07); border:1px solid rgba(255,255,255,.10); color:#C2C8E6; padding:5px 9px; border-radius:999px; font-size:.75rem; cursor:pointer; font-family:inherit';
      b.onclick=()=>{ input.value=f.q; ask(); };
      quick.appendChild(b);
    });
  } else {
    quick.innerHTML='<span style="font-size:.75rem; color:#94A3B8">سؤال خود را وارد کنید</span>';
  }
  function toggle(){ 
    const isOpen=panel.style.display==='flex';
    panel.style.display= isOpen?'none':'flex';
    if(!isOpen) { input.focus(); btn.style.transform='scale(.92)'; setTimeout(()=> btn.style.transform='scale(1)', 180); }
  }
  btn.onclick=toggle; close.onclick=toggle;
  // hover
  btn.onmouseenter=()=> btn.style.transform='scale(1.06)';
  btn.onmouseleave=()=> btn.style.transform='scale(1)';
  function addMsg(role, text, type){
    const div=document.createElement('div');
    const isUser=role==='user';
    div.style.cssText=`margin:8px 0; padding:10px 12px; border-radius:14px; max-width:88%; line-height:1.8; white-space:pre-wrap; word-wrap:break-word; font-size:.88rem; box-shadow:0 2px 8px rgba(15,23,42,.08); ${isUser?'background:#24456b; margin-left:auto; color:#fff; border:1px solid #24456b':'background:rgba(255,255,255,.72); border:1px solid rgba(36,69,107,.14); color:#263238; backdrop-filter:blur(8px)'}`;
    if(type==='faq') div.style.border='1px solid rgba(16,185,129,.25)';
    // bamze emoji for assistant
    if(!isUser && !text.includes('😎') && !text.includes('😄')) text=''+text;
    div.textContent=text; if(!isUser){const speak=document.createElement('button');speak.textContent='🔊 شنیدن';speak.type='button';speak.style.cssText='border:0;background:transparent;color:#24456b;cursor:pointer;font-size:.68rem;margin-top:5px';speak.onclick=()=>{if(window.speechSynthesis){const u=new SpeechSynthesisUtterance(text);u.lang='fa-IR';u.voice=faVoice||null;u.rate=voiceRate;window.speechSynthesis.cancel();window.speechSynthesis.speak(u);}};div.appendChild(speak);const fb=document.createElement('div');fb.innerHTML='<button type="button" data-rate="1" style="border:0;background:transparent;cursor:pointer">مفید بود</button><button type="button" data-rate="-1" style="border:0;background:transparent;cursor:pointer">نیاز به بهبود</button>';fb.style.cssText='font-size:.68rem;margin-top:5px;color:#65727a';fb.querySelectorAll('button').forEach(b=>b.onclick=()=>{fetch(s.rest.replace('/chat','/feedback'),{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({session_id:sid,rating:b.dataset.rate,message:text})});fb.textContent='بازخورد شما ثبت شد';});div.appendChild(fb);} log.appendChild(div); log.scrollTop=log.scrollHeight;
  }
  async function ask(){
    const q=input.value.trim(); if(!q) return;
    addMsg('user', q); input.value='';
    const thinking=document.createElement('div'); thinking.textContent='...'; thinking.style.cssText='margin:8px 0; padding:10px 12px; border-radius:14px; background:rgba(109,40,255,.10); border:1px solid rgba(109,40,255,.15); color:#C2C8E6; font-size:.85rem; display:inline-block'; log.appendChild(thinking); log.scrollTop=log.scrollHeight;
    try{
      const res=await fetch(s.rest, {method:'POST', headers:{'Content-Type':'application/json','X-WP-Nonce':s.nonce}, body:JSON.stringify({message:q, session_id:sid})});
      const data=await res.json();
      thinking.remove();
      if(!res.ok || !data.answer){ addMsg('assistant',data.message||'فعلاً نتوانستم پاسخ را آماده کنم. لطفاً پرسش را کوتاه‌تر بنویسید یا از کارشناس کمک بگیرید.'); return; }
      const answer=data.answer||'پاسخی دریافت نشد'; addMsg('assistant', answer, data.type); if(data.needs_expert===true||data.type==='fallback') document.getElementById('eaiwSoulHandoff').style.display='block';
      if(data.sources && data.sources.length && data.type!=='faq'){
        const src=document.createElement('div'); src.style.cssText='margin:6px 0 10px; font-size:.72rem; color:#94A3B8; display:flex; gap:6px; flex-wrap:wrap';
        src.innerHTML=data.sources.slice(0,2).map(x=> x.url ? `<a href="${x.url}" target="_blank" style="color:#22d3ee; text-decoration:none; background:rgba(34,211,238,.10); border:1px solid rgba(34,211,238,.18); padding:3px 7px; border-radius:999px">🔗 ${x.title.slice(0,22)}</a>` : '').join('');
        if(src.innerHTML) { log.appendChild(src); log.scrollTop=log.scrollHeight; }
      }
    } catch(e){ thinking.remove(); addMsg('assistant','وای، اینترنت لگ زد 😅 — دوباره بگو!'); }
  }
  document.getElementById('eaiwSoulAskExpert').onclick=async function(){const message=prompt('متن درخواست کارشناس را وارد کنید:',input.value||'لطفاً این گفتگو را بررسی کنید.');if(!message)return;const r=await fetch(s.rest.replace('/chat','/support'),{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({message:message,session_id:sid,name:document.getElementById('eaiwSoulExpertName').value,contact:document.getElementById('eaiwSoulExpertContact').value})});const d=await r.json();if(d.ticket){addMsg('assistant','درخواست شما ثبت شد. شناسه پیگیری: '+d.ticket+'\nکارشناس پس از بررسی پاسخ خواهد داد.');document.getElementById('eaiwSoulHandoff').style.display='none';try{localStorage.setItem('eaiw_support_ticket',d.ticket);localStorage.setItem('eaiw_support_token',d.access_token);}catch(e){};let checks=0;const watch=setInterval(async function(){if(++checks>180){clearInterval(watch);return;}try{const rr=await fetch(s.rest.replace('/chat','/support/'+d.ticket),{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({access_token:d.access_token})});const dd=await rr.json();if(dd.response){clearInterval(watch);addMsg('assistant','پاسخ کارشناس:\n'+dd.response);}else if(dd.status==='open'){document.getElementById('eaiwSoulHandoff').style.display='none';}}catch(e){}},10000);}else addMsg('assistant','ثبت درخواست انجام نشد. لطفاً دوباره تلاش کنید.');};
  send.onclick=ask; document.addEventListener('click',()=>{if(window.speechSynthesis&&liveMode)window.speechSynthesis.cancel();},{capture:true}); input.addEventListener('keydown', e=>{ if(e.key==='Enter') ask(); });
  setTimeout(()=> addMsg('assistant', greeting), 700);
  // typing indicator on focus
  input.addEventListener('focus', ()=> btn.style.animation='none');
});
