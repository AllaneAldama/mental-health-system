<?php
$studentProgressTotal = isset($studentProgressTotal) ? max(1, (int) $studentProgressTotal) : 6;
$studentProgressStep = isset($studentProgressStep)
    ? min($studentProgressTotal, max(1, (int) $studentProgressStep))
    : 1;
?>
<div class="student-progress" data-current-step="<?= $studentProgressStep ?>" data-total-steps="<?= $studentProgressTotal ?>">
    <div class="student-progress__heading">
        <span class="student-progress__step">Step <?= $studentProgressStep ?> of <?= $studentProgressTotal ?></span>
        <span class="student-progress__percent"><?= (int) round(($studentProgressStep / $studentProgressTotal) * 100) ?>% Complete</span>
    </div>
    <div class="student-progress__track" role="progressbar" aria-label="Assessment progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int) round(($studentProgressStep / $studentProgressTotal) * 100) ?>">
        <div class="student-progress__fill"></div>
    </div>
</div>
