export const clampTime = minutes => Math.max(0, Math.min(1439, Math.round(minutes)));
export const windowStart = minutes => Math.max(0, Math.min(720, minutes - 360));
export const clockValue = minutes => `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`;

export function initTimeScroller(root, input, button, formatClock) {
    const container = document.createElement('div');
    container.className = 'time-scroller';
    const range = document.createElement('input');
    range.type = 'range';
    range.step = '1';
    range.setAttribute('aria-label', 'Time');
    range.setAttribute('aria-description', 'Drag or scroll to select a time. Arrow keys adjust by one minute. Home and End select the start and end of the day.');
    const labels = document.createElement('div');
    labels.className = 'time-scroller-labels';
    labels.setAttribute('aria-hidden', 'true');
    const ticks = Array.from({length: 5}, () => {
        const tick = document.createElement('span');
        labels.append(tick);
        return tick;
    });
    container.append(range, labels);
    root.append(container);
    let selected = 720, start = 360, dragging = false, timer;
    const render = () => {
        range.min = String(start);
        range.max = String(start + 720);
        range.value = String(selected);
        range.setAttribute('aria-valuetext', formatClock(clockValue(selected)));
        button.textContent = formatClock(clockValue(selected));
        ticks.forEach((tick, index) => { tick.textContent = formatClock(clockValue(start + index * 180)); });
    };
    const update = () => {
        clearTimeout(timer);
        const [hour, minute] = (input.value || '12:00').split(':').map(Number);
        selected = clampTime(hour * 60 + minute);
        start = windowStart(selected);
        range.disabled = input.disabled;
        render();
    };
    const finish = () => {
        clearTimeout(timer);
        start = windowStart(selected);
        render();
        const value = clockValue(selected);
        if (input.value === value) return;
        input.value = value;
        input.dispatchEvent(new Event('input', {bubbles: true}));
        input.dispatchEvent(new Event('change', {bubbles: true}));
    };
    range.addEventListener('pointerdown', event => {
        event.stopPropagation();
        clearTimeout(timer);
        dragging = true;
        range.setPointerCapture(event.pointerId);
    });
    const release = () => { if (dragging) { dragging = false; finish(); } };
    range.addEventListener('pointerup', release);
    range.addEventListener('pointercancel', release);
    range.addEventListener('lostpointercapture', release);
    range.addEventListener('input', event => {
        event.stopPropagation();
        selected = clampTime(Number(range.value));
        render();
    });
    range.addEventListener('change', event => {
        event.stopPropagation();
        if (!dragging) finish();
    });
    range.addEventListener('wheel', event => {
        if (range.disabled || dragging) return;
        const delta = Math.abs(event.deltaX) > Math.abs(event.deltaY) ? event.deltaX : event.deltaY;
        if (!delta) return;
        event.preventDefault();
        event.stopPropagation();
        selected = clampTime(selected + Math.sign(delta) * 5);
        // Pan only when reaching the visible edge; recenter when the gesture ends.
        start = Math.max(0, Math.min(720, Math.max(selected - 720, Math.min(start, selected))));
        render();
        clearTimeout(timer);
        timer = setTimeout(finish, 180);
    }, {passive: false});
    range.addEventListener('keydown', event => {
        if (!['Home', 'End', 'PageUp', 'PageDown'].includes(event.key)) return;
        event.preventDefault();
        selected = event.key === 'Home' ? 0 : event.key === 'End' ? 1439 : clampTime(selected + (event.key === 'PageUp' ? 60 : -60));
        finish();
    });
    range.addEventListener('blur', finish);
    button.addEventListener('click', () => range.focus());
    input.addEventListener('change', update);
    input.addEventListener('input', update);
    new MutationObserver(() => { range.disabled = input.disabled; }).observe(input, {attributes: true, attributeFilter: ['disabled']});
    update();
}
