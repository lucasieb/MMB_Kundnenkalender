/* Shared, keyboard-accessible date picker. No network or customer-data persistence. */
((scope)=>{'use strict';
const iso=d=>`${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
const parse=s=>new Date(s+'T12:00:00');
const add=(s,n)=>{const d=parse(s);d.setDate(d.getDate()+n);return iso(d);};
function chooseRange(state,date){
 if(!state.start||state.end||date<state.start)return {start:date,end:''};
 if(date===state.start)return {start:date,end:''};
 return {start:state.start,end:date};
}
function open({start='',end='',value='',birth=false,onSelect,opener,min=iso(new Date()),max=''}){
 const dialog=document.createElement('dialog');dialog.className='mmb-date-dialog';dialog.setAttribute('aria-label',birth?'Geburtsdatum auswählen':'Mietzeitraum auswählen');
 const selected=birth?value:start;let month=parse(selected||(birth?'1990-01-01':min));month.setDate(1);
 let draft={start:'',end:''},closed=false;
 const close=()=>{if(closed)return;closed=true;dialog.close();dialog.remove();opener?.focus({preventScroll:true});};
 const render=(focusDate='')=>{
  const year=month.getFullYear(),m=month.getMonth(),offset=(new Date(year,m,1).getDay()+6)%7,last=new Date(year,m+1,0).getDate();
  const from=draft.start||start,until=draft.start?draft.end:end;
  const months=Array.from({length:12},(_,i)=>new Date(2000,i,1).toLocaleDateString('de-DE',{month:'long'}));
  const minYear=birth?1900:parse(min).getFullYear(),maxYear=birth?new Date().getFullYear():Math.max(year,minYear+6);
  let days=Array.from({length:offset},()=>'<span aria-hidden="true"></span>');
  for(let n=1;n<=last;n++){
   const date=iso(new Date(year,m,n)),disabled=(birth?date<'1900-01-01':date<min)||(max&&date>max),edge=birth?date===value:date===from||date===until;
   const between=!birth&&from&&until&&date>from&&date<until;
   days.push(`<button type="button" data-day="${date}" ${disabled?'disabled':''} class="${edge?'is-edge ':''}${between?'is-range':''}" aria-pressed="${!!edge}" aria-label="${parse(date).toLocaleDateString('de-DE',{day:'numeric',month:'long',year:'numeric'})}">${n}</button>`);
  }
  dialog.innerHTML=`<div class="mmb-picker-heading"><h2>${birth?'Geburtsdatum':'Dein Mietzeitraum'}</h2><button type="button" data-close aria-label="Kalender schließen">×</button></div><p class="mmb-picker-help" aria-live="polite">${birth?'Monat und Jahr wählen, dann deinen Geburtstag anklicken.':draft.start?'Jetzt Rückgabedatum wählen. Ein früherer Tag setzt den Mietbeginn neu.':'Erster Klick: Mietbeginn · zweiter Klick: Rückgabe.'}</p><div class="mmb-picker-month"><button type="button" data-month="-1" aria-label="Vorheriger Monat">‹</button><label><span class="mmb-sr">Monat</span><select data-select-month>${months.map((name,i)=>`<option value="${i}" ${i===m?'selected':''}>${name}</option>`).join('')}</select></label><label><span class="mmb-sr">Jahr</span><select data-select-year>${Array.from({length:maxYear-minYear+1},(_,i)=>minYear+i).map(y=>`<option ${y===year?'selected':''}>${y}</option>`).join('')}</select></label><button type="button" data-month="1" aria-label="Nächster Monat">›</button></div><div class="mmb-picker-week">${['Mo','Di','Mi','Do','Fr','Sa','So'].map(d=>`<span>${d}</span>`).join('')}</div><div class="mmb-picker-days">${days.join('')}</div>${!birth?'<p class="mmb-note">Ein Miettag = 24 Stunden. Rückgabe am Enddatum zur gleichen vereinbarten Uhrzeit. Für einen Miettag bitte den Folgetag wählen.</p>':'<button type="button" class="mmb-text-btn" data-clear>Geburtsdatum leer lassen</button>'}`;
  dialog.querySelector('[data-close]').onclick=close;
  dialog.querySelectorAll('[data-month]').forEach(b=>b.onclick=()=>{month.setMonth(month.getMonth()+Number(b.dataset.month));if(month.getFullYear()<minYear)month=new Date(minYear,0,1);if(birth&&month.getFullYear()>maxYear)month=new Date(maxYear,11,1);render();});
  dialog.querySelector('[data-select-month]').onchange=e=>{month.setMonth(Number(e.target.value));render();dialog.querySelector('[data-select-month]').focus();};
  dialog.querySelector('[data-select-year]').onchange=e=>{month.setFullYear(Number(e.target.value));render();dialog.querySelector('[data-select-year]').focus();};
  dialog.querySelectorAll('[data-day]').forEach(b=>b.onclick=()=>{if(birth){const date=b.dataset.day;close();onSelect(date);return;}draft=chooseRange(draft,b.dataset.day);if(draft.end){const result=draft;close();onSelect(result);}else render(draft.start);});
  dialog.querySelector('[data-clear]')?.addEventListener('click',()=>{close();onSelect('');});
  if(focusDate)dialog.querySelector(`[data-day="${focusDate}"]`)?.focus();
 };
 dialog.addEventListener('cancel',e=>{e.preventDefault();close();});
 dialog.addEventListener('click',e=>{if(e.target===dialog){const r=dialog.getBoundingClientRect();if(e.clientX<r.left||e.clientX>r.right||e.clientY<r.top||e.clientY>r.bottom)close();}});
 dialog.addEventListener('keydown',e=>{
  const day=e.target.closest('[data-day]');if(!day)return;
  const shifts={ArrowLeft:-1,ArrowRight:1,ArrowUp:-7,ArrowDown:7};if(!(e.key in shifts))return;e.preventDefault();const next=add(day.dataset.day,shifts[e.key]);
  if((birth?next<'1900-01-01':next<min)||(max&&next>max))return;
  month=parse(next);month.setDate(1);render(next);
 });
 document.body.append(dialog);render();dialog.showModal();dialog.querySelector(`[data-day="${selected}"]`)?.focus();
 return {close};
}
const api={open,chooseRange,add,iso};scope.MMBDatePicker=api;if(typeof module!=='undefined'&&module.exports)module.exports=api;
})(typeof globalThis!=='undefined'?globalThis:this);
