import loadingLogoUrl from '../Assets/Loading_Icon.gif';
import staticIconUrl from '../Assets/Icon.svg';

const pendingForms = new Map();
let outputRequests = 0;
let indicator;
let loadingDialog;

function createLoadingPicture() {
    const picture = document.createElement('picture');
    picture.className = 'loading-brand';
    picture.setAttribute('aria-hidden', 'true');
    const reducedMotion = document.createElement('source');
    reducedMotion.media = '(prefers-reduced-motion: reduce)';
    reducedMotion.srcset = staticIconUrl;
    const image = document.createElement('img');
    image.src = loadingLogoUrl;
    image.alt = '';
    image.width = 64;
    image.height = 64;
    picture.append(reducedMotion, image);
    return picture;
}

function updateLoadingDialog() {
    if (pendingForms.size === 0) {
        if (loadingDialog?.open) loadingDialog.close();
        return;
    }
    if (!loadingDialog) {
        loadingDialog = document.createElement('dialog');
        loadingDialog.className = 'loading-dialog';
        loadingDialog.setAttribute('aria-labelledby', 'loading-dialog-label');
        loadingDialog.setAttribute('aria-describedby', 'loading-dialog-hint');
        loadingDialog.tabIndex = -1;
        const label = document.createElement('strong');
        label.id = 'loading-dialog-label';
        label.setAttribute('role', 'status');
        label.setAttribute('aria-live', 'polite');
        const hint = document.createElement('p');
        hint.id = 'loading-dialog-hint';
        hint.textContent = 'Please wait while we process your request.';
        loadingDialog.append(createLoadingPicture(), label, hint);
        // Keep the page blocked until completion, navigation, or error recovery.
        loadingDialog.addEventListener('cancel', event => event.preventDefault());
        document.body.append(loadingDialog);
    }
    const saved = [...pendingForms.values()].at(-1);
    loadingDialog.querySelector('strong').textContent = saved.loadingLabel;
    if (!loadingDialog.open) loadingDialog.showModal();
}

function updateIndicator() {
    if (!indicator) {
        indicator = document.createElement('div');
        indicator.className = 'request-indicator';
        indicator.setAttribute('role', 'status');
        indicator.setAttribute('aria-live', 'polite');
        const picture = createLoadingPicture();
        const label = document.createElement('span');
        label.textContent = 'Working…';
        indicator.append(picture, label);
        document.body.append(indicator);
    }
    indicator.hidden = outputRequests === 0 || pendingForms.size > 0;
    updateLoadingDialog();
}

async function submitDeletion(form) {
    let error = form.querySelector('[data-delete-error]');
    if (error) error.remove();
    try {
        const response = await fetch(form.action, {
            method: 'POST',
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            body: new FormData(form),
            signal: AbortSignal.timeout(65000),
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            const messages = {
                401: 'Your session expired. Refresh the page and sign in again.',
                419: 'Your session expired. Refresh the page and try again.',
                403: 'You no longer have permission to delete this item.',
                404: 'This item no longer exists. Refresh the page to update the list.',
            };
            throw new Error(messages[response.status] || (response.status < 500
                ? Object.values(data.errors ?? {}).flat().join(' ') || data.message
                : '') || 'Deletion failed. Please try again later.');
        }
        const destination = new URL(data.redirect, window.location.origin);
        if (!data.redirect || destination.origin !== window.location.origin) {
            throw new Error('Deletion completed. Refresh the page to update the list.');
        }
        window.location.assign(destination.href);
    } catch (failure) {
        releaseForm(form);
        error = document.createElement('p');
        error.dataset.deleteError = '';
        error.className = 'danger';
        error.setAttribute('role', 'alert');
        error.textContent = failure.name === 'TypeError' || failure.name === 'TimeoutError'
            ? 'Could not confirm deletion. Refresh the page before trying again.'
            : failure.message;
        form.append(error);
    }
}

export function releaseForm(form) {
    const saved = pendingForms.get(form);
    if (!saved) return;
    saved.buttons.forEach(({button, disabled}) => { button.disabled = disabled; });
    if (saved.submitter) {
        saved.submitter.innerHTML = saved.label;
        saved.submitter.classList.remove('is-loading');
    }
    form.removeAttribute('aria-busy');
    pendingForms.delete(form);
    updateIndicator();
}

export function beginOutput() {
    outputRequests += 1;
    updateIndicator();
    let finished = false;
    return () => {
        if (finished) return;
        finished = true;
        outputRequests = Math.max(0, outputRequests - 1);
        updateIndicator();
    };
}

document.addEventListener('submit', event => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || form.hasAttribute('wire:submit')) return;
    if (pendingForms.has(form)) {
        event.preventDefault();
        event.stopImmediatePropagation();
        return;
    }
    const submitter = event.submitter || form.querySelector('button:not([type]), button[type="submit"], input[type="submit"]');
    const buttons = [...form.querySelectorAll('button, input[type="submit"]')].map(button => ({button, disabled: button.disabled}));
    const loadingLabel = submitter?.dataset.loadingLabel || (form.method.toLowerCase() === 'get' ? 'Loading…' : 'Saving…');
    pendingForms.set(form, {buttons, submitter: submitter?.tagName === 'BUTTON' ? submitter : null, label: submitter?.innerHTML, loadingLabel});
    form.setAttribute('aria-busy', 'true');
    // Disable buttons, leaving named fields enabled so the browser submits their values.
    buttons.forEach(({button}) => { button.disabled = true; });
    if (submitter?.tagName === 'BUTTON') {
        submitter.textContent = loadingLabel;
        submitter.classList.add('is-loading');
    }
    updateIndicator();
    if (form.hasAttribute('data-delete-form')) {
        event.preventDefault();
        submitDeletion(form);
    }
}, true);

window.addEventListener('pageshow', event => {
    if (!event.persisted) return;
    [...pendingForms.keys()].forEach(releaseForm);
    outputRequests = 0;
    updateIndicator();
});
document.querySelectorAll('dialog[data-reopen]').forEach(dialog => dialog.showModal());
