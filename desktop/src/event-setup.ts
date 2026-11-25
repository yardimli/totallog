import type {Row} from './logging-model';
export function enhanceEventSetup(dialog:HTMLDialogElement,task:Row) {
    dialog.classList.add('event-setup-dialog');
    const get=<T extends Element=HTMLElement>(selector:string)=>dialog.querySelector<T>(selector)!;
    const title=get('.dialog-header h2'), description=get('.form-fields>p');
    const heading=document.createElement('div');
    title.replaceWith(heading);heading.append(title,description);description.className='event-setup-description';
    get<HTMLTextAreaElement>('[name="options_text"]').placeholder='e.g. early, on time, late';
    const recurrence=get<HTMLSelectElement>('[name="recurrence_type"]');
    const daily=get('[data-recurrence="daily"]'), hint=daily.querySelector('small')!;
    const recurrenceRow=document.createElement('div');recurrenceRow.className='event-recurrence-row';
    recurrence.closest('label')!.before(recurrenceRow);
    recurrenceRow.append(recurrence.closest('label')!,daily,get('[name="daily_default_count"]').closest('label')!);
    recurrenceRow.after(hint);
    daily.className='event-daily-fields';
    const interval=get<HTMLInputElement>('[name="repeat_interval"]');
    const start=get<HTMLInputElement>('[name="repeat_start_date"]');
    const updateRecurrence=()=>{
        dialog.querySelectorAll<HTMLElement>('[data-recurrence]').forEach(group=>{
            const active=group.dataset.recurrence===recurrence.value;
            group.hidden=!active;
            group.querySelectorAll<HTMLInputElement>('input').forEach(input=>input.disabled=!active);
        });
        start.required=recurrence.value==='daily' && Number(interval.value)>1;
    };
    recurrence.addEventListener('change',updateRecurrence);interval.addEventListener('input',updateRecurrence);updateRecurrence();
    const sticky=get<HTMLInputElement>('[name="is_sticky"]'), after=get<HTMLInputElement>('[name="__show_after"]');
    const updateVisibility=()=>{
        get('[data-event-visibility]').hidden=!sticky.checked;
        get('[data-visibility-time]').hidden=!after.checked;
        get<HTMLInputElement>('[name="visible_after"]').disabled=!sticky.checked || !after.checked;
    };
    sticky.addEventListener('change',updateVisibility);after.addEventListener('change',updateVisibility);updateVisibility();
    const slots=get('[data-event-slots]'), slotValue=get<HTMLInputElement>('[name="scheduled_times_text"]');
    const syncSlots=()=>{slotValue.value=[...slots.querySelectorAll<HTMLInputElement>('input')].map(i=>i.value).filter(Boolean).join(', ');};
    const addSlot=(value='12:00')=>{
        const row=document.createElement('div');row.className='event-slot-row';
        const input=document.createElement('input');input.type='time';input.step='300';input.value=value;input.required=true;input.setAttribute('aria-label','Scheduled time');
        const remove=document.createElement('button');remove.type='button';remove.className='secondary';remove.textContent='×';remove.setAttribute('aria-label','Remove time slot');
        remove.addEventListener('click',()=>{row.remove();syncSlots();});input.addEventListener('input',syncSlots);input.addEventListener('change',syncSlots);
        row.append(input,remove);slots.append(row);syncSlots();
    };
    (task.scheduled_times || []).forEach((v:string)=>addSlot(v));
    get('[data-add-slot]').addEventListener('click',()=>{if(slots.childElementCount<24)addSlot();});
    const imageHost=get('[data-event-image]');
    const file=get<HTMLInputElement>('[name="__icon_file"]'), remove=get<HTMLInputElement>('[name="remove_icon"]');
    const preview=document.createElement('img');preview.className='event-image-preview';preview.alt='Event image preview';preview.hidden=!task.icon_data;
    if(task.icon_data)preview.src=task.icon_data;
    const upload=document.createElement('div');upload.className='event-upload-controls';
    upload.append(file.closest('label')!,remove.closest('label')!);
    const frame=document.createElement('div');frame.className='event-image-frame';frame.setAttribute('aria-label','Image preview');frame.append(preview);
    const appearance=get('[data-event-appearance]').parentElement!;
    const emoji=get('.emoji-picker'), color=get('.event-color');
    imageHost.append(upload,frame);
    appearance.className='event-appearance-row';
    appearance.replaceChildren(emoji,imageHost,color);
    let previewUrl='';
    file.addEventListener('change',()=>{
        if(previewUrl)URL.revokeObjectURL(previewUrl);
        if(file.files?.[0]) {previewUrl=URL.createObjectURL(file.files[0]);preview.src=previewUrl;preview.hidden=false;remove.checked=false;}
    });
    remove.addEventListener('change',()=>{preview.hidden=remove.checked || (!file.files?.length && !task.icon_data);});
    dialog.addEventListener('close',()=>{if(previewUrl)URL.revokeObjectURL(previewUrl);});
}
