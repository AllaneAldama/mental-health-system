(() => {
    'use strict';

    const form = document.getElementById('assessment-submit-form');
    const modal = document.getElementById('submission-modal');
    if (!form || !modal) return;

    const submitButton = document.getElementById('assessment-submit-button');
    const title = document.getElementById('submission-modal-title');
    const description = document.getElementById('submission-modal-description');
    const footnote = document.getElementById('submission-modal-footnote');
    const fill = document.getElementById('submission-progress-fill');
    const mail = document.getElementById('submission-mail');
    const continueButton = document.getElementById('submission-continue');
    const stages = [...modal.querySelectorAll('.submission-stage')];

    let redirectTimer = null;
    let assessmentSaved = false;

    function showModal() {
        modal.classList.add('is-visible');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('submission-modal-open');
    }

    function setStage(activeName, completedNames = []) {
        stages.forEach(stage => {
            const name = stage.dataset.stage;
            const isDone = completedNames.includes(name);
            const isActive = name === activeName;
            stage.classList.toggle('is-done', isDone);
            stage.classList.toggle('is-active', isActive);
            stage.querySelector('.submission-stage__state').textContent =
                isDone ? 'Completed' : (isActive ? 'In progress' : 'Waiting');
            stage.querySelector('.submission-stage__icon').textContent =
                isDone ? '✓' : (name === 'save' ? '1' : name === 'email' ? '2' : '3');
        });
        const positions = { save: 8, email: 52, finish: 88, done: 100 };
        fill.style.width = `${positions[activeName] ?? 8}%`;
    }

    function lockForm() {
        submitButton.disabled = true;
        submitButton.setAttribute('aria-disabled', 'true');
    }

    function goThankYou() {
        window.location.href = 'thank_you.php';
    }

    function finishSuccess() {
        mail.classList.add('is-success');
        title.textContent = 'Email Sent Successfully!';
        description.textContent = 'Your assessment has been saved and your confirmation email has been sent.';
        footnote.textContent = 'You will now be redirected to your confirmation page.';
        setStage('done', ['save', 'email', 'finish']);
        continueButton.hidden = false;
        redirectTimer = window.setTimeout(goThankYou, 2200);
    }

    function finishEmailFailure(message) {
        mail.classList.add('is-failed');
        title.textContent = 'Assessment Saved Successfully';
        description.textContent = message || 'Your assessment was saved, but we could not confirm delivery of the email.';
        footnote.textContent = 'You do not need to submit the assessment again. Continue to the confirmation page.';
        stages.forEach(stage => {
            const name = stage.dataset.stage;
            const icon = stage.querySelector('.submission-stage__icon');
            const state = stage.querySelector('.submission-stage__state');
            stage.classList.remove('is-active', 'is-done', 'is-failed');
            if (name === 'save') {
                stage.classList.add('is-done');
                icon.textContent = '✓';
                state.textContent = 'Completed';
            } else if (name === 'email') {
                stage.classList.add('is-failed');
                icon.textContent = '!';
                state.textContent = 'Not sent';
            } else {
                stage.classList.add('is-done');
                icon.textContent = '✓';
                state.textContent = 'Completed';
            }
        });
        fill.style.width = '100%';
        continueButton.hidden = false;
        continueButton.textContent = 'Continue to Thank You';
    }

    continueButton.addEventListener('click', () => {
        if (redirectTimer) window.clearTimeout(redirectTimer);
        goThankYou();
    });

    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (submitButton.disabled) return;

        lockForm();
        showModal();
        setStage('save');

        try {
            const saveResponse = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin'
            });

            const saveData = await saveResponse.json().catch(() => null);
            if (!saveResponse.ok || !saveData || !saveData.success) {
                throw new Error(saveData?.message || 'We could not save your assessment. Please contact the Guidance Office before trying again.');
            }

            assessmentSaved = true;
            setStage('email', ['save']);

            if (saveData.already_submitted) {
                setStage('done', ['save', 'email', 'finish']);
                mail.classList.add('is-success');
                title.textContent = 'Assessment Already Submitted';
                description.textContent = 'Our records show that your Needs Assessment has already been submitted.';
                footnote.textContent = 'Taking you to the confirmation page.';
                redirectTimer = window.setTimeout(goThankYou, 1800);
                return;
            }

            const emailResponse = await fetch('send_assessment_confirmation.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({ assessment_id: saveData.assessment_id }),
                credentials: 'same-origin'
            });

            const emailData = await emailResponse.json().catch(() => null);
            setStage('finish', ['save', 'email']);

            if (emailData && emailData.success && emailData.email_status === 'sent') {
                finishSuccess();
            } else {
                finishEmailFailure(emailData?.message);
            }
        } catch (error) {
            if (assessmentSaved) {
                finishEmailFailure('Your assessment was saved, but we could not confirm whether the confirmation email was delivered.');
                return;
            }

            mail.classList.add('is-failed');
            title.textContent = 'We Could Not Save Your Assessment';
            description.textContent = error.message || 'Something went wrong while saving your responses. Please contact the Guidance Office before trying again.';
            footnote.textContent = 'Please do not repeatedly click Submit. Contact the Guidance Office to check your submission status.';
            fill.style.width = '0%';
            stages.forEach(stage => {
                stage.classList.remove('is-active', 'is-done');
                stage.querySelector('.submission-stage__state').textContent = 'Not completed';
            });
            continueButton.hidden = false;
            continueButton.textContent = 'Return to Review';
            continueButton.onclick = () => window.location.reload();
        }
    });
})();
