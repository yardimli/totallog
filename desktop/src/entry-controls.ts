import {systemDate,systemWallTime} from './system-format';
// Reuse the web app's accessible drag/keyboard time control and offline emoji data.
// @ts-ignore Shared web JavaScript module.
import { initTimeScroller } from '../../resources/js/time-scroller.mjs';
import groups from '../../public/data/data-by-group.json';

export function enhanceEntryControls(dialog: HTMLDialogElement) {
    const input = dialog.querySelector<HTMLInputElement>('input[name="occurred_at"]');
    if (input) {
        const label = input.closest('label')!;
        const root = document.createElement('section');
        root.className = 'entry-time-control';
        const heading = document.createElement('div');
        heading.className = 'section-heading';
        dialog.classList.add('entry-dialog');
        const now = document.createElement('button');
        now.type = 'button'; now.className = 'quiet'; now.textContent = 'Now';

        const display = document.createElement('button');
        display.type = 'button'; display.className = 'time-display';
        display.setAttribute('aria-label', 'Selected entry time');
        input.type = 'text'; input.pattern = '(?:[01][0-9]|2[0-3]):[0-5][0-9]|24:00';
        input.setAttribute('aria-label', 'Entry time');
        input.hidden = true;
        label.replaceWith(root); root.append(heading, input); heading.append(display, now);
        initTimeScroller(root, input, display, (value: string) => systemWallTime(value));
        now.addEventListener('click', () => {
            input.value = new Date().toTimeString().slice(0, 5);
            input.dispatchEvent(new Event('input', {bubbles: true}));
        });
        dialog.querySelector('.form-fields')!.prepend(root);
        const title = dialog.querySelector('.dialog-header h2')!;
        const titleGroup = document.createElement('div'); title.replaceWith(titleGroup); titleGroup.append(title);
        const stamp = document.createElement('small'); stamp.className = 'entry-date-time'; titleGroup.append(stamp);
        const updateStamp = () => { stamp.textContent = `${dialog.dataset.entryDate ? systemDate(dialog.dataset.entryDate) : ''} · ${systemWallTime(input.value)}`; };
        input.addEventListener('input', updateStamp); input.addEventListener('change', updateStamp); updateStamp();
        const text = dialog.querySelector<HTMLTextAreaElement>('textarea[name="content"], textarea[name="notes"]');
        if (text) { text.setAttribute('aria-label', 'Entry text'); text.placeholder = 'What happened?'; text.closest('label')!.replaceWith(text); }

    }
    const emoji = dialog.querySelector<HTMLInputElement>('input[name="emoji"]');
    if (emoji) {
        const host = document.createElement('section'); host.className = 'emoji-picker';
        const toggle = document.createElement('button'); toggle.type = 'button';
        const menu = document.createElement('div'); menu.hidden = true; menu.className = 'emoji-menu';
        const toolbar = document.createElement('div'); toolbar.className = 'emoji-toolbar';
        const search = document.createElement('input'); search.type = 'search'; search.placeholder = 'Search all emojis…'; search.setAttribute('aria-label', 'Search emojis');
        const category = document.createElement('select'); category.setAttribute('aria-label', 'Emoji category');
        groups.forEach((group, i) => category.add(new Option(group.name, String(i))));
        const grid = document.createElement('div'); grid.className = 'emoji-grid';
        const draw = () => {
            const terms = search.value.toLowerCase().trim().split(/\s+/).filter(Boolean);
            const emojis = terms.length ? groups.flatMap(g => g.emojis).filter(e => terms.every(t => `${e.name} ${e.slug} ${e.emoji}`.toLowerCase().includes(t))) : groups[Number(category.value)].emojis;
            grid.replaceChildren();
            for (const item of emojis) {
                const button = document.createElement('button'); button.type = 'button'; button.textContent = item.emoji;
                button.title = item.name; button.setAttribute('aria-label', item.name);
                button.addEventListener('click', () => { emoji.value = item.emoji; update(); menu.hidden = true; toggle.setAttribute('aria-expanded', 'false'); toggle.focus(); });
                grid.append(button);
            }
            if (!emojis.length) grid.textContent = 'No emojis match that search.';
        };
        const update = () => { toggle.textContent = `${emoji.value || '📝'}  Choose emoji`; };
        toggle.setAttribute('aria-expanded', 'false');
        toggle.addEventListener('click', () => { menu.hidden = !menu.hidden; toggle.setAttribute('aria-expanded', String(!menu.hidden)); if (!menu.hidden) { draw(); search.focus(); } });
        search.addEventListener('input', draw); category.addEventListener('change', () => {search.value = ''; draw();});
        emoji.closest('label')!.replaceWith(host); emoji.type = 'hidden';
        toolbar.append(search, category); menu.append(toolbar, grid); host.append(emoji, toggle, menu); update();
        const text = dialog.querySelector('textarea[name="content"], textarea[name="notes"]');
        if (text) text.before(host);
    }
}
