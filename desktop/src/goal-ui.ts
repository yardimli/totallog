import {systemDate,systemDateTime} from './system-format';
import {calendarRange, localDate, type Row} from './logging-model';
const esc = (v: unknown) => String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]!));
const button = (label: string, action: string, id: unknown = '', cls = 'secondary') => `<button type="button" class="${cls}" data-action="${action}" data-id="${esc(id)}">${label}</button>`;
const dayOf = (value: unknown) => String(value).endsWith('Z') ? localDate(new Date(String(value))) : String(value).slice(0,10);
export const goalColor = (g: Row) => /^#[\da-f]{6}$/i.test(g.color || '') ? g.color : '#5145e5';
export const goalIcon = (g: Row) => `<span class="goal-icon" style="background:${goalColor(g)}">${/^data:image\/png;base64,/.test(g.icon_data || '') ? `<img alt="" src="${esc(g.icon_data)}">` : esc(g.emoji || '🎯')}</span>`;
export function goalSnapshot(g: Row, date: string, week: number) {
    const range = g.period === 'none' ? {from: '0000', to:'9999'} : g.period === 'daily' ? {from:date,to:date} : calendarRange(date,g.period === 'monthly' ? 'month' : 'week',week);
    const from = [range.from, g.start_date || '0000'].sort().at(-1)!;
    const to = [range.to, g.end_date || '9999'].sort()[0];
    const entries = (g.entries || []).filter((e: Row) => dayOf(e.occurred_at) >= from && dayOf(e.occurred_at) <= to && dayOf(e.occurred_at) <= date).sort((a: Row,b: Row) => String(b.occurred_at).localeCompare(String(a.occurred_at)));
    return {from,to,entries,points:entries.reduce((n:number,e:Row)=>n+Number(e.points),0)};
}
export function shiftGoalPeriod(g: Row, date: string, direction: number) {
    const d = new Date(`${date}T12:00:00`);
    if(g.period === 'monthly') { d.setDate(1); d.setMonth(d.getMonth()+direction); }
    else d.setDate(d.getDate()+direction*(g.period === 'daily' ? 1 : 7));
    return localDate(d);
}
const bounds = (s: {from:string,to:string}) => `${s.from === '0000' ? 'Open start' : systemDate(s.from)} – ${s.to === '9999' ? 'Open end' : systemDate(s.to)}`;
const schedule = (g: Row) => `${g.period === 'none' ? 'One time' : `${g.period || 'weekly'} reset`} · ${g.start_date ? systemDate(g.start_date) : 'Open start'} → ${g.end_date ? systemDate(g.end_date) : 'Open end'}`;
function sourceLabels(g: Row, tasks: Row[]) {
    return [...(g.sources || []).map((s: Row) => s.type === 'github' ? `💻 GitHub: ${s.github_project}` : `✅ Event: ${tasks.find(t=>String(t.id)===String(s.task_definition_id))?.name || 'Deleted event'}`), ...(g.manual_enabled ? ['✍ Manual points'] : [])];
}
function latest(g: Row) {
    const value = (g.entries || []).map((e:Row)=>String(e.occurred_at)).sort().at(-1);
    if(!value) return 'No activity';
    const mins = Math.max(0,Math.floor((Date.now()-new Date(value).getTime())/60000));
    return mins < 1 ? 'Just now' : mins < 60 ? `${mins} minutes ago` : mins < 1440 ? `${Math.floor(mins/60)} hours ago` : `${Math.floor(mins/1440)} days ago`;
}
export function goalStrip(goals: Row[],date: string,week: number,compact=false) {
    if(!goals.length) return "";
    return `<div class="goal-strip ${compact ? 'day-goals' : ''}" aria-label="Goals">${goals.map(g=>{
        const hex=goalColor(g), rgb=[1,3,5].map(i=>parseInt(hex.slice(i,i+2),16));
        const color=rgb[0]*.299+rgb[1]*.587+rgb[2]*.114>155?'#101828':'#fff';
        return `<button class="goal-pill" type="button" data-action="goal-details" data-id="${esc(g.id)}" style="background:${hex};color:${color}">${goalIcon(g)}<span><strong>${esc(g.name)}</strong>${compact ? "" : `<small>${goalSnapshot(g,date,week).points}/${g.target_points} points · ${latest(g)}</small>`}</span>${compact ? `<span class="goal-count">${goalSnapshot(g,date,week).points}/${g.target_points}</span>` : ""}</button>`;
    }).join('')}</div>`;
}
export function goalList(goals: Row[],tasks: Row[]) {
    return `<header class="page-toolbar"><div><p class="eyebrow">Goal setup</p><h2>Goals & progress</h2></div>${button('+ Add goal','new-goal')}</header><section class="workspace-card"><h3>Your goals <small>${goals.length} goals</small></h3><p class="muted">Open a goal to see progress, or choose Setup to change its target and sources.</p></section><div class="goal-list">${goals.map(g=>`<article class="workspace-card goal-list-row">${button(`${goalIcon(g)}<span><strong>${esc(g.name)}</strong><small>${g.target_points} points · ${esc(schedule(g))}</small><small>${esc(sourceLabels(g,tasks).join(' · ') || 'No progress sources')}</small></span>`,'goal-details',g.id,'goal-list-link')}${button('Setup','edit-goal',g.id)}</article>`).join('') || '<p>No goals yet.</p>'}</div>`;
}
export function goalDetails(g: Row, date: string, week: number, tasks: Row[]) {
    const s=goalSnapshot(g,date,week), percent=Math.min(100,Math.round(s.points/Math.max(1,g.target_points)*100));
    const history=Array.from({length:g.period==='none'?1:12},(_,i)=>{const d=shiftGoalPeriod(g,date,-i);return {date:d,...goalSnapshot(g,d,week)};}).filter(h=>!g.start_date||h.to>=g.start_date);
    return `<header class="page-header goal-detail-header">${goalIcon(g)}<div><p class="eyebrow">Goal details</p><h2>${esc(g.name)}</h2></div>${button('Goal setup','edit-goal',g.id)}</header><div class="goal-detail-page"><div class="actions goal-navigation">${g.period==='none'?'':button('← Previous period','goal-period','-1')+button('Current','goal-period','0')+button('Next period →','goal-period','1')}${button('View calendar','page','calendar')}</div><section class="workspace-card goal-summary"><p>${bounds(s)}</p><div class="section-heading"><p class="goal-number">${s.points} <small>/ ${g.target_points} points</small></p><span class="source-chip">${percent}%</span></div><progress max="${g.target_points}" value="${Math.min(s.points,g.target_points)}" style="--goal-color:${goalColor(g)};accent-color:${goalColor(g)}"></progress><p class="muted">${esc(schedule(g))} · ${latest(g)}</p></section><section class="workspace-card"><h3>Progress sources</h3><div class="source-chips">${sourceLabels(g,tasks).map(t=>`<span class="source-chip">${esc(t)}</span>`).join('') || '<p>No progress source configured.</p>'}</div></section>${g.manual_enabled?`<section class="workspace-card">${button('＋ Add progress','goal-progress',g.id)}</section>`:''}<div class="goal-detail-columns"><section class="workspace-card"><h3>Activity in this period</h3>${s.entries.map((e:Row)=>`<div class="goal-activity"><span class="source-chip">+${e.points}</span><div><strong>${esc(e.note || 'Progress')}</strong><small>${esc(g.sources?.find((source:Row)=>String(source.id)===String(e.goal_source_id))?.type || 'Manual')} · ${esc(systemDateTime(e.occurred_at))}</small></div></div>`).join('') || '<p class="muted">No progress recorded in this period.</p>'}</section><section class="workspace-card"><h3>Period history</h3>${history.map(h=>button(`<span>${bounds(h)}</span><strong>${h.points}/${g.target_points}</strong>`,'goal-history',h.date,'goal-history-row secondary')).join('')}</section></div></div>`;
}
export function enhanceGoalSetup(dialog: HTMLDialogElement, projects: string[]) {
    dialog.classList.add('goal-setup-dialog');
    const title=dialog.querySelector('.dialog-header h2')!, description=dialog.querySelector('.form-fields>p')!;
    const heading=document.createElement('div');title.replaceWith(heading);heading.append(title,description);description.className='event-setup-description';
    const appearance=document.createElement('div');appearance.className='goal-appearance-row';
    const emoji=dialog.querySelector('.emoji-picker')!, image=dialog.querySelector('[data-goal-image]')!, color=dialog.querySelector('[name="color"]')!.closest('label')!;
    emoji.before(appearance);appearance.append(emoji,image,color);
    const input=dialog.querySelector<HTMLInputElement>('[name="github_projects_text"]')!;
    const selected=new Set(input.value.split('\n').filter(Boolean));
    const search=dialog.querySelector<HTMLInputElement>('[data-repo-search]')!;
    const list=dialog.querySelector<HTMLElement>('[data-repo-results]')!;
    const chips=dialog.querySelector<HTMLElement>('[data-repo-chips]')!;
    const draw=()=>{
        input.value=[...selected].join('\n'); list.replaceChildren(); chips.replaceChildren();
        projects.filter(p=>!selected.has(p)&&p.toLowerCase().includes(search.value.trim().toLowerCase())).forEach(p=>{
            const b=document.createElement('button'); b.type='button'; b.textContent=p; b.setAttribute('aria-label',`Add repository ${p}`);
            b.addEventListener('click',()=>{selected.add(p);draw();});list.append(b);
        });
        if(!list.childElementCount) list.textContent='No matching repositories in your synced logs.';
        selected.forEach(p=>{const b=document.createElement('button');b.type='button';b.className='source-chip';b.textContent=`${p} ×`;b.setAttribute('aria-label',`Remove repository ${p}`);b.addEventListener('click',()=>{selected.delete(p);draw();});chips.append(b);});
    };
    search.addEventListener('input',draw);draw();
}
