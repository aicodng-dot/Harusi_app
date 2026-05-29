import './bootstrap';
import { Html5Qrcode, Html5QrcodeScannerState, Html5QrcodeSupportedFormats } from 'html5-qrcode';

const clamp = (value, min, max) => Math.min(max, Math.max(min, Number(value) || min));

const jsonRequest = async (url, csrf, payload) => {
    const response = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': csrf,
        },
        body: JSON.stringify(payload),
    });

    const data = await response.json().catch(() => ({
        ok: false,
        result: 'error',
        message: 'Server returned an unexpected response.',
    }));

    return { response, data };
};

document.addEventListener('DOMContentLoaded', () => {
    const passType = document.querySelector('[data-pass-type]');
    const allowedAdmissions = document.querySelector('[data-allowed-admissions]');

    const syncPassType = () => {
        if (!passType || !allowedAdmissions) return;

        const usedEntries = Number(allowedAdmissions.dataset.usedEntries || 0);

        if (passType.value === 'single') {
            allowedAdmissions.value = 1;
            allowedAdmissions.min = 1;
            allowedAdmissions.max = 1;
            allowedAdmissions.readOnly = true;
        } else if (passType.value === 'double') {
            allowedAdmissions.value = 2;
            allowedAdmissions.min = 2;
            allowedAdmissions.max = 2;
            allowedAdmissions.readOnly = true;
        } else {
            const minSpecialEntries = Math.max(3, usedEntries);
            allowedAdmissions.min = minSpecialEntries;
            allowedAdmissions.max = 10;
            allowedAdmissions.readOnly = false;
            allowedAdmissions.value = clamp(allowedAdmissions.value, minSpecialEntries, 10);
        }
    };

    passType?.addEventListener('change', syncPassType);
    allowedAdmissions?.addEventListener('input', syncPassType);
    syncPassType();

    const userRole = document.querySelector('[data-user-role]');
    const gateName = document.querySelector('[data-gate-name]');

    const syncUserRole = () => {
        if (!userRole || !gateName) return;

        const scannerSelected = userRole.value === 'scanner';
        gateName.required = scannerSelected;
        gateName.disabled = !scannerSelected;
        gateName.classList.toggle('bg-stone-100', !scannerSelected);

        if (!scannerSelected) {
            gateName.value = '';
        }
    };

    userRole?.addEventListener('change', syncUserRole);
    syncUserRole();

    const qrDialog = document.querySelector('[data-qr-dialog]');
    const qrImage = document.querySelector('[data-qr-preview-image]');
    const qrTitle = document.querySelector('[data-qr-title]');
    const qrDownload = document.querySelector('[data-qr-download]');
    const confirmDialog = document.querySelector('[data-confirm-dialog]');
    const confirmTitle = document.querySelector('[data-confirm-title]');
    const confirmMessage = document.querySelector('[data-confirm-message]');
    const confirmCancel = document.querySelector('[data-confirm-cancel]');
    const confirmAccept = document.querySelector('[data-confirm-accept]');
    let pendingConfirmForm = null;

    document.querySelectorAll('form[data-confirm]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (form.dataset.confirmed === 'true') {
                delete form.dataset.confirmed;
                return;
            }

            event.preventDefault();

            const title = form.dataset.confirmTitle || 'Confirm action';
            const message = form.dataset.confirmMessage || form.dataset.confirm || 'This action cannot be undone.';

            if (!confirmDialog || typeof confirmDialog.showModal !== 'function') {
                if (window.confirm(`${title}\n\n${message}`)) {
                    form.dataset.confirmed = 'true';
                    form.requestSubmit();
                }
                return;
            }

            pendingConfirmForm = form;
            if (confirmTitle) confirmTitle.textContent = title;
            if (confirmMessage) confirmMessage.textContent = message;
            confirmDialog.showModal();
        });
    });

    confirmCancel?.addEventListener('click', () => {
        pendingConfirmForm = null;
        confirmDialog?.close();
    });

    confirmAccept?.addEventListener('click', () => {
        if (!pendingConfirmForm) return;

        const form = pendingConfirmForm;
        pendingConfirmForm = null;
        form.dataset.confirmed = 'true';
        confirmDialog?.close();
        form.requestSubmit();
    });

    document.querySelectorAll('[data-disable-on-submit]').forEach((form) => {
        form.addEventListener('submit', () => {
            form.querySelectorAll('button[type="submit"]').forEach((button) => {
                button.disabled = true;
                button.classList.add('opacity-70', 'cursor-not-allowed');

                if (button.dataset.submittingText) {
                    button.textContent = button.dataset.submittingText;
                }
            });
        });
    });

    document.querySelectorAll('[data-preview-qr]').forEach((button) => {
        button.addEventListener('click', () => {
            if (qrImage) qrImage.src = button.dataset.qrUrl || '';
            if (qrTitle) qrTitle.textContent = button.dataset.guestName || 'Guest pass';
            if (qrDownload) qrDownload.href = button.dataset.downloadUrl || '#';

            if (typeof qrDialog?.showModal === 'function') {
                qrDialog.showModal();
            } else {
                qrDialog?.setAttribute('open', '');
            }
        });
    });

    document.querySelector('[data-close-qr]')?.addEventListener('click', () => qrDialog?.close());
    qrDialog?.addEventListener('click', (event) => {
        if (event.target === qrDialog) qrDialog.close();
    });

    const scanner = document.querySelector('[data-scanner]');
    document.querySelectorAll('[data-manual-admit-card]').forEach((card) => {
        const quantity = card.querySelector('[data-manual-quantity]');
        const minus = card.querySelector('[data-manual-minus]');
        const plus = card.querySelector('[data-manual-plus]');

        const syncManualQuantity = (nextValue = quantity?.value) => {
            if (!quantity) return;
            quantity.value = String(clamp(nextValue, Number(quantity.min || 1), Number(quantity.max || 1)));
        };

        minus?.addEventListener('click', () => syncManualQuantity(Number(quantity?.value || 1) - 1));
        plus?.addEventListener('click', () => syncManualQuantity(Number(quantity?.value || 1) + 1));
        quantity?.addEventListener('input', () => syncManualQuantity());
    });

    if (!scanner) return;

    const csrf = scanner.dataset.csrf;
    const verifyUrl = scanner.dataset.verifyUrl;
    const admitUrl = scanner.dataset.admitUrl;
    const qrReader = scanner.querySelector('[data-qr-reader]');
    const cameraStatus = scanner.querySelector('[data-camera-status]');
    const startCameraButton = scanner.querySelector('[data-start-camera]');
    const stopCameraButton = scanner.querySelector('[data-stop-camera]');
    const scanAnotherButton = scanner.querySelector('[data-scan-another]');
    const manualToken = scanner.querySelector('[data-manual-token]');
    const verifyButton = scanner.querySelector('[data-verify-token]');
    const idleResult = scanner.querySelector('[data-idle-result]');
    const panel = scanner.querySelector('[data-result-panel]');
    const resultLabel = scanner.querySelector('[data-result-label]');
    const resultTitle = scanner.querySelector('[data-result-title]');
    const resultBadge = scanner.querySelector('[data-result-badge]');
    const resultMessage = scanner.querySelector('[data-result-message]');
    const allowedCount = scanner.querySelector('[data-allowed-count]');
    const usedCount = scanner.querySelector('[data-used-count]');
    const remainingCount = scanner.querySelector('[data-remaining-count]');
    const phoneNumber = scanner.querySelector('[data-phone-number]');
    const systemStatus = scanner.querySelector('[data-system-status]');
    const admitControls = scanner.querySelector('[data-admit-controls]');
    const quantityInput = scanner.querySelector('[data-admit-quantity]');
    const minusButton = scanner.querySelector('[data-quantity-minus]');
    const plusButton = scanner.querySelector('[data-quantity-plus]');
    const admitButton = scanner.querySelector('[data-admit-button]');
    const admittedBy = scanner.querySelector('[data-admitted-by]');

    const state = {
        scanner: null,
        isScanning: false,
        isStarting: false,
        isProcessing: false,
        scanLocked: false,
        token: '',
        guestId: null,
        remaining: 0,
        lastScannedCode: null,
        lastScannedAt: 0,
    };

    if (admittedBy) {
        admittedBy.value = localStorage.getItem('wedding_qr_officer') || '';
        admittedBy.addEventListener('input', () => localStorage.setItem('wedding_qr_officer', admittedBy.value));
    }

    const setCameraStatus = (message) => {
        if (cameraStatus) cameraStatus.textContent = message;
    };

    const getScannerState = () => {
        if (!state.scanner) return Html5QrcodeScannerState.NOT_STARTED;

        try {
            return state.scanner.getState();
        } catch (error) {
            return Html5QrcodeScannerState.NOT_STARTED;
        }
    };

    const scannerIsStarted = () => [
        Html5QrcodeScannerState.SCANNING,
        Html5QrcodeScannerState.PAUSED,
    ].includes(getScannerState());

    const scannerIsScanning = () => getScannerState() === Html5QrcodeScannerState.SCANNING;
    const scannerIsPaused = () => getScannerState() === Html5QrcodeScannerState.PAUSED;

    const ensureScanner = () => {
        if (!state.scanner && qrReader) {
            state.scanner = new Html5Qrcode(qrReader.id, {
                formatsToSupport: [Html5QrcodeSupportedFormats.QR_CODE],
                verbose: false,
            });
        }

        return state.scanner;
    };

    const clearResult = () => {
        idleResult?.removeAttribute('hidden');
        panel?.setAttribute('hidden', '');
        scanAnotherButton?.setAttribute('hidden', '');
        admitControls?.setAttribute('hidden', '');

        if (resultMessage) resultMessage.textContent = '';
        if (allowedCount) allowedCount.textContent = '0';
        if (usedCount) usedCount.textContent = '0';
        if (remainingCount) remainingCount.textContent = '0';
        if (phoneNumber) phoneNumber.textContent = 'Unavailable';
        if (systemStatus) systemStatus.textContent = 'Unknown';
        if (quantityInput) {
            quantityInput.max = '10';
            quantityInput.value = '1';
        }
    };

    const resetScanState = () => {
        state.isProcessing = false;
        state.scanLocked = false;
        state.token = '';
        state.guestId = null;
        state.remaining = 0;
        state.lastScannedCode = null;
        state.lastScannedAt = 0;

        if (manualToken) manualToken.value = '';
    };

    const pauseCameraForResult = async (message = 'Scan paused') => {
        state.isScanning = false;
        state.scanLocked = true;

        if (state.scanner && scannerIsScanning()) {
            try {
                state.scanner.pause(true);
                setCameraStatus(message);
                return;
            } catch (error) {
                await stopCamera(message);
                state.scanLocked = true;
                return;
            }
        }

        setCameraStatus(message);
    };

    const stopCamera = async (message = 'Camera stopped') => {
        state.isScanning = false;

        if (state.scanner && scannerIsStarted()) {
            try {
                await state.scanner.stop();
            } catch (error) {
                // html5-qrcode throws if stop is called while already stopped.
            }
        }

        state.scanLocked = false;
        setCameraStatus(message);
    };

    const displayStatus = (result) => ({
        valid: 'VALID PASS',
        admitted: 'ADMITTED SUCCESSFULLY',
        already_used: 'ALREADY FULLY USED',
        fully_used: 'ALREADY FULLY USED',
        wrong_event: 'WRONG EVENT',
        invalid: 'INVALID QR CODE',
        cancelled: 'CANCELLED PASS',
        revoked: 'REVOKED QR CODE',
        error: 'INVALID QR CODE',
        connection_error: 'CONNECTION ERROR',
    }[result] || 'SCAN RESULT');

    const badgeClass = (result) => {
        if (['valid', 'admitted'].includes(result)) return 'bg-emerald-600 text-white';
        if (['already_used', 'partially_used', 'over_limit', 'fully_used'].includes(result)) return 'bg-amber-400 text-zinc-950';
        if (['revoked', 'cancelled', 'invalid', 'wrong_event', 'error', 'connection_error'].includes(result)) return 'bg-rose-600 text-white';
        return 'bg-zinc-100 text-zinc-700';
    };

    const panelClass = (result) => {
        if (result === 'admitted') return 'scanner-result-panel scanner-result-admitted';
        if (result === 'valid') return 'scanner-result-panel scanner-result-valid';
        if (['already_used', 'fully_used'].includes(result)) return 'scanner-result-panel scanner-result-warning';
        return 'scanner-result-panel scanner-result-danger';
    };

    const normalizeValidationPayload = (payload) => {
        if (payload.guest) {
            const result = payload.result || payload.status || 'unknown';
            return {
                ...payload,
                result,
                ok: payload.ok ?? result === 'valid',
                guest: payload.guest,
            };
        }

        const result = payload.status || payload.result || 'unknown';
        const guest = payload.guest_id ? {
            id: payload.guest_id,
            guest_name: payload.guest_name,
            phone_number: payload.phone_number,
            pass_type: payload.pass_type,
            allowed_admissions: payload.allowed_entries,
            admitted_count: payload.used_entries,
            remaining_admissions: payload.remaining_entries,
            allowed_entries: payload.allowed_entries,
            used_entries: payload.used_entries,
            remaining_entries: payload.remaining_entries,
            usage_status: result,
            system_status: result,
        } : null;

        return {
            ...payload,
            ok: result === 'valid',
            result,
            message: payload.message || (result === 'valid' ? 'Valid pass.' : 'Scan again.'),
            guest,
        };
    };

    const setResult = (payload) => {
        payload = normalizeValidationPayload(payload);
        idleResult?.setAttribute('hidden', '');
        panel?.removeAttribute('hidden');
        scanAnotherButton?.removeAttribute('hidden');
        state.isScanning = false;
        state.scanLocked = true;

        const guest = payload.guest || null;
        const result = payload.result || 'unknown';
        const canAdmit = Boolean(payload.ok && result !== 'admitted' && guest && guest.remaining_admissions > 0);

        if (panel) panel.className = panelClass(result);
        if (resultLabel) resultLabel.textContent = guest?.pass_type || 'QR result';
        if (resultTitle) resultTitle.textContent = guest?.guest_name || 'Unknown pass';
        if (resultMessage) resultMessage.textContent = payload.message || displayStatus(result);
        if (resultBadge) {
            resultBadge.className = `shrink-0 rounded-md px-2.5 py-1 text-xs font-black ${badgeClass(result)}`;
            resultBadge.textContent = displayStatus(result);
        }
        if (allowedCount) allowedCount.textContent = guest?.allowed_admissions ?? 0;
        if (usedCount) usedCount.textContent = guest?.admitted_count ?? 0;
        if (remainingCount) remainingCount.textContent = guest?.remaining_admissions ?? 0;
        if (phoneNumber) phoneNumber.textContent = guest?.phone_number || 'Unavailable';
        if (systemStatus) systemStatus.textContent = (guest?.usage_status || guest?.system_status || result).replaceAll('_', ' ');

        state.token = payload.token || state.token || manualToken?.value || '';
        state.guestId = guest?.id || payload.guest_id || state.guestId || null;
        state.remaining = Number(guest?.remaining_admissions || 0);

        admitControls?.toggleAttribute('hidden', !canAdmit);
        if (quantityInput) {
            quantityInput.max = String(Math.max(1, state.remaining));
            quantityInput.value = String(clamp(quantityInput.value, 1, Math.max(1, state.remaining)));
        }
    };

    const verifyToken = async (rawToken) => {
        if (state.isProcessing) return false;

        const token = (rawToken || manualToken?.value || '').trim();
        if (!token) {
            setResult({ ok: false, result: 'invalid', message: 'Enter or scan a QR token.' });
            return false;
        }

        state.isProcessing = true;
        if (verifyButton) verifyButton.textContent = 'Verifying...';
        setCameraStatus('Validating QR code...');

        try {
            const { data } = await jsonRequest(verifyUrl, csrf, { scanned_url: token });
            if (manualToken) manualToken.value = data.token || token;
            setResult(data);
        } catch (error) {
            setResult({ ok: false, result: 'connection_error', message: 'Connection failed. Try again.' });
        }

        state.isProcessing = false;
        if (verifyButton) verifyButton.textContent = 'Verify pass';
        setCameraStatus('Result ready');
        return true;
    };

    const startCamera = async ({ reset = false } = {}) => {
        if (!qrReader) {
            setCameraStatus('Camera scanner unavailable');
            return;
        }

        if (state.isStarting || state.isProcessing) {
            return;
        }

        if (reset) {
            resetScanState();
            clearResult();
        }

        if (!window.isSecureContext) {
            setCameraStatus('Phone camera requires HTTPS');
            return;
        }

        if (!navigator.mediaDevices?.getUserMedia) {
            setCameraStatus('Camera not supported');
            return;
        }

        state.scanLocked = false;
        scanAnotherButton?.setAttribute('hidden', '');

        ensureScanner();

        if (state.scanner && scannerIsPaused()) {
            setCameraStatus('Resuming camera...');
            state.isStarting = true;

            try {
                state.scanner.resume();
                state.isScanning = true;
                setCameraStatus('Scanning QR code');
                window.setTimeout(() => {
                    state.isStarting = false;
                }, 300);
                return;
            } catch (error) {
                state.isStarting = false;
                await stopCamera('Restarting camera...');
            }
        }

        if (state.scanner && scannerIsScanning()) {
            state.isScanning = true;
            setCameraStatus('Scanning QR code');
            return;
        }

        setCameraStatus('Starting camera...');

        const scanConfig = {
            fps: 10,
            qrbox: { width: 240, height: 240 },
            aspectRatio: 1,
        };

        const onScanSuccess = async (decodedText) => {
            const scannedCode = (decodedText || '').trim();
            const now = Date.now();

            if (!scannedCode || state.isProcessing || state.scanLocked) return;
            if (state.lastScannedCode === scannedCode && now - state.lastScannedAt < 1500) return;

            state.scanLocked = true;
            state.lastScannedCode = scannedCode;
            state.lastScannedAt = now;
            if (manualToken) manualToken.value = scannedCode;
            setCameraStatus('QR detected');
            await pauseCameraForResult('Scan paused');
            await verifyToken(scannedCode);
        };

        try {
            state.isStarting = true;

            try {
                await state.scanner.start({ facingMode: 'environment' }, scanConfig, onScanSuccess);
            } catch (error) {
                const cameras = await Html5Qrcode.getCameras().catch(() => []);
                const fallbackCamera = cameras.find((camera) => /back|rear|environment/i.test(camera.label || '')) || cameras[0];

                if (!fallbackCamera?.id) {
                    throw error;
                }

                await state.scanner.start(fallbackCamera.id, scanConfig, onScanSuccess);
            }

            state.isScanning = true;
            setCameraStatus('Scanning QR code');
        } catch (error) {
            state.isScanning = false;
            state.scanLocked = false;
            const errorText = `${error?.name || ''} ${error?.message || error || ''}`.toLowerCase();

            if (errorText.includes('permission') || errorText.includes('notallowed') || errorText.includes('denied')) {
                setCameraStatus('Allow camera permission, then tap Start Camera');
            } else if (errorText.includes('notfound') || errorText.includes('overconstrained') || errorText.includes('no cameras')) {
                setCameraStatus('No camera found');
            } else {
                setCameraStatus('Camera could not start');
            }
        } finally {
            state.isStarting = false;
        }
    };

    startCameraButton?.addEventListener('click', startCamera);
    stopCameraButton?.addEventListener('click', () => stopCamera());
    scanAnotherButton?.addEventListener('click', () => startCamera({ reset: true }));
    verifyButton?.addEventListener('click', async () => {
        state.scanLocked = true;
        await pauseCameraForResult('Manual verification');
        await verifyToken(manualToken?.value || '');
    });
    manualToken?.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            state.scanLocked = true;
            pauseCameraForResult('Manual verification').then(() => verifyToken(manualToken?.value || ''));
        }
    });

    const syncQuantity = () => {
        if (!quantityInput) return;
        quantityInput.value = String(clamp(quantityInput.value, 1, Math.max(1, state.remaining)));
    };

    minusButton?.addEventListener('click', () => {
        if (!quantityInput) return;
        quantityInput.value = String(clamp(Number(quantityInput.value) - 1, 1, Math.max(1, state.remaining)));
    });

    plusButton?.addEventListener('click', () => {
        if (!quantityInput) return;
        quantityInput.value = String(clamp(Number(quantityInput.value) + 1, 1, Math.max(1, state.remaining)));
    });

    quantityInput?.addEventListener('input', syncQuantity);

    admitButton?.addEventListener('click', async () => {
        if (state.isProcessing) return;

        const quantity = clamp(quantityInput?.value, 1, Math.max(1, state.remaining));
        state.isProcessing = true;
        admitButton.disabled = true;
        admitButton.classList.add('opacity-70', 'cursor-not-allowed');
        admitButton.textContent = 'Recording...';

        try {
            const { data } = await jsonRequest(admitUrl, csrf, {
                guest_id: state.guestId,
                qr_token: state.token || manualToken?.value || '',
                entries_to_admit: quantity,
                admitted_by: admittedBy?.value || '',
                device_label: 'Mobile scanner',
            });

            setResult(data);
        } catch (error) {
            setResult({ ok: false, result: 'error', message: 'Network error. Try again.' });
        } finally {
            state.isProcessing = false;
            admitButton.disabled = false;
            admitButton.classList.remove('opacity-70', 'cursor-not-allowed');
            admitButton.textContent = 'Confirm Admission';
        }
    });

    ensureScanner();
    window.addEventListener('beforeunload', () => {
        if (state.scanner && scannerIsStarted()) {
            try {
                state.scanner.stop().catch(() => {});
            } catch (error) {
                // The browser can unload during a scanner state transition.
            }
        }
    });

    if ((scanner.dataset.initialToken || '').trim()) {
        verifyToken(scanner.dataset.initialToken);
    } else {
        resetScanState();
        clearResult();
        setCameraStatus('Tap Start Camera to scan');
    }
});
