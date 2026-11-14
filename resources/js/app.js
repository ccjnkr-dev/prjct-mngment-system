import './bootstrap';
import './theme';
// Include assets referenced by Blade in the production Vite manifest.
import.meta.glob(['../Assets/Logo.svg']);
import { releaseForm, beginOutput } from './form-loading';
document.addEventListener('click',event=>{const open=event.target.closest('[data-open]');if(open)document.getElementById(open.dataset.open)?.showModal();if(event.target.closest('[data-close]'))event.target.closest('dialog')?.close();});
document.querySelectorAll('[data-upload]').forEach(form => form.addEventListener('submit', event => {
    event.preventDefault();
    const xhr = new XMLHttpRequest();
    const progress = form.querySelector('progress');
    const status = form.querySelector('[role=status]');
    const payload = new FormData(form);
    xhr.open('POST', form.action);
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.timeout = 180000;
    progress.hidden = false;
    status.textContent = 'Sending file…';
    xhr.upload.onprogress = event => {
        if (event.lengthComputable) progress.value = event.loaded / event.total * 100;
    };
    xhr.onload = () => {
        if (xhr.status >= 200 && xhr.status < 300) {
            status.textContent = 'Uploaded. Waiting for Google Drive…';
            location.reload();
            return;
        }
        try {
            const data = JSON.parse(xhr.responseText);
            status.textContent = Object.values(data.errors ?? {}).flat().join(' ') || data.message || 'Upload failed.';
            if (xhr.status === 423 && data.redirect) location.assign(data.redirect);
        } catch {
            status.textContent = 'Upload failed. Check the connection and try again.';
        }
        releaseForm(form);
    };
    const failed = () => {
        status.textContent = 'Connection interrupted or timed out. Try again.';
        releaseForm(form);
    };
    xhr.onerror = failed;
    xhr.ontimeout = failed;
    xhr.onabort = failed;
    xhr.send(payload);
}));
const gantt=document.getElementById('gantt');
if(gantt){let tasks=[],scale=28,saving=false;const finishLoading=beginOutput();gantt.setAttribute('aria-busy','true');gantt.textContent='Loading timeline…';const day=86400000;const parse=date=>Date.parse(date+'T00:00:00Z');const fmt=ms=>new Date(ms).toISOString().slice(0,10);const notice=document.getElementById('timeline-status');const csrf=document.querySelector('meta[name=csrf-token]').content;
 const render=()=>{gantt.replaceChildren();const scheduled=tasks.filter(t=>t.start&&t.end);if(!scheduled.length){gantt.textContent='No dated tasks yet. Add start and due dates in Tasks to see your timeline.';return;}const origin=Math.min(...scheduled.map(t=>parse(t.start)))-day*2;const end=Math.max(...scheduled.map(t=>parse(t.end)))+day*5;const days=Math.max(14,Math.ceil((end-origin)/day));const caption=document.createElement('p');caption.className='gantt-caption';caption.textContent=fmt(origin)+' — '+fmt(origin+(days-1)*day);gantt.append(caption);const canvas=document.createElement('div');canvas.className='gantt-canvas';canvas.style.width=(220+days*scale)+'px';const header=document.createElement('div');header.className='gantt-header';header.innerHTML='<strong>Task / milestone</strong>';for(let i=0;i<days;i++){const d=document.createElement('span');d.style.width=scale+'px';d.textContent=new Date(origin+i*day).getUTCDate();d.title=fmt(origin+i*day);header.append(d);}canvas.append(header);
 scheduled.forEach(task=>{const row=document.createElement('div');row.className='gantt-row';const label=document.createElement('strong');label.textContent=task.title;row.append(label);const track=document.createElement('div');track.className='gantt-track';track.style.backgroundSize=scale+'px 100%';const bar=document.createElement('button');bar.type='button';bar.className='gantt-bar '+task.status+(task.milestone?' milestone':'');bar.style.left=((parse(task.start)-origin)/day*scale)+'px';bar.style.width=(task.milestone?20:Math.max(scale,(parse(task.end)-parse(task.start))/day*scale+scale))+'px';bar.textContent=task.milestone?'◆':task.title;bar.title=task.title+': '+task.start+' → '+task.end;const grip=document.createElement('span');grip.className='resize';if(!task.milestone)bar.append(grip);
 bar.addEventListener('pointerdown',event=>{if(event.button!==0||saving)return;event.preventDefault();bar.setPointerCapture(event.pointerId);const startX=event.clientX;const resize=event.target===grip;const left=parseFloat(bar.style.left),width=parseFloat(bar.style.width);const move=e=>{const delta=Math.round((e.clientX-startX)/scale);if(resize)bar.style.width=Math.max(scale,width+delta*scale)+'px';else bar.style.left=left+delta*scale+'px';};const up=async e=>{bar.removeEventListener('pointermove',move);bar.removeEventListener('pointerup',up);bar.removeEventListener('pointercancel',cancel);const delta=Math.round((e.clientX-startX)/scale);if(!delta)return;const data={...task,start_date:resize?task.start:fmt(parse(task.start)+delta*day),due_date:fmt(Math.max(parse(task.start),parse(task.end)+delta*day)),priority:task.priority??'medium',assignee_id:task.assignee_id??null};if(task.milestone)data.due_date=data.start_date;if(data.due_date<data.start_date)data.due_date=data.start_date;saving=true;gantt.setAttribute('aria-busy','true');const finishSaving=beginOutput();notice.textContent='Saving dates…';try{const r=await fetch('/projects/'+gantt.dataset.project+'/tasks/'+task.id,{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':csrf},body:JSON.stringify(data)});if(!r.ok)throw Error('Could not save dates. Your access or task details may have changed.');task.start=data.start_date;task.end=data.due_date;notice.textContent='Dates saved.';}catch(err){notice.textContent=err.message;}finally{saving=false;gantt.removeAttribute('aria-busy');finishSaving();}render();};const cancel=()=>{bar.removeEventListener('pointermove',move);bar.removeEventListener('pointerup',up);bar.removeEventListener('pointercancel',cancel);render();};bar.addEventListener('pointermove',move);bar.addEventListener('pointerup',up);bar.addEventListener('pointercancel',cancel);});track.append(bar);row.append(track);canvas.append(row);});gantt.append(canvas);};
 fetch(gantt.dataset.url,{headers:{Accept:'application/json'}}).then(r=>{if(!r.ok)throw Error();return r.json();}).then(data=>{tasks=data.data;render();}).catch(()=>gantt.textContent='Unable to load the timeline. Refresh to try again.').finally(()=>{gantt.removeAttribute('aria-busy');finishLoading();});document.getElementById('timeline-scale').addEventListener('change',e=>{scale=e.target.value==='month'?16:28;render();});}
