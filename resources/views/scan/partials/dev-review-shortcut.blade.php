        // ================================================================ DEV ONLY
        // Review & Export shortcut (temporary). See config/dev_review_shortcut.php
        // for what it is and how to remove it. Included at the end of the
        // workspace's script only when it is on, so it can use the workspace's
        // own functions; nothing in the workspace calls into it.
        //
        //   Click        open the saved file, copy its saved pages (no OCR), tick
        //                every field that has a value, and open step 4.
        //   Shift+click  scan the open file for real (or the saved one), save that
        //                scan as the new shortcut, then open step 4. Use it the
        //                first time, or after the layout or the OCR model changes.
        //
        //   In Batch mode, the PDFs in the codebase's pdf-files folder:
        //     Click        a batch of every PDF there, made on the server from what
        //                  was saved of their reading (no OCR, every field with a
        //                  value ticked), resumed as any batch is resumed, and step
        //                  4 opens on the first one.
        //     Shift+click  in a real batch of those files, once Scan all has read
        //                  them (in Verify or step 4): save its pages' reading for
        //                  the shortcut. Again after the layout or the model changes.
        (() => {
            const devUrls = {
                status: @json(route('dev.review-shortcut.status')),
                file: @json(route('dev.review-shortcut.file')),
                store: @json(route('dev.review-shortcut.store')),
                pages: @json(route('dev.review-shortcut.pages')),
                batch: @json(route('dev.review-shortcut.batch')),
                batchStatus: @json(route('dev.review-shortcut.batch.status')),
                batchFile: @json(route('dev.review-shortcut.batch.file')),
                batchStore: @json(route('dev.review-shortcut.batch.store')),
            };

            const devButton = document.createElement('button');
            devButton.type = 'button';
            devButton.className = 'btn btn-sm btn-warning shadow';
            devButton.style.cssText = 'position:fixed;left:1rem;bottom:1rem;z-index:2000;';
            devButton.title = 'DEV ONLY. Single: click opens step 4 on the saved scan; Shift+click scans the open file for real and saves it. '
                + 'Batch: click opens step 4 on a batch of pdf-files; Shift+click (in a real batch of them, once read) saves their reading.';
            const devIdle = '⚡ DEV: Review &amp; Export';
            devButton.innerHTML = devIdle;
            document.body.append(devButton);

            let devBusy = false;
            devButton.addEventListener('click', async (event) => {
                if (devBusy) return;
                devBusy = true;
                devButton.disabled = true;
                try {
                    await devJump({ rescan: event.shiftKey });
                } catch (error) {
                    console.error('DEV shortcut:', error);
                    alert(`DEV shortcut: ${error.message || error}`);
                } finally {
                    devBusy = false;
                    devButton.disabled = false;
                    devButton.innerHTML = devIdle;
                }
            });

            const devSay = (text) => { devButton.textContent = `⚡ ${text}`; };

            async function devJson(url, options = {}) {
                const response = await fetch(url, {
                    credentials: 'same-origin',
                    ...options,
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': config.csrf,
                        ...(options.headers ?? {}),
                    },
                });
                const body = (response.headers.get('content-type') || '').includes('application/json')
                    ? await response.json()
                    : null;
                if (!response.ok) throw new Error(body?.message || `The server answered ${response.status}.`);
                return body;
            }

            async function devWaitFor(test, ms, what) {
                const until = Date.now() + ms;
                while (!test()) {
                    if (Date.now() > until) throw new Error(`Timed out waiting for ${what}.`);
                    await new Promise((resolve) => window.setTimeout(resolve, 200));
                }
            }

            // Step 1 as a person does it: the file opens in Align, both pages of a spread.
            async function devOpenSavedFile(fileName) {
                devSay('Opening the saved file…');
                const response = await fetch(devUrls.file, { credentials: 'same-origin' });
                if (!response.ok) throw new Error('The saved file could not be loaded.');
                const file = new File([await response.blob()], fileName || 'scan.pdf', { type: 'application/pdf' });
                await openDocument(file);
                if (scanFile !== file) throw new Error('The saved file did not open.');
                if (scanPageCount >= 2) {
                    await devWaitFor(() => spreadPartnerForScan() !== null, 30000, 'the second page to load');
                }
            }

            async function devJump({ rescan }) {
                if (uploadMode === 'batch') {
                    await devBatchJump({ rescan });
                    return;
                }
                devSay('Checking…');
                const status = await devJson(devUrls.status);

                if (!rescan && status.hasPages && status.hasFile) {
                    await devOpenSavedFile(status.fileName);
                    await devShowSavedPages(status);
                } else {
                    if (!scanFile && !status.hasFile) {
                        throw new Error('Nothing is saved yet. Open your PDF first (step 1), then press the button: '
                            + 'it scans it for real once and saves it for next time.');
                    }
                    if (!scanFile) await devOpenSavedFile(status.fileName);
                    await devScanAndSave();
                }

                devSay('Opening step 4…');
                // The real scan's "already saved" question, if any, is not wanted here.
                window.bootstrap.Modal.getInstance(el('existingRecordModal'))?.hide();
                devTickEveryFieldWithAValue();
                openReview({ allowMissing: true });
            }

            // Shift+click (or the first time): a real scan, then it is saved for the shortcut.
            async function devScanAndSave() {
                if (!el('step-mark') || el('step-mark').classList.contains('d-none')) {
                    throw new Error('Go to Align (step 2) with the file open, then Shift+click the button.');
                }
                devSay('Scanning for real (once)…');
                el('scanNowBtn').click();
                await devWaitFor(() => scanInProgress, 10000, 'the scan to start');
                await devWaitFor(() => !scanInProgress, 15 * 60 * 1000, 'the scan to finish');
                if (el('step-verify').classList.contains('d-none')) {
                    throw new Error('The scan did not reach Verify, so nothing was saved for the shortcut.');
                }

                devSay('Saving the scan for next time…');
                const data = new FormData();
                data.set('scan', scanFile, scanFile.name);
                docEntries().forEach((entry, index) => {
                    data.set(`pages[${index}][id]`, String(entry.page.id));
                    data.set(`pages[${index}][number]`, String(entry.number));
                });
                await devJson(devUrls.store, { method: 'POST', body: data });
            }

            // Fresh copies of the saved pages, handed to Verify as a finished scan hands them.
            async function devShowSavedPages(status) {
                const changed = status.pages.filter((saved) => layoutFor(saved.number).templateId !== saved.templateId);
                if (changed.length > 0 && !window.confirm('DEV shortcut: the saved scan was read with another layout '
                    + `(page ${changed.map((saved) => saved.number).join(', ')}). Shift+click scans again with the current one. `
                    + 'Go on with the old scan anyway?')) {
                    throw new Error('Stopped.');
                }

                devSay('Copying the saved pages…');
                const made = await devJson(devUrls.pages, { method: 'POST' });
                const entries = await Promise.all(made.pages.map(async (copy) => ({
                    number: copy.number,
                    page: await devJson(copy.statusUrl),
                    layout: layoutFor(copy.number),
                })));
                entries.sort((a, b) => a.number - b.number);
                if (entries[0].number !== scanPageNumber) {
                    throw new Error(`The file opened at page ${scanPageNumber}, but the saved scan starts at page ${entries[0].number}.`);
                }

                // From here as the scan does once both pages are read (see scanNowBtn).
                clearDetection();
                entries.slice(1).forEach((entry) => pageDetections.delete(entry.number));
                const page = entries[0].page;
                if (Math.abs(Number(page.deskew) || 0) >= 0.05) {
                    await marker.loadFromUrl(page.imageUrl);
                    setGrid(geometryMarkers(page.geometry, page), page.geometry?.ruled_ys ?? gridRows, { byHand: rowsByHand });
                }
                verifyDoc = entries.length > 1 ? { pages: entries, offsets: [0, 0] } : null;
                processedPage = page;
                cropped = verifyItems();
                readings = cropped.map((item) => item.reading);
                requiredInput(document, '#ocrModelKey').value = processedPage.modelKey.trim();
                requiredInput(document, '#documentPageId').value = String(processedPage.id);
                el('summaryModel').textContent = processedPage.model || '—';
                renderVerifyRows();
                if (verifyDoc) await loadVerifySpread();
                showStep('verify');
                if (verifyDoc) prepareSpreadComparison();
                else prepareValidationComparison();
            }

            // Batch mode: the PDFs in pdf-files. Click: a batch of them made on the server
            // from what was saved of their reading, resumed by the workspace's own Resume.
            // Shift+click (in a real batch of them, read): save that reading.
            async function devBatchJump({ rescan }) {
                if (rescan) {
                    await devSaveBatchReading();
                    return;
                }
                if (batchState.active) {
                    throw new Error('A batch is open already. Cancel it (Discard batch), or reload the page, then press the button again. '
                        + '(Shift+click saves what this batch has read, for the shortcut.)');
                }

                devSay('Checking…');
                const status = await devJson(devUrls.batchStatus);
                if (status.files.length === 0) throw new Error(`There is no PDF in ${status.folder}.`);
                const notRead = status.files.filter((file) => !file.complete);
                if (notRead.length > 0) {
                    throw new Error(`Not read yet: ${notRead.map((file) => file.name).join(', ')}.

`
                        + `Scan them once for real: in Batch mode add the files of ${status.folder}, align them and Scan all, `
                        + 'then in Verify (or step 4) Shift+click this button. After that a click is enough.');
                }
                const changed = status.files.filter((file) => file.pages.some((page) => layoutFor(page.number).templateId !== page.templateId));
                if (changed.length > 0 && !window.confirm('DEV shortcut: these files were read with another layout: '
                    + `${changed.map((file) => file.name).join(', ')}. Scan them again and Shift+click to save the new reading. `
                    + 'Go on with the old reading anyway?')) {
                    throw new Error('Stopped.');
                }

                // Each page of each file on its own, as Save & Next keeps a queued page
                // (a file and its copies are one fingerprint: cut once).
                devSay('Cutting the files…');
                const data = new FormData();
                data.set('doc_type', config.docType);
                data.set('document_template_id', String(PAGE_ONE_TEMPLATE_ID));
                if (config.pageTwo?.templateId) data.set('page_two_template_id', String(config.pageTwo.templateId));
                data.set('pairs', config.pageTwo ? '1' : '0');
                const cut = new Set();
                for (const entry of status.files) {
                    if (cut.has(entry.hash)) continue;
                    cut.add(entry.hash);
                    // eslint-disable-next-line no-await-in-loop
                    const response = await fetch(`${devUrls.batchFile}?name=${encodeURIComponent(entry.name)}`, { credentials: 'same-origin' });
                    if (!response.ok) throw new Error(`${entry.name} could not be loaded.`);
                    // eslint-disable-next-line no-await-in-loop
                    const file = new File([await response.blob()], entry.name, { type: 'application/pdf' });
                    for (const page of entry.pages) {
                        const pageFile = entry.pageTotal > 1
                            // eslint-disable-next-line no-await-in-loop
                            ? await pdfPageFile(file, page.number)
                            : file;
                        data.set(`scans[${entry.hash}][${page.number}]`, pageFile, `page-${page.number}.pdf`);
                    }
                }

                devSay(`Making a batch of ${status.files.length} files…`);
                const made = await devJson(devUrls.batch, { method: 'POST', body: data });

                devSay('Resuming the batch…');
                await resumeBatch(made.batchId);
                if (!batchState.active) throw new Error('The batch did not open (see the message on the page).');
                await devWaitFor(() => activeStep === 'verify', 60000, 'the first document to open');

                devSay('Opening step 4…');
                openReview({ allowMissing: true });
            }

            // Shift+click in a batch: the pages it has read are saved for the shortcut
            // (only those of files in pdf-files; the server checks).
            async function devSaveBatchReading() {
                const read = batchState.active
                    ? batchState.items.filter((item) => item.pageId && item.sourceHash && ['ready', 'verifying'].includes(item.status))
                    : [];
                if (read.length === 0) {
                    throw new Error('Shift+click saves what a batch has read: in Batch mode add the files of pdf-files, '
                        + 'align them and Scan all, then Shift+click in Verify (before saving the records).');
                }
                devSay('Saving the reading…');
                const data = new FormData();
                read.forEach((item, index) => {
                    data.set(`pages[${index}][id]`, String(item.pageId));
                    data.set(`pages[${index}][hash]`, item.sourceHash);
                    data.set(`pages[${index}][number]`, String(item.pageNo ?? 1));
                    data.set(`pages[${index}][total]`, String(item.pageTotal ?? 1));
                });
                const saved = await devJson(devUrls.batchStore, { method: 'POST', body: data });
                const status = await devJson(devUrls.batchStatus);
                const missing = status.files.filter((file) => !file.complete).map((file) => file.name);
                tellInApp('DEV shortcut: reading saved',
                    `${saved.files.reduce((sum, file) => sum + file.pages, 0)} pages saved. `
                    + (missing.length > 0
                        ? `Still not read: ${missing.join(', ')}.`
                        : `Every PDF in ${status.folder} is ready: a click on the button in Batch mode now goes straight to step 4.`));
            }

            // Step 3 as a person does it: tick Verified on every field that has a value.
            function devTickEveryFieldWithAValue() {
                el('verifyRows').querySelectorAll('.validation-field').forEach((row) => {
                    const box = row.querySelector('.validation-verified');
                    const value = row.querySelector('.verified')?.value.trim();
                    if (!box || box.checked || !value) return;
                    box.checked = true;
                    box.dispatchEvent(new Event('change', { bubbles: true }));
                });
            }
        })();
        // ============================================================ END DEV ONLY
