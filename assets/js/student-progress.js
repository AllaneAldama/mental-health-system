/* Shared progress calculation and animation for all student form steps. */
(() => {
    'use strict';

    function initializeStudentProgress() {
        document
            .querySelectorAll('.student-progress[data-current-step][data-total-steps]')
            .forEach((component) => {
                const currentStep = Number.parseInt(component.dataset.currentStep, 10);
                const totalSteps = Number.parseInt(component.dataset.totalSteps, 10);
                const fill = component.querySelector('.student-progress__fill');
                const stepLabel = component.querySelector('.student-progress__step');
                const percentLabel = component.querySelector('.student-progress__percent');
                const track = component.querySelector('.student-progress__track');

                if (
                    !fill || !stepLabel || !percentLabel || !track ||
                    !Number.isFinite(currentStep) ||
                    !Number.isFinite(totalSteps) ||
                    totalSteps < 1
                ) {
                    return;
                }

                const safeStep = Math.min(totalSteps, Math.max(1, currentStep));
                const percentage = Math.round((safeStep / totalSteps) * 100);

                stepLabel.textContent = `Step ${safeStep} of ${totalSteps}`;
                percentLabel.textContent = `${percentage}% Complete`;
                track.setAttribute('aria-valuenow', String(percentage));
                track.setAttribute(
                    'aria-valuetext',
                    `Step ${safeStep} of ${totalSteps}, ${percentage}% complete`
                );

                // Animate from zero to the computed progress value.
                fill.style.width = '0%';
                requestAnimationFrame(() => {
                    requestAnimationFrame(() => {
                        fill.style.width = `${percentage}%`;
                    });
                });
            });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeStudentProgress, { once: true });
    } else {
        initializeStudentProgress();
    }
})();
