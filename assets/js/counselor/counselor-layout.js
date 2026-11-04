/* Shared counselor controls v17: desktop pin, mobile drawer, notifications. */
(function () {
  'use strict';
  const toggle = document.getElementById('counselorMenuToggle');
  const sidebar = document.getElementById('counselorSidebar');
  const backdrop = document.getElementById('counselorSidebarBackdrop');
  if (!toggle || !sidebar || !backdrop) return;
  const desktop = window.matchMedia('(min-width: 901px)');
  let previousFocus = null;
  function sync() {
    const mobileOpen = !desktop.matches && document.body.classList.contains('counselor-menu-open');
    const pinned = desktop.matches && document.body.classList.contains('counselor-sidebar-pinned');
    toggle.setAttribute('aria-expanded', String(mobileOpen || pinned));
    toggle.setAttribute('aria-label', desktop.matches ? (pinned ? 'Unpin sidebar' : 'Pin sidebar') : (mobileOpen ? 'Close navigation menu' : 'Open navigation menu'));
    // On mobile, retain the backdrop element while closed so opacity can
    // transition smoothly in both directions. On desktop it stays hidden.
    backdrop.hidden = desktop.matches;
    document.body.style.overflow = mobileOpen ? 'hidden' : '';
  }
  function closeMobile(restoreFocus) {
    if (!document.body.classList.contains('counselor-menu-open')) return;
    document.body.classList.remove('counselor-menu-open');
    sync();
    if (restoreFocus) toggle.focus();
  }
  toggle.addEventListener('click', function () {
    if (desktop.matches) {
      document.body.classList.toggle('counselor-sidebar-pinned');
    } else {
      previousFocus = document.activeElement;
      document.body.classList.toggle('counselor-menu-open');
    }
    sync();
  });
  backdrop.addEventListener('click', () => closeMobile(true));
  sidebar.addEventListener('click', e => {
    if (!desktop.matches && e.target.closest('.counselor-nav-link, .counselor-logout-link')) closeMobile(false);
  });
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && !desktop.matches) closeMobile(true);
  });
  desktop.addEventListener('change', () => {
    document.body.classList.remove('counselor-menu-open');
    sync();
  });
  sync();
})();

/* Shared counselor notifications. Not coupled to Dashboard AJAX DOM replacement. */
(function () {
    'use strict';
    const root=document.querySelector('[data-notification-component]');
    const toggle=document.getElementById('counselorNotificationToggle');
    const panel=document.getElementById('counselorNotificationPanel');
    const indicator=document.getElementById('counselorNotificationIndicator');
    const badge=document.getElementById('counselorNotificationCount');
    const list=document.getElementById('counselorNotificationList');
    const summary=document.getElementById('counselorNotificationSummary');
    const markAll=document.getElementById('counselorNotificationMarkAll');
    const closeButton=document.getElementById('counselorNotificationClose');
    if(!root||!toggle||!panel||!list)return;
    let loading=false, actionPending=false;
    function close(restore=false){panel.hidden=true;toggle.setAttribute('aria-expanded','false');if(restore)toggle.focus();}
    function formatDate(text){const d=new Date(String(text).replace(' ','T'));return Number.isNaN(d.getTime())?'':new Intl.DateTimeFormat('en-PH',{month:'short',day:'numeric',hour:'numeric',minute:'2-digit'}).format(d);}
    function make(tag,cls,content){const el=document.createElement(tag);if(cls)el.className=cls;if(content!==undefined)el.textContent=content;return el;}
    function render(data){
      const unread=Math.max(0,Number(data.unread_count)||0);
      badge.hidden=unread===0;indicator.hidden=unread===0;
      if(unread){badge.textContent=unread>99?'99+':String(unread);toggle.setAttribute('aria-label',unread+' unread notifications');}
      else {badge.textContent='';toggle.setAttribute('aria-label','Open notifications');}
      summary.textContent=unread+' unread';markAll.disabled=unread===0||actionPending;
      list.replaceChildren();
      if(!Array.isArray(data.items)||!data.items.length){list.appendChild(make('p','notification-panel-empty','No pending assessment notifications.'));return;}
      for(const item of data.items){
        const row=make('div','notification-item'+(item.is_read?' is-read':' is-unread'));
        const info=make('div','notification-item-copy');
        const title=make('a','notification-item-title','New Needs Assessment · #'+item.assessment_id);
        title.href='view_assessment.php?id='+encodeURIComponent(item.assessment_id);
        info.append(title,make('small','',String(item.student_name||'Student')+' · '+formatDate(item.date)));
        row.appendChild(info);
        if(!item.is_read){const action=make('button','notification-read-button','Mark read');action.type='button';action.addEventListener('click',()=>markRead(item.assessment_id));row.appendChild(action);}
        list.appendChild(row);
      }
    }
    async function fetchData(){
      if(loading||document.visibilityState==='hidden')return;
      loading=true;
      try{const response=await fetch('notifications.php?_='+Date.now(),{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});
        if(!response.ok)throw new Error('Notification request failed');
        const data=await response.json();if(!data.success)throw new Error('Notification response invalid');render(data);
      }catch(err){if(!panel.hidden)list.replaceChildren(make('p','notification-panel-empty','Notifications unavailable. Try again shortly.'));console.error('Notifications:',err);}
      finally{loading=false;}
    }
    async function write(action,assessmentId){
      if(actionPending)return;actionPending=true;markAll.disabled=true;
      try{const body=new URLSearchParams({action,csrf_token:root.dataset.csrf||''});if(assessmentId)body.set('assessment_id',String(assessmentId));
        const response=await fetch('notifications.php',{method:'POST',credentials:'same-origin',body,headers:{Accept:'application/json'}});
        const data=await response.json();if(!response.ok||!data.success)throw new Error('Unable to mark notification as read');
      }catch(err){console.error('Notifications:',err);list.prepend(make('p','notification-panel-error','Update failed. Please retry.'));}
      finally{actionPending=false;await fetchData();}
    }
    function markRead(id){write('mark_read',id);}
    toggle.addEventListener('click',()=>{const open=panel.hidden;panel.hidden=!open;toggle.setAttribute('aria-expanded',String(open));if(open)fetchData();});
    closeButton?.addEventListener('click',()=>close(true));
    markAll?.addEventListener('click',()=>write('mark_all_read'));
    document.addEventListener('click',e=>{if(!root.contains(e.target))close();});
    document.addEventListener('keydown',e=>{if(e.key==='Escape'&&!panel.hidden)close(true);});
    fetchData();window.setInterval(fetchData,5000);
    document.addEventListener('visibilitychange',()=>{if(document.visibilityState==='visible')fetchData();});
})();
