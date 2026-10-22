/* Display-only bridge for sandboxed MietMichBox booking embeds. No form data is read or sent. */
(()=>{'use strict';
if(window.MMBCustomerEmbedHost)return;window.MMBCustomerEmbedHost=true;
const frames=()=>Array.from(document.querySelectorAll('iframe')).filter(frame=>{const html=frame.getAttribute('srcdoc')||'';return /id=["']mmb-customer["']/.test(html)&&html.includes('configurator.js');});
const headerHeight=()=>document.querySelector('header')?.getBoundingClientRect().height||80;
const send=frame=>{const layout=frame.closest?.('.block-layout');if(layout){layout.style.maxWidth='1224px';layout.style.width='100%';}const height=Math.max(240,Math.min(860,(window.visualViewport?.height||window.innerHeight)-headerHeight()-24));frame.contentWindow?.postMessage({kind:'mmb-booking-viewport',version:1,height},'*');frame.style.scrollMarginTop=(headerHeight()+12)+'px';};
const refresh=()=>frames().forEach(send);
window.addEventListener('message',event=>{
 if(event.data?.version!==1||!['mmb-booking-ready','mmb-booking-focus'].includes(event.data?.kind))return;
 const frame=frames().find(f=>f.contentWindow===event.source);if(!frame)return;
 send(frame);
 if(event.data.kind==='mmb-booking-focus')window.scrollTo({top:Math.max(0,window.scrollY+frame.getBoundingClientRect().top-headerHeight()-12),behavior:'instant'});
});
window.addEventListener('resize',refresh);window.visualViewport?.addEventListener('resize',refresh);
window.addEventListener('load',refresh);if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',refresh,{once:true});else refresh();
})();
