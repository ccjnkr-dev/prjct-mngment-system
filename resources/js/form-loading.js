import loadingLogoUrl from '../Assets/Loading_Icon.gif';
import staticIconUrl from '../Assets/Icon.svg';

const pendingForms = new Map();
let outputRequests = 0;
let indicator;

function updateIndicator() {
    if (!indicator) {
        indicator = document.createElement('div');
        indicator.className = 'request-indicator';
        indicator.setAttribute('role', 'status');
        indicator.setAttribute('aria-live', 'polite');
        const picture = document.createElement('picture');
        picture.className = 'loading-brand';
        picture.setAttribute('aria-hidden', 'true');
        const reducedMotion = document.createElement('source');
        reducedMotion.media = '(prefers-reduced-motion: reduce)';
        reducedMotion.srcset = staticIconUrl;
        const image = document.createElement('img');
        image.src = loadingLogoUrl;
        image.alt = '';
        image.width = 40;
        image.height = 40;
        picture.append(reducedMotion, image);
        const label = document.createElement('span');
        label.textContent = 'Working…';
        indicator.append(picture, label);
        document.body.append(indicator);
    }
    indicator.hidden = pendingForms.size === 0 && outputRequests === 0;
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
    pendingForms.set(form, {buttons, submitter: submitter?.tagName === 'BUTTON' ? submitter : null, label: submitter?.innerHTML});
    form.setAttribute('aria-busy', 'true');
    // Disable buttons, leaving named fields enabled so the browser submits their values.
    buttons.forEach(({button}) => { button.disabled = true; });
    if (submitter?.tagName === 'BUTTON') {
        submitter.textContent = submitter.dataset.loadingLabel || (form.method.toLowerCase() === 'get' ? 'Loading…' : 'Saving…');
        submitter.classList.add('is-loading');
    }
    updateIndicator();
}, true);

window.addEventListener('pageshow', event => {
    if (!event.persisted) return;
    [...pendingForms.keys()].forEach(releaseForm);
    outputRequests = 0;
    updateIndicator();
});
document.querySelectorAll('dialog[data-reopen]').forEach(dialog => dialog.showModal());
