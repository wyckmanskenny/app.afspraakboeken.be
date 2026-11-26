const eye = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>';
const eyeOff = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m3 3 18 18M10.6 5.1A11.6 11.6 0 0 1 12 5c6.4 0 10 7 10 7a19.3 19.3 0 0 1-3.1 3.9M6.2 6.2A20.2 20.2 0 0 0 2 12s3.6 7 10 7a11.5 11.5 0 0 0 5.8-1.8M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>';
const controls = new WeakMap();
const observers = new WeakMap();
let nextId = 0;

/** Enhance existing nodes without copying, reading or storing password values. */
function enhance(input) {
    if (controls.has(input) || !input.parentNode) return;
    const doc = input.ownerDocument;
    const focused = doc.activeElement === input;
    const selection = focused ? [input.selectionStart, input.selectionEnd, input.selectionDirection] : null;
    const wrapper = doc.createElement('span');
    wrapper.className = 'password-field';
    input.before(wrapper);
    wrapper.append(input);
    if (!input.id) {
        let id;
        do { id = `password-input-${++nextId}`; } while (doc.getElementById(id));
        input.id = id;
    }
    const button = doc.createElement('button');
    button.type = 'button';
    button.className = 'password-toggle';
    button.setAttribute('aria-controls', input.id);
    const label = input.name === 'key' ? 'Installatiesleutel' : 'Wachtwoord';
    const refresh = () => {
        const visible = input.type === 'text';
        const action = `${label} ${visible ? 'verbergen' : 'tonen'}`;
        button.setAttribute('aria-label', action);
        button.setAttribute('title', action);
        button.setAttribute('aria-pressed', String(visible));
        button.innerHTML = visible ? eyeOff : eye;
    };
    let keepInputFocus = false;
    button.addEventListener('pointerdown', event => {
        keepInputFocus = doc.activeElement === input;
        if (keepInputFocus) event.preventDefault();
    });
    button.addEventListener('click', () => {
        const start = input.selectionStart;
        const end = input.selectionEnd;
        const direction = input.selectionDirection;
        input.type = input.type === 'password' ? 'text' : 'password';
        refresh();
        if (keepInputFocus) input.focus({preventScroll: true});
        if (start !== null && end !== null) input.setSelectionRange(start, end, direction || 'none');
        keepInputFocus = false;
    });
    controls.set(input, button);
    refresh();
    wrapper.append(button);
    if (focused) {
        input.focus({preventScroll: true});
        if (selection[0] !== null && selection[1] !== null) input.setSelectionRange(selection[0], selection[1], selection[2] || 'none');
    }
}

/** Covers both static forms and later login, activation or account renders. */
export function initPasswordVisibility(root = document) {
    if (observers.has(root)) return observers.get(root);
    const scan = node => {
        if (node.nodeType !== 1 && node.nodeType !== 9 && node.nodeType !== 11) return;
        if (node.matches?.('input[type="password"]')) enhance(node);
        node.querySelectorAll?.('input[type="password"]').forEach(enhance);
    };
    scan(root);
    const observer = new window.MutationObserver(records => {
        for (const record of records) for (const node of record.addedNodes) {
            if (root.contains(node)) scan(node);
        }
    });
    observer.observe(root, {childList: true, subtree: true});
    observers.set(root, observer);
    return observer;
}
