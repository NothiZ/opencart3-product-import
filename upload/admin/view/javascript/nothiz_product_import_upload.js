(function () {
    'use strict';

    var form = document.getElementById('nothiz-upload');
    if (!form) return;

    var submit = form.querySelector('button[type="submit"]');
    var status = document.getElementById('nothiz-upload-status');
    var running = false;
    var resume = document.getElementById('nothiz-resume-scan');
    var pending = form.dataset.pendingScan;
    var run = document.getElementById('nothiz-run');

    function warnBeforeLeaving(event) {
        event.preventDefault();
        event.returnValue = '';
    }

    function setRunning(value) {
        running = value;
        submit.disabled = value;
        resume.disabled = value;
        if (run) run.disabled = value;
        inputs.forEach(function (input) { input.disabled = value; });
        if (value) window.addEventListener('beforeunload', warnBeforeLeaving);
        else window.removeEventListener('beforeunload', warnBeforeLeaving);
    }

    async function scan(params, output) {
        var busyAttempts = 0;
        output.textContent = form.dataset.scanning;
        while (true) {
            var reply = await readReply(await fetch(form.dataset.scan, { method: 'POST', body: params }));
            if (reply.busy) {
                if (++busyAttempts > 20) throw new Error(form.dataset.uploadError);
                await new Promise(function (resolve) { window.setTimeout(resolve, 500); });
                continue;
            }
            busyAttempts = 0;
            output.textContent = form.dataset.scanning + ' ' + reply.processed + ' / ' + reply.total;
            if (reply.done) return;
        }
    }

    function openPreview(upload) {
        document.getElementById('nothiz-staged-upload').value = upload;
        window.removeEventListener('beforeunload', warnBeforeLeaving);
        HTMLFormElement.prototype.submit.call(form);
    }

    async function finishScan() {
        await scan(new URLSearchParams({ upload: pending }), status);
        openPreview(pending);
    }

    resume.addEventListener('click', async function () {
        if (running || submit.disabled || !pending) return;
        setRunning(true);
        try {
            await finishScan();
        } catch (error) {
            status.textContent = error.message || form.dataset.uploadError;
            setRunning(false);
        }
    });

    var wrapper = document.getElementById('nothiz-wrapper');
    if (wrapper) {
        wrapper.addEventListener('click', async function () {
            if (running || submit.disabled) return;
            var output = document.getElementById('nothiz-wrapper-status');
            setRunning(true);
            wrapper.disabled = true;
            try {
                await scan(new URLSearchParams({
                    job: wrapper.dataset.job,
                    strip_products: wrapper.dataset.strip
                }), output);
                window.removeEventListener('beforeunload', warnBeforeLeaving);
                window.location.assign(form.action);
            } catch (error) {
                output.textContent = error.message || form.dataset.uploadError;
                wrapper.disabled = false;
                setRunning(false);
            }
        });
    }
    var minChunk = 16 * 1024;
    var hashChunk = 4 * 1024 * 1024;
    var inputs = [document.getElementById('nothiz-workbook'), document.getElementById('nothiz-images')];
    inputs.forEach(function (input) {
        input.addEventListener('change', function () {
            if (!running) status.textContent = '';
        });
    });

    async function readReply(response) {
        var reply;
        try {
            reply = await response.json();
        } catch (error) {
            throw new Error(form.dataset.uploadError);
        }
        if (!response.ok || !reply || reply.error) {
            throw new Error((reply && reply.error) || form.dataset.uploadError);
        }
        return reply;
    }

    async function requestUploadPlan(upload, digest) {
        var params = new URLSearchParams({ upload: upload });
        if (digest) params.set('archive_sha', digest);
        return readReply(await fetch(form.dataset.plan, { method: 'POST', body: params }));
    }

    async function fingerprint(file) {
        var sha = window.sha256.create();
        // Hash file slices to avoid loading a few hundred MiB into browser memory at once.
        for (var offset = 0; offset < file.size; offset += hashChunk) {
            var end = Math.min(offset + hashChunk, file.size);
            sha.update(await file.slice(offset, end).arrayBuffer());
            status.textContent = form.dataset.checkArchive + ' ' + Math.floor(end * 100 / file.size) + '%';
        }
        return sha.hex();
    }

    async function sendChunk(url, chunk) {
        for (var retry = 0; ; retry++) {
            try {
                var response = await fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/octet-stream' },
                    body: chunk
                });
                if (response.status < 500 || retry === 2) return response;
            } catch (error) {
                if (retry === 2) throw new Error(form.dataset.uploadError);
            }
            await new Promise(function (resolve) { window.setTimeout(resolve, 300); });
        }
    }

    async function uploadFile(file, kind, stage, transfer) {
        var sent = 0;
        var label = kind === 'workbook' ? form.dataset.uploadWorkbook : form.dataset.uploadImages;
        status.textContent = label + ' 0%';

        async function part(start, end) {
            var url = new URL(form.dataset.chunk, window.location.href);
            url.searchParams.set('upload', stage.upload);
            url.searchParams.set('file', kind);
            url.searchParams.set('offset', start);
            var response = await sendChunk(url.href, file.slice(start, end));
            if (response.status === 413) {
                // Split only the rejected byte range, preserving chunks already written in the same window.
                if (end - start <= minChunk) throw new Error(form.dataset.uploadLimit);
                transfer.chunkSize = Math.min(transfer.chunkSize, Math.max(minChunk, Math.floor((end - start) / 2)));
                for (var next = start; next < end;) {
                    var limit = Math.min(next + transfer.chunkSize, end);
                    await part(next, limit);
                    next = limit;
                }
                return;
            }
            var result = await readReply(response);
            if (result.offset !== end) throw new Error(form.dataset.uploadError);
            sent += end - start;
            status.textContent = label + ' ' + Math.floor(sent * 100 / file.size) + '%';
        }

        // Learn the proxy limit before opening a small window of concurrent image requests.
        var offset = Math.min(transfer.chunkSize, file.size);
        await part(0, offset);
        var lanes = kind === 'images' ? 3 : 1;
        while (offset < file.size) {
            var pending = [];
            for (var i = 0; i < lanes && offset < file.size; i++) {
                var end = Math.min(offset + transfer.chunkSize, file.size);
                pending.push(part(offset, end).catch(function (error) { return error; }));
                offset = end;
            }
            var results = await Promise.all(pending);
            var failure = results.find(function (result) { return result instanceof Error; });
            if (failure) throw failure;
        }
    }

    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        if (running || submit.disabled) return;

        var workbook = inputs[0].files[0];
        var images = inputs[1].files[0];
        if (!workbook || !workbook.size) {
            status.textContent = form.dataset.uploadMissing;
            return;
        }

        var meta = new URLSearchParams({ workbook_name: workbook.name, workbook_size: workbook.size });
        if (images) {
            meta.set('images_name', images.name);
            meta.set('images_size', images.size);
        }

        setRunning(true);

        try {
            var begun = await fetch(form.dataset.begin, { method: 'POST', body: meta });
            var stage = await readReply(begun);
            if (!/^[a-f0-9]{32}$/.test(stage.upload) || !Number.isInteger(stage.chunk_bytes) || stage.chunk_bytes < minChunk) {
                throw new Error(form.dataset.uploadError);
            }
            var transfer = {chunkSize: stage.chunk_bytes};
            await uploadFile(workbook, 'workbook', stage, transfer);
            status.textContent = form.dataset.uploadChecking;
            var plan = await requestUploadPlan(stage.upload);
            if (plan.needs_images) {
                if (!images || !images.size) throw new Error(form.dataset.zipMissing);
                status.textContent = form.dataset.checkArchive;
                var archive = await requestUploadPlan(stage.upload, await fingerprint(images));
                if (!archive.reused) await uploadFile(images, 'images', stage, transfer);
                else status.textContent = form.dataset.archiveReused;
                pending = stage.upload;
                resume.hidden = false;
                await finishScan();
            } else {
                status.textContent = form.dataset.catalogReused;
                openPreview(stage.upload);
            }
        } catch (error) {
            status.textContent = error.message || form.dataset.uploadError;
            setRunning(false);
        }
    });
}());
