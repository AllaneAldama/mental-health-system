<?php
require_once __DIR__ . '/session.php';

$errors = $_SESSION['errors'] ?? [];
$old = $_SESSION['old'] ?? [];

if (empty($old) && isset($_SESSION['student_id'])) {
    $old = [
        'alias' => $_SESSION['alias'] ?? '',
        'fullname' => $_SESSION['fullname'] ?? '',
        'student_number' => $_SESSION['student_number'] ?? '',
        'program' => $_SESSION['program'] ?? '',
        'year_level' => $_SESSION['year_level'] ?? '',
        'email' => $_SESSION['email'] ?? ''
    ];
}

unset($_SESSION['errors'], $_SESSION['old']);

function oldValue(array $old, string $key): string {
    return htmlspecialchars((string)($old[$key] ?? ''), ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Student Information</title>
<link rel="stylesheet" href="../assets/students/css/student-base.css">
<link rel="stylesheet" href="../assets/students/css/student-information.css">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="../assets/students/css/student-progress.css">
    <script src="../assets/js/student-progress.js" defer></script>

</head>
<body class="student-info-page">
<div class="student-wrapper">
    <?php $studentProgressStep = 2; $studentProgressTotal = 6; include __DIR__ . '/student-progress.php'; ?>

    <div class="student-card">
        <a href="disclaimer.php" class="student-back-btn" aria-label="Back to Disclaimer" title="Back">
            <span aria-hidden="true">‹</span>
        </a>

        <h1>Student Information</h1>
        <p class="student-intro">Please provide the following information before starting your assessment.</p>

        <div class="form-status" id="formStatus" role="alert" aria-live="polite" hidden></div>

        <form action="process_student_information.php" method="POST" id="studentInfoForm" novalidate>
            <div class="form-group">
                <label for="alias">Student Alias</label>
                <input id="alias" type="text" name="alias" value="<?= oldValue($old, 'alias') ?>" required autocomplete="nickname" aria-describedby="aliasError">
                <p class="field-error" id="aliasError" aria-live="polite" hidden></p>
            </div>

            <div class="form-group">
                <label for="fullname">Full Name</label>
                <input id="fullname" type="text" name="fullname" value="<?= oldValue($old, 'fullname') ?>" required autocomplete="name" aria-describedby="fullnameError">
                <p class="field-error" id="fullnameError" aria-live="polite" hidden></p>
            </div>

            <div class="form-group">
                <label for="student_number">Student Number</label>
                <input id="student_number" type="text" name="student_number" autocomplete="off" value="<?= oldValue($old, 'student_number') ?>" required aria-describedby="studentNumberError">
                <p class="field-error" id="studentNumberError" aria-live="polite" hidden></p>
            </div>

            <div class="form-row">
                <div class="form-group custom-select-group">
                    <label for="programPicker">Program</label>
                    <input type="hidden" name="program" id="program" value="<?= htmlspecialchars((string)($old['program'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                    <div class="custom-select" data-select="program">
                        <button type="button" class="custom-select-trigger" id="programPicker" aria-haspopup="listbox" aria-expanded="false" aria-controls="programOptions">
                            <span class="custom-select-value" data-placeholder="Select Program">Select Program</span>
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                        </button>
                        <div class="custom-select-panel" id="programOptions" hidden>
                            <label class="select-search-wrap">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m16 16 4 4"/></svg>
                                <input type="search" class="select-search" placeholder="Search program..." autocomplete="off" aria-label="Search programs">
                            </label>
                            <div class="custom-select-options" role="listbox" aria-label="Programs">
                                <div class="select-group" data-group="4-year">
                                    <div class="select-group-heading"><span>♧</span> 4-Year Programs</div>
                                    <button type="button" class="select-option" role="option" data-value="BSIT"><strong>BSIT</strong><span>Bachelor of Science in Information Technology</span></button>
                                    <button type="button" class="select-option" role="option" data-value="BSOA"><strong>BSOA</strong><span>Bachelor of Science in Office Administration</span></button>
                                    <button type="button" class="select-option" role="option" data-value="BSAIS"><strong>BSAIS</strong><span>Bachelor of Science in Accounting Information System</span></button>
                                </div>
                                <div class="select-group" data-group="certificate">
                                    <div class="select-group-heading"><span>✧</span> 2-Year Certificate Programs</div>
                                    <button type="button" class="select-option" role="option" data-value="CCS"><strong>CCS</strong><span>Certificate in Computer Sciences</span></button>
                                    <button type="button" class="select-option" role="option" data-value="COA"><strong>COA</strong><span>Certificate in Office Administration</span></button>
                                    <button type="button" class="select-option" role="option" data-value="CHRM"><strong>CHRM</strong><span>Certificate in Hotel and Restaurant Management</span></button>
                                </div>
                                <div class="select-group" data-group="associate">
                                    <div class="select-group-heading"><span>▱</span> 2-Year Associate Programs</div>
                                    <button type="button" class="select-option" role="option" data-value="AHRT"><strong>AHRT</strong><span>Associate in Hotel and Restaurant Technology</span></button>
                                    <button type="button" class="select-option" role="option" data-value="AHRD"><strong>AHRD</strong><span>Associate in Human Resource Development</span></button>
                                    <button type="button" class="select-option" role="option" data-value="AAIS"><strong>AAIS</strong><span>Associate in Accounting Information System</span></button>
                                </div>
                                <?php if (in_array((string)($old['program'] ?? ''), ['BSBA', 'BSED'], true)): ?>
                                <div class="select-group legacy-group">
                                    <div class="select-group-heading">Previously Saved Program</div>
                                    <button type="button" class="select-option" role="option" data-value="<?= htmlspecialchars((string)$old['program'], ENT_QUOTES, 'UTF-8') ?>"><strong><?= htmlspecialchars((string)$old['program'], ENT_QUOTES, 'UTF-8') ?></strong><span>Previously saved program</span></button>
                                </div>
                                <?php endif; ?>
                            </div>
                            <p class="select-empty" hidden>No matching programs found.</p>
                        </div>
                    </div>
                    <p class="field-error" id="programError" aria-live="polite" hidden></p>
                </div>

                <div class="form-group custom-select-group">
                    <label for="yearPicker">Year Level</label>
                    <input type="hidden" name="year_level" id="year_level" value="<?= htmlspecialchars((string)($old['year_level'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                    <div class="custom-select" data-select="year_level">
                        <button type="button" class="custom-select-trigger" id="yearPicker" aria-haspopup="listbox" aria-expanded="false" aria-controls="yearOptions">
                            <span class="custom-select-value" data-placeholder="Select Year">Select Year</span>
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                        </button>
                        <div class="custom-select-panel year-panel" id="yearOptions" hidden>
                            <div class="custom-select-options" role="listbox" aria-label="Year levels">
                                <div class="select-group">
                                    <div class="select-group-heading"><span>▦</span> Choose your year level</div>
                                    <button type="button" class="select-option year-option" role="option" data-value="1st Year"><strong>1st</strong><span>1st Year</span></button>
                                    <button type="button" class="select-option year-option" role="option" data-value="2nd Year"><strong>2nd</strong><span>2nd Year</span></button>
                                    <button type="button" class="select-option year-option" role="option" data-value="3rd Year"><strong>3rd</strong><span>3rd Year</span></button>
                                    <button type="button" class="select-option year-option" role="option" data-value="4th Year"><strong>4th</strong><span>4th Year</span></button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <p class="field-error" id="yearLevelError" aria-live="polite" hidden></p>
                </div>
            </div>

            <div class="form-group">
                <label for="email">Email Address</label>
                <input id="email" type="email" name="email" value="<?= oldValue($old, 'email') ?>" required autocomplete="email" aria-describedby="emailError">
                <p class="field-error" id="emailError" aria-live="polite" hidden></p>
            </div>

            <button class="next-btn" type="submit" id="nextButton">Next →</button>
        </form>
    </div>
</div>


<div class="save-modal" id="saveModal" hidden>
    <div class="save-modal-backdrop"></div>
    <section class="save-modal-card" role="dialog" aria-modal="true"
             aria-labelledby="saveModalTitle" aria-describedby="saveModalDescription">
        <div class="save-modal-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" focusable="false">
                <path d="m5 12.5 4.2 4.2L19.5 6.8"></path>
            </svg>
        </div>
        <p class="save-modal-eyebrow" id="saveModalEyebrow">STEP COMPLETED</p>
        <h2 id="saveModalTitle">Information Saved Successfully!</h2>
        <p class="save-modal-description" id="saveModalDescription">
            Thank you for providing your information. Your details have been saved
            and will be used for the Needs Assessment process.
        </p>
        <div class="privacy-note">
            <span class="privacy-note-icon" aria-hidden="true"></span>
            <p>
                <strong>Your information matters.</strong>
                Your information and assessment responses will be handled confidentially
                and used for the Guidance Office's assessment and service-planning purposes,
                as explained in the Disclaimer.
            </p>
        </div>
        <button type="button" class="save-modal-continue" id="saveModalContinue">
            Continue <span aria-hidden="true">→</span>
        </button>
    </section>
</div>

<script>
(() => {
    const form = document.getElementById('studentInfoForm');
    const nextButton = document.getElementById('nextButton');
    const statusBox = document.getElementById('formStatus');
    const saveModal = document.getElementById('saveModal');
    const modalContinue = document.getElementById('saveModalContinue');
    const modalEyebrow = document.getElementById('saveModalEyebrow');
    const modalTitle = document.getElementById('saveModalTitle');
    const modalDescription = document.getElementById('saveModalDescription');
    const privacyNote = saveModal.querySelector('.privacy-note');
    let submitting = false;
    let nextPageUrl = 'assessment_guide.php';

    const customSelects = document.querySelectorAll('.custom-select');
    function closeCustomSelects(except = null) {
        customSelects.forEach((wrapper) => {
            if (wrapper === except) return;
            const trigger = wrapper.querySelector('.custom-select-trigger');
            const panel = wrapper.querySelector('.custom-select-panel');
            panel.hidden = true;
            trigger.setAttribute('aria-expanded', 'false');
            wrapper.classList.remove('is-open');
        });
    }

    customSelects.forEach((wrapper) => {
        const key = wrapper.dataset.select;
        const valueInput = document.getElementById(key);
        const trigger = wrapper.querySelector('.custom-select-trigger');
        const valueLabel = wrapper.querySelector('.custom-select-value');
        const panel = wrapper.querySelector('.custom-select-panel');
        const options = [...wrapper.querySelectorAll('.select-option')];
        const search = wrapper.querySelector('.select-search');
        const empty = wrapper.querySelector('.select-empty');

        function selectOption(option) {
            valueInput.value = option.dataset.value;
            valueLabel.textContent = option.querySelector('span').textContent.trim();
            wrapper.querySelectorAll('.select-option').forEach((item) => {
                const selected = item === option;
                item.classList.toggle('is-selected', selected);
                item.setAttribute('aria-selected', selected ? 'true' : 'false');
            });
            valueInput.dispatchEvent(new Event('change', { bubbles: true }));
            closeCustomSelects();
            trigger.focus();
        }

        const initial = options.find((option) => option.dataset.value === valueInput.value);
        if (initial) {
            valueLabel.textContent = initial.querySelector('span').textContent.trim();
            initial.classList.add('is-selected');
            initial.setAttribute('aria-selected', 'true');
        }

        trigger.addEventListener('click', () => {
            const opening = panel.hidden;
            closeCustomSelects(wrapper);
            panel.hidden = !opening;
            trigger.setAttribute('aria-expanded', String(opening));
            wrapper.classList.toggle('is-open', opening);
            if (opening && search) {
                search.value = '';
                options.forEach((option) => option.hidden = false);
                wrapper.querySelectorAll('.select-group').forEach((group) => group.hidden = false);
                if (empty) empty.hidden = true;
                requestAnimationFrame(() => search.focus({ preventScroll: true }));
            }
        });

        options.forEach((option) => option.addEventListener('click', () => selectOption(option)));

        if (search) {
            search.addEventListener('input', () => {
                const query = search.value.trim().toLowerCase();
                let visibleCount = 0;
                wrapper.querySelectorAll('.select-group').forEach((group) => {
                    const groupOptions = [...group.querySelectorAll('.select-option')];
                    let groupCount = 0;
                    groupOptions.forEach((option) => {
                        const match = option.textContent.toLowerCase().includes(query);
                        option.hidden = !match;
                        if (match) groupCount++;
                    });
                    group.hidden = groupCount === 0;
                    visibleCount += groupCount;
                });
                empty.hidden = visibleCount !== 0;
            });
        }
    });

    document.addEventListener('click', (event) => {
        if (!event.target.closest('.custom-select')) closeCustomSelects();
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') closeCustomSelects();
    });

    const fields = {
        alias: { input: document.getElementById('alias'), error: document.getElementById('aliasError'), required: 'Please enter your student alias.' },
        fullname: { input: document.getElementById('fullname'), error: document.getElementById('fullnameError'), required: 'Please enter your full name.' },
        student_number: { input: document.getElementById('student_number'), error: document.getElementById('studentNumberError'), required: 'Please enter your student number.' },
        program: { input: document.getElementById('program'), error: document.getElementById('programError'), required: 'Please select your program.' },
        year_level: { input: document.getElementById('year_level'), error: document.getElementById('yearLevelError'), required: 'Please select your year level.' },
        email: { input: document.getElementById('email'), error: document.getElementById('emailError'), required: 'Please enter your email address.' }
    };

    function clearFieldError(key) {
        const field = fields[key];
        field.error.textContent = '';
        field.error.hidden = true;
        field.input.classList.remove('input-error');
        field.input.removeAttribute('aria-invalid');
        const customTrigger = document.querySelector(`[data-select="${field.input.id}"] .custom-select-trigger`);
        if (customTrigger) {
            customTrigger.classList.remove('input-error');
            customTrigger.removeAttribute('aria-invalid');
        }
    }

    function showFieldError(key, message) {
        const field = fields[key];
        field.error.textContent = message;
        field.error.hidden = false;
        field.input.classList.add('input-error');
        field.input.setAttribute('aria-invalid', 'true');
        const customTrigger = document.querySelector(`[data-select="${field.input.id}"] .custom-select-trigger`);
        if (customTrigger) {
            customTrigger.classList.add('input-error');
            customTrigger.setAttribute('aria-invalid', 'true');
        }
    }

    function showStatus(message) {
        statusBox.textContent = message;
        statusBox.hidden = false;
    }

    function clearStatus() {
        statusBox.textContent = '';
        statusBox.hidden = true;
    }

    Object.entries(fields).forEach(([key, field]) => {
        field.input.addEventListener('input', () => {
            clearFieldError(key);
            clearStatus();
        });
        field.input.addEventListener('change', () => {
            clearFieldError(key);
            clearStatus();
        });
    });

    function validateLocally() {
        let valid = true;
        Object.entries(fields).forEach(([key, field]) => {
            const value = field.input.value.trim();
            if (!value) {
                showFieldError(key, field.required);
                valid = false;
            } else if (key === 'email' && !field.input.validity.valid) {
                showFieldError(key, 'Please enter a valid email address.');
                valid = false;
            }
        });

        if (!valid) {
            const firstInvalid = form.querySelector('[aria-invalid="true"]');
            if (firstInvalid) {
                const customTrigger = document.querySelector(`[data-select="${firstInvalid.id}"] .custom-select-trigger`);
                (customTrigger || firstInvalid).focus();
                (customTrigger || firstInvalid).scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }
        return valid;
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (submitting) return;

        clearStatus();
        if (!validateLocally()) return;

        submitting = true;
        nextButton.disabled = true;
        nextButton.textContent = 'Checking…';

        try {
            const payload = new FormData(form);
            const checkResponse = await fetch('check_student_information.php', {
                method: 'POST',
                body: payload,
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
            });

            const checkResult = await checkResponse.json();
            if (!checkResponse.ok || checkResult.error) {
                throw new Error('We could not verify your information right now. Please try again.');
            }

            let hasDuplicate = false;
            ['student_number', 'email'].forEach((key) => {
                if (checkResult.errors && checkResult.errors[key]) {
                    showFieldError(key, checkResult.errors[key]);
                    hasDuplicate = true;
                }
            });

            if (hasDuplicate) {
                const firstDuplicate = form.querySelector('[aria-invalid="true"]');
                if (firstDuplicate) {
                    const customTrigger = document.querySelector(`[data-select="${firstDuplicate.id}"] .custom-select-trigger`);
                    (customTrigger || firstDuplicate).focus();
                    (customTrigger || firstDuplicate).scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
                return;
            }

            nextButton.textContent = 'Saving…';
            const saveResponse = await fetch(form.action, {
                method: 'POST',
                body: payload,
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
            });

            const saveResult = await saveResponse.json();

            if (saveResult.errors) {
                Object.entries(saveResult.errors).forEach(([key, message]) => {
                    if (fields[key]) showFieldError(key, message);
                });
                if (saveResult.general) showStatus(saveResult.general);

                const firstInvalid = form.querySelector('[aria-invalid="true"]');
                if (firstInvalid) {
                    const customTrigger = document.querySelector(`[data-select="${firstInvalid.id}"] .custom-select-trigger`);
                    (customTrigger || firstInvalid).focus();
                    firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
                return;
            }

            if (!saveResponse.ok || !saveResult.success || !saveResult.redirect) {
                throw new Error(saveResult.message || 'Unable to save your information. Please try again.');
            }

            nextPageUrl = saveResult.redirect;

            // Show a distinct confirmation depending on whether this is the
            // student's first database save or a later update to their details.
            if (saveResult.modal_type === 'updated') {
                modalEyebrow.textContent = 'INFORMATION UPDATED';
                modalTitle.textContent = 'Changes Saved Successfully!';
                modalDescription.textContent =
                    'Any updates or changes to your information have been saved successfully. You may now continue to the Needs Assessment Guide.';
                privacyNote.querySelector('strong').textContent = 'Your changes are saved securely.';
            } else {
                modalEyebrow.textContent = 'STEP COMPLETED';
                modalTitle.textContent = 'Information Saved Successfully!';
                modalDescription.textContent =
                    'Thank you for providing your information. Your details have been saved and will be used for the Needs Assessment process.';
                privacyNote.querySelector('strong').textContent = 'Your information matters.';
            }

            saveModal.hidden = false;
            document.body.classList.add('modal-open');
            requestAnimationFrame(() => saveModal.classList.add('is-visible'));
            modalContinue.focus();
        } catch (error) {
            showStatus(error.message || 'Something went wrong. Please try again.');
        } finally {
            submitting = false;
            nextButton.disabled = false;
            nextButton.textContent = 'Next →';
        }
    });

    modalContinue.addEventListener('click', () => {
        if (modalContinue.disabled) return;
        modalContinue.disabled = true;
        modalContinue.innerHTML = 'Continuing…';
        document.body.classList.add('page-leaving');
        saveModal.classList.remove('is-visible');
        window.setTimeout(() => {
            window.location.assign(nextPageUrl);
        }, window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 260);
    });
})();
</script>
</body>
</html>
