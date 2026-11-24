(function () {
    'use strict';

    var uploadPanel = document.getElementById('nothiz-upload-panel');
    var changeFiles = document.querySelector('[data-npi-change]');
    var submit = document.querySelector('#nothiz-upload button[type="submit"]');

    document.querySelectorAll('[data-npi-file]').forEach(function (card) {
        var input = card.querySelector('input[type="file"]');
        var name = card.querySelector('[data-npi-filename]');
        var size = card.querySelector('[data-npi-file-size]');
        var action = card.querySelector('[data-npi-file-action]');
        var emptyName = name.textContent;
        var emptySize = size.textContent;
        input.addEventListener('change', function () {
            var file = input.files[0];
            card.classList.toggle('is-selected', Boolean(file));
            name.textContent = file ? file.name : emptyName;
            name.title = file ? file.name : '';
            size.textContent = file ? formatSize(file.size) : emptySize;
            action.textContent = file ? action.dataset.replace : action.dataset.choose;
        });
    });

    function formatSize(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1024 * 1024) return (bytes / 1024).toLocaleString(undefined, { maximumFractionDigits: 1 }) + ' KiB';
        return (bytes / (1024 * 1024)).toLocaleString(undefined, { maximumFractionDigits: 1 }) + ' MiB';
    }

    function setStep(phase) {
        document.querySelectorAll('#nothiz-steps .npi-step').forEach(function (step, index) {
            var current = index + 1 === phase;
            step.classList.toggle('is-current', current);
            step.classList.toggle('is-complete', index + 1 < phase);
            if (current) step.setAttribute('aria-current', 'step');
            else step.removeAttribute('aria-current');
        });
    }

    if (uploadPanel && changeFiles) {
        changeFiles.addEventListener('click', function () {
            uploadPanel.hidden = !uploadPanel.hidden;
            changeFiles.setAttribute('aria-expanded', String(!uploadPanel.hidden));
            if (!uploadPanel.hidden) {
                setStep(1);
                document.getElementById('nothiz-workbook').focus();
            } else {
                setStep(Number(changeFiles.dataset.phase));
            }
        });
    }

    var run = document.getElementById('nothiz-run');
    if (!run) return;

    var panel = document.getElementById('nothiz-result-panel');
    var heading = document.getElementById('nothiz-result-title');
    var banner = document.getElementById('nothiz-result-banner');
    var icon = document.getElementById('nothiz-result-icon');
    var summary = document.getElementById('nothiz-summary-title');
    var hint = document.getElementById('nothiz-summary-hint');
    var segments = document.getElementById('nothiz-segments');
    var progressBar = document.getElementById('nothiz-progress-bar');
    var info = document.getElementById('nothiz-progress');
    var filters = panel.querySelector('.npi-filters');
    var busyAttempts = 0;
    var deferredAttempts = 0;
    var paused = run.dataset.isPaused === '1';
    var pause = document.getElementById('nothiz-pause');
    var confirmation = $('#nothiz-confirm-modal');

    function warnBeforeLeaving(event) {
        event.preventDefault();
        event.returnValue = '';
    }

    pause.addEventListener('click', function () {
        pause.disabled = true;
        info.classList.remove('sr-only');
        info.textContent = run.dataset.pausing;
        $.ajax({
            url: run.dataset.pause,
            type: 'POST',
            dataType: 'json',
            data: { job: run.dataset.job }
        }).done(function (result) {
            if (result.error && !pause.hidden) {
                info.textContent = result.error;
                pause.disabled = false;
            }
        }).fail(function () {
            if (pause.hidden) return;
            info.textContent = run.dataset.error;
            pause.disabled = false;
        });
    });

    function stop(message) {
        info.classList.remove('sr-only');
        info.textContent = message || run.dataset.error;
        hint.textContent = panel.dataset.resumeHint;
        heading.textContent = panel.dataset.pausedTitle;
        filters.hidden = false;
        window.removeEventListener('beforeunload', warnBeforeLeaving);
        pause.hidden = true;
        run.textContent = run.dataset.resume;
        run.hidden = false;
        run.disabled = false;
        if (changeFiles) changeFiles.disabled = false;
        if (submit) submit.disabled = false;
    }

    function batch(resume) {
        $.ajax({
            url: run.dataset.batch,
            type: 'POST',
            dataType: 'json',
            data: { job: run.dataset.job, resume: resume ? '1' : '0' }
        }).done(function (result) {
            if (result.error) return stop(result.error);
            if (result.busy) {
                if (++busyAttempts > 20) return stop();
                return window.setTimeout(function () { batch(resume && !pause.disabled); }, 500);
            }

            busyAttempts = 0;
            progressBar.max = result.total;
            progressBar.value = result.processed;
            var label = run.dataset.importing + ' ' + result.processed + ' / ' + result.total;
            summary.textContent = label;
            info.textContent = pause.disabled ? run.dataset.pausing : label;
            document.querySelectorAll('[data-npi-count]').forEach(function (count) {
                count.textContent = result[count.dataset.npiCount];
            });
            result.updated.forEach(function (update) {
                var row = panel.querySelector('[data-npi-row="' + Number(update.row) + '"]');
                if (!row) return;
                var status = row.querySelector('[data-npi-row-status]');
                status.className = 'npi-status npi-status--' + update.status;
                status.textContent = panel.dataset[update.status];
                row.querySelector('[data-npi-row-message]').textContent = update.message;
                row.classList.toggle('npi-row-failed', update.status === 'failed');
            });
            if (result.done) {
                window.removeEventListener('beforeunload', warnBeforeLeaving);
                window.location.assign(run.dataset.return);
                return;
            }
            paused = result.paused;
            if (paused) return stop(run.dataset.paused);
            if (result.deferred) {
                info.classList.remove('sr-only');
                info.textContent = pause.disabled ? run.dataset.pausing : run.dataset.deferred;
                if (++deferredAttempts > 10) return stop(run.dataset.deferred);
                return window.setTimeout(batch, 1000);
            }
            deferredAttempts = 0;
            window.setTimeout(batch, 30);
        }).fail(function () {
            stop();
        });
    }

    run.addEventListener('click', function () {
        if (submit && submit.disabled) return;
        confirmation.modal('show');
    });

    document.getElementById('nothiz-confirm-accept').addEventListener('click', function () {
        if (run.disabled || (submit && submit.disabled)) return;
        confirmation.modal('hide');
        busyAttempts = 0;
        deferredAttempts = 0;
        pause.disabled = false;
        pause.hidden = false;
        window.addEventListener('beforeunload', warnBeforeLeaving);
        run.disabled = true;
        run.hidden = true;
        filters.hidden = true;
        info.classList.add('sr-only');
        if (changeFiles) changeFiles.disabled = true;
        if (submit) submit.disabled = true;
        setStep(3);
        heading.textContent = panel.dataset.importTitle;
        banner.className = 'npi-banner npi-banner--info';
        icon.className = 'fa fa-info-circle';
        summary.textContent = run.dataset.importing + ' ' + progressBar.value + ' / ' + progressBar.max;
        hint.textContent = panel.dataset.importHint;
        document.querySelector('[data-npi-total="created"]').hidden = false;
        segments.hidden = true;
        progressBar.hidden = false;
        info.textContent = run.dataset.importing;
        batch(paused);
    });
}());
