/* PTC Wellness — Email status popup shared by View Assessment + Monitoring.
   Only the compose-message greeting/signature and send feedback are owned here.
   Sending progress is indeterminate until the server confirms success or error. */
(function () {
    'use strict';

    function element(tag, className, content) {
        const el = document.createElement(tag);
        if (className) el.className = className;
        if (content !== undefined) el.textContent = content;
        return el;
    }

    function attach(settings) {
        const form = settings.form;
        const message = settings.message;
        const feedback = settings.feedback;
        if (!form || !message || !feedback) return null;

        // Leave the existing email compose dialog and page layout untouched.
        const greeting = element('div', 'ptc-email-composer-greeting');
        const signature = element('div', 'ptc-email-composer-signature');
        const closing = element('span', '', 'Best regards,');
        const senderName = element('strong', '', 'Guidance Counselor');
        const senderOffice = element('span', '', 'Guidance Counselor · PTC Wellness Guidance Office');
        signature.append(closing, senderName, senderOffice);
        message.parentNode.insertBefore(greeting, message);
        message.insertAdjacentElement('afterend', signature);

        // IMPORTANT: Popup is appended to <body>, not to the scrollable composer.
        const overlay = element('div', 'ptc-mail-status-overlay');
        overlay.hidden = true;
        overlay.setAttribute('aria-hidden', 'true');
        const dialog = element('section', 'ptc-mail-status-dialog');
        dialog.setAttribute('role', 'dialog');
        dialog.setAttribute('aria-modal', 'true');
        dialog.setAttribute('aria-labelledby', 'ptcMailStatusTitle');
        dialog.setAttribute('aria-describedby', 'ptcMailStatusDescription');
        dialog.setAttribute('tabindex', '-1');
        dialog.innerHTML = `
          <div class="ptc-mail-status-icon" aria-hidden="true">
            <svg class="ptc-mail-status-envelope" viewBox="0 0 104 94" fill="none" aria-hidden="true">
              <circle cx="52" cy="47" r="44" fill="#e8f5ea"/>
              <rect class="ptc-mail-letter" x="35" y="14" width="34" height="47" rx="4" fill="#fff" stroke="#91bd9b" stroke-width="2"/>
              <path class="ptc-mail-letter" d="M42 27h19M42 34h19M42 41h12" stroke="#91bd9b" stroke-linecap="round" stroke-width="2"/>
              <g class="ptc-mail-envelope-body">
                <rect x="20" y="38" width="64" height="42" rx="6" fill="#d7ecdb" stroke="#267447" stroke-width="3"/>
                <path d="M22 42L52 63L82 42" stroke="#267447" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
              </g>
            </svg>
            <svg class="ptc-mail-status-check" viewBox="0 0 72 72" fill="none" aria-hidden="true">
              <circle cx="36" cy="36" r="31" fill="#e1f3e7" stroke="#a7d4b5" stroke-width="2"/>
              <path d="m20 36 11 11 21-23" stroke="#287746" stroke-width="5" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <svg class="ptc-mail-status-error-icon" viewBox="0 0 72 72" fill="none" aria-hidden="true">
              <circle cx="36" cy="36" r="31" fill="#fff0ed" stroke="#e9b6ad" stroke-width="2"/>
              <path d="M36 20v23" stroke="#ba4d3b" stroke-width="5" stroke-linecap="round"/>
              <circle cx="36" cy="52" r="3" fill="#ba4d3b"/>
            </svg>
          </div>
          <p class="ptc-mail-status-eyebrow">PTC WELLNESS · EMAIL</p>
          <h2 id="ptcMailStatusTitle" class="ptc-mail-status-title"></h2>
          <p id="ptcMailStatusDescription" class="ptc-mail-status-description" role="status" aria-live="polite"></p>
          <div class="ptc-mail-status-track" aria-hidden="true"><span class="ptc-mail-status-fill"></span></div>
          <button type="button" class="ptc-mail-status-return" hidden>Back to Email</button>
        `;
        overlay.appendChild(dialog);
        document.body.appendChild(overlay);
        const title = dialog.querySelector('.ptc-mail-status-title');
        const description = dialog.querySelector('.ptc-mail-status-description');
        const returnButton = dialog.querySelector('.ptc-mail-status-return');
        const originModal = form.closest('.email-modal-overlay, .followup-email-overlay');
        let previousOverflow = null;
        let waitingTimer = null;
        let isPending = false;
        let state = '';

        function clearWaitingTimer() {
            if (waitingTimer !== null) window.clearTimeout(waitingTimer);
            waitingTimer = null;
        }
        function setNames(alias, counselorName) {
            const student = String(alias || '').trim() || 'Student';
            const counselor = String(counselorName || '').trim() || 'PTC Wellness Guidance Counselor';
            greeting.textContent = 'Dear ' + student + ',';
            senderName.textContent = counselor;
        }
        function show(stateName, heading, detail) {
            state = stateName;
            overlay.hidden = false;
            overlay.setAttribute('aria-hidden', 'false');
            overlay.className = 'ptc-mail-status-overlay is-' + stateName;
            title.textContent = heading;
            description.textContent = detail;
            returnButton.hidden = stateName !== 'error';
            if (previousOverflow === null) previousOverflow = document.body.style.overflow;
            document.body.style.overflow = 'hidden';
            // Keep the original composing dialog visible underneath, but inert to AT.
            if (originModal) originModal.setAttribute('aria-hidden', 'true');
            dialog.focus({preventScroll: true});
        }
        function hide() {
            clearWaitingTimer();
            overlay.hidden = true;
            overlay.setAttribute('aria-hidden', 'true');
            overlay.className = 'ptc-mail-status-overlay';
            if (previousOverflow !== null) document.body.style.overflow = previousOverflow;
            previousOverflow = null;
            if (originModal && originModal.classList.contains('is-open')) {
                originModal.setAttribute('aria-hidden', 'false');
            }
            if (originModal?.classList.contains('is-open')) {
                (settings.submit || message).focus({preventScroll: true});
            }
        }
        function reset() {
            isPending = false;
            state = '';
            form.removeAttribute('aria-busy');
            hide();
            feedback.textContent = ''; // No duplicate inline success/error message.
        }
        function start() {
            clearWaitingTimer();
            isPending = true;
            form.setAttribute('aria-busy', 'true');
            show('sending', 'Sending your email', 'Preparing your message and waiting for the mail server…');
            waitingTimer = window.setTimeout(function () {
                if (!isPending) return;
                description.textContent = 'Still waiting for the mail server to respond. Your message is not marked as sent yet.';
            }, 6000);
        }
        function succeed() {
            clearWaitingTimer();
            isPending = false;
            form.removeAttribute('aria-busy');
            show('success', 'Email sent successfully!', 'The mail server has accepted your message.');
        }
        function fail(errorMessage) {
            clearWaitingTimer();
            isPending = false;
            form.removeAttribute('aria-busy');
            show('error', 'Email could not be sent', String(errorMessage || 'Unable to confirm sending. Please review and try again.'));
        }

        // While sending, prevent dismissal or keyboard access to the composer.
        // In an error state, Escape returns to the email draft (not out of it).
        document.addEventListener('keydown', function (event) {
            if (overlay.hidden) return;
            if (event.key === 'Escape') {
                event.preventDefault();
                event.stopImmediatePropagation();
                if (state === 'error') hide();
            } else if (event.key === 'Tab') {
                event.preventDefault();
                event.stopImmediatePropagation();
                if (state === 'error') returnButton.focus({preventScroll: true});
                else dialog.focus({preventScroll: true});
            }
        }, true);
        returnButton.addEventListener('click', hide);
        setNames(settings.student, settings.counselor);
        return {setNames, reset, start, succeed, fail, isPending: () => isPending};
    }
    window.PTCEmailSending = Object.freeze({attach});
})();
