export const clampTime = minutes => Math.max(0, Math.min(1440, minutes));
export const snapTime = minutes => clampTime(Math.round(minutes / 5) * 5);
export const clockValue = minutes => `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`;
export const stripOffset = minutes => 25 - clampTime(minutes) / 1440 * 100;

export function initTimeScroller(root, input, button, formatClock) {
    const viewport = document.createElement('div');
    viewport.className = 'time-scroller';
    viewport.tabIndex = 0;
    viewport.setAttribute('role', 'slider');
    viewport.setAttribute('aria-label', 'Time');
    viewport.setAttribute('aria-orientation', 'horizontal');
    viewport.setAttribute('aria-valuemin', '0');
    viewport.setAttribute('aria-valuemax', '1440');
    viewport.setAttribute('aria-description', 'Drag the picture or scroll to choose a time. Snaps to five minutes. Arrow keys adjust five minutes; Page Up and Page Down adjust an hour. Home and End select the day boundaries.');
    const strip = document.createElement('div');
    strip.className = 'time-scroller-strip';
    strip.setAttribute('aria-hidden', 'true');
    const artwork = document.createElement('div');
    artwork.className = 'time-scroller-artwork';
    const ruler = document.createElement('div');
    ruler.className = 'time-scroller-ruler';
    for (let minute = 0; minute <= 1440; minute += 60) {
        const tick = document.createElement('span');
        tick.className = 'time-scroller-tick';
        tick.style.left = `${minute / 1440 * 100}%`;
        if (minute % 180 === 0) tick.textContent = formatClock(clockValue(minute));
        ruler.append(tick);
    }
    strip.append(artwork, ruler);
    const cursor = document.createElement('div');
    cursor.className = 'time-scroller-cursor';
    cursor.setAttribute('aria-hidden', 'true');
    viewport.append(strip, cursor);
    root.append(viewport);
    let selected = 720, pointer = null, originX = 0, originTime = 0, timer, dirty = false, committing = false;
    const render = (animate = false) => {
        viewport.classList.toggle('is-settling', animate);
        strip.style.transform = `translateX(${stripOffset(selected)}%)`;
        const value = clockValue(snapTime(selected));
        button.textContent = formatClock(value);
        viewport.setAttribute('aria-valuenow', String(snapTime(selected)));
        viewport.setAttribute('aria-valuetext', formatClock(value));
    };
    const finish = () => {
        clearTimeout(timer);
        if (!dirty || input.disabled) return;
        dirty = false;
        selected = snapTime(selected);
        render(true);
        const value = clockValue(selected);
        if (input.value === value) return;
        input.value = value;
        committing = true;
        input.dispatchEvent(new Event('input', {bubbles: true}));
        input.dispatchEvent(new Event('change', {bubbles: true}));
        committing = false;
    };
    const update = () => {
        if (committing) return;
        clearTimeout(timer);
        dirty = false;
        const [hour, minute] = (input.value || '12:00').split(':').map(Number);
        selected = clampTime(Number.isFinite(hour + minute) ? hour * 60 + minute : 720);
        render();
        // Opening an existing entry must not round its saved time.
        button.textContent = formatClock(clockValue(selected));
        viewport.setAttribute('aria-valuenow', String(selected));
        viewport.setAttribute('aria-valuetext', formatClock(clockValue(selected)));
    };
    const select = minutes => { selected = clampTime(minutes); dirty = true; render(); };
    viewport.addEventListener('pointerdown', event => {
        if (input.disabled || pointer !== null || (event.pointerType === 'mouse' && event.button !== 0)) return;
        event.stopPropagation();
        clearTimeout(timer);
        viewport.focus({preventScroll: true});
        pointer = event.pointerId;
        originX = event.clientX;
        originTime = selected;
        viewport.classList.add('is-dragging');
        viewport.classList.remove('is-settling');
        viewport.setPointerCapture(pointer);
    });
    viewport.addEventListener('pointermove', event => {
        if (pointer !== event.pointerId || input.disabled) return;
        const width = viewport.getBoundingClientRect().width;
        if (!width || (event.clientX === originX && !dirty)) return;
        select(originTime - (event.clientX - originX) * 720 / width);
    });
    const release = event => {
        if (pointer !== event.pointerId) return;
        pointer = null;
        viewport.classList.remove('is-dragging');
        if (viewport.hasPointerCapture(event.pointerId)) viewport.releasePointerCapture(event.pointerId);
        if (event.type === 'pointercancel') update();
        else finish();
    };
    viewport.addEventListener('pointerup', release);
    viewport.addEventListener('pointercancel', release);
    viewport.addEventListener('lostpointercapture', release);
    viewport.addEventListener('wheel', event => {
        if (input.disabled || pointer !== null) return;
        const delta = Math.abs(event.deltaX) > Math.abs(event.deltaY) ? event.deltaX : event.deltaY;
        if (!delta) return;
        event.preventDefault();
        event.stopPropagation();
        const width = viewport.getBoundingClientRect().width || 360;
        const scale = event.deltaMode === 1 ? 16 : event.deltaMode === 2 ? width : 1;
        const mouseNotch = event.deltaMode === 1 || (!event.deltaX && Math.abs(delta) >= 100 && Number.isInteger(delta));
        select(selected + (mouseNotch ? Math.sign(delta) * 5 : delta * scale * 144 / width));
        clearTimeout(timer);
        timer = setTimeout(finish, 160);
    }, {passive: false});
    viewport.addEventListener('keydown', event => {
        if (input.disabled) return;
        const steps = {ArrowRight: 5, ArrowUp: 5, ArrowLeft: -5, ArrowDown: -5, PageUp: 60, PageDown: -60};
        if (!(event.key in steps) && !['Home', 'End'].includes(event.key)) return;
        event.preventDefault();
        event.stopPropagation();
        select(event.key === 'Home' ? 0 : event.key === 'End' ? 1440 : snapTime(selected) + steps[event.key]);
        finish();
    });
    viewport.addEventListener('blur', finish);
    button.addEventListener('click', () => viewport.focus());
    input.addEventListener('change', update);
    input.addEventListener('input', update);
    const syncDisabled = () => {
        viewport.setAttribute('aria-disabled', String(input.disabled));
        viewport.tabIndex = input.disabled ? -1 : 0;
        if (input.disabled) update();
    };
    new MutationObserver(syncDisabled).observe(input, {attributes: true, attributeFilter: ['disabled']});
    input.form?.addEventListener('reset', () => setTimeout(update, 0));
    syncDisabled();
    update();
}
