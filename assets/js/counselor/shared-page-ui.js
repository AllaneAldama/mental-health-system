/* PTC Wellness | Shared Counselor loading and logout behavior.
   One shared file for every page using includes/counselor/sidebar.php.
   Capturing logout clicks prevents older per-page logout handlers from opening
   a second dialog; older PHP/JS is deliberately left untouched. */
(() => {
  'use strict';
  if (window.__ptcSharedPageUiInitialized) return;
  window.__ptcSharedPageUiInitialized = true;

  const loader = document.getElementById('ptcPageLoader');
  const overlay = document.getElementById('ptcSharedLogout');
  const dialog = overlay?.querySelector('.ptc-logout-card');
  const cancel = document.getElementById('ptcSharedLogoutCancel');
  const confirm = document.getElementById('ptcSharedLogoutConfirm');
  let previousFocus = null;
  let previousOverflow = '';
  let logoutHref = '../auth/logout.php?portal=counselor';
  let closeTimer = null;
  let overlayIsOpen = false;

  function finishLoading() {
    loader?.classList.add('ptc-loading-finished');
  }
  function showLoading() {
    if (loader && !overlayIsOpen) loader.classList.remove('ptc-loading-finished');
  }

  // No artificial wait: loader clears as soon as the document finishes loading.
  if (document.readyState === 'complete') finishLoading();
  else window.addEventListener('load', finishLoading, { once: true });
  window.addEventListener('pageshow', finishLoading); // bfcache/browser Back

  function closeLogout() {
    if (!overlay || !overlayIsOpen) return;
    overlayIsOpen = false;
    overlay.classList.remove('is-open');
    document.body.style.overflow = previousOverflow;
    const focusTarget = previousFocus;
    if (closeTimer) window.clearTimeout(closeTimer);
    closeTimer = window.setTimeout(() => { if (!overlayIsOpen) overlay.hidden = true; }, 190);
    if (focusTarget?.isConnected) focusTarget.focus({ preventScroll: true });
  }
  function openLogout(link) {
    if (!overlay || !cancel || !confirm || !dialog) return;
    logoutHref = link?.href || logoutHref;
    previousFocus = document.activeElement;
    previousOverflow = document.body.style.overflow;
    if (closeTimer) window.clearTimeout(closeTimer);
    overlay.hidden = false;
    overlayIsOpen = true;
    document.body.style.overflow = 'hidden';
    // Two animation frames allow the CSS opacity transition without reflow of content.
    window.requestAnimationFrame(() => overlay.classList.add('is-open'));
    cancel.focus({ preventScroll: true });
  }

  // Capture before the local per-page click listeners, which normally run at target/bubble.
  document.addEventListener('click', (event) => {
    const target = event.target;
    if (!(target instanceof Element)) return;
    const anchor = target.closest('a[href]');
    if (!anchor || event.defaultPrevented || event.button !== 0 ||
        event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    let url;
    try { url = new URL(anchor.href, window.location.href); } catch { return; }
    const isCounselorLogout = anchor.matches('.counselor-logout-link') ||
      (url.origin === window.location.origin && /\/auth\/logout\.php$/i.test(url.pathname));
    if (isCounselorLogout) {
      event.preventDefault();
      event.stopImmediatePropagation();
      openLogout(anchor);
    }
  }, true);

  cancel?.addEventListener('click', closeLogout);
  confirm?.addEventListener('click', () => {
    if (!overlayIsOpen) return;
    showLoading();
    window.location.assign(logoutHref);
  });
  overlay?.addEventListener('click', (event) => {
    if (event.target === overlay) closeLogout();
  });
  document.addEventListener('keydown', (event) => {
    if (!overlayIsOpen) return;
    if (event.key === 'Escape') { event.preventDefault(); closeLogout(); return; }
    if (event.key !== 'Tab') return;
    const controls = [cancel, confirm].filter((el) => el && !el.disabled);
    if (!controls.length) { event.preventDefault(); dialog?.focus(); return; }
    const first = controls[0];
    const last = controls[controls.length - 1];
    if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
    else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
  });

  // Only regular same-origin GET navigation for counselor HTML pages.
  // Do not touch AJAX/fetch, POST forms, calendar month loading or action buttons.
  document.addEventListener('click', (event) => {
    if (event.defaultPrevented || event.button !== 0 || event.ctrlKey ||
        event.shiftKey || event.metaKey || event.altKey) return;
    const target = event.target;
    if (!(target instanceof Element)) return;
    const anchor = target.closest('a[href]');
    if (!anchor || anchor.hasAttribute('download') || anchor.target === '_blank' ||
        anchor.hasAttribute('onclick')) return;
    let url;
    try { url = new URL(anchor.href, window.location.href); } catch { return; }
    if (url.origin !== window.location.origin || !/\/counselor\/[^/]+\.php$/i.test(url.pathname) ||
        /logout\.php$/i.test(url.pathname)) return;
    const here = new URL(window.location.href);
    if (url.pathname === here.pathname && url.search === here.search) return;
    // Microtask checks defaultPrevented after the page's existing event handlers run.
    queueMicrotask(() => { if (!event.defaultPrevented) showLoading(); });
  }, false);
})();
