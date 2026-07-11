(function(){
  function closestFromEventTarget(target, selector){
    if(target && target.nodeType === 3) target = target.parentElement;
    return target && typeof target.closest === 'function' ? target.closest(selector) : null;
  }

  function setMenuOpen(menu, isOpen){
    if(!menu) return;
    menu.classList.toggle('is-open', isOpen);
    const trigger = menu.querySelector('[data-menu-trigger]');
    if(trigger) trigger.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
  }

  function closeMenus(except){
    document.querySelectorAll('[data-menu].is-open').forEach(menu=>{
      if(menu !== except) setMenuOpen(menu, false);
    });
  }

  function toggleMenu(menu){
    if(!menu) return;
    const willOpen = !menu.classList.contains('is-open');
    closeMenus(menu);
    setMenuOpen(menu, willOpen);
  }

  function openModal(id){
    const el = document.getElementById(id);
    if(el){
      closeMenus();
      el.classList.add('is-open');
    }
  }

  function closeModal(el){
    if(el) el.classList.remove('is-open');
  }

  document.addEventListener('click', (e)=>{
    const menuTrigger = closestFromEventTarget(e.target, '[data-menu-trigger]');
    if(menuTrigger){
      e.preventDefault();
      toggleMenu(menuTrigger.closest('[data-menu]'));
      return;
    }

    const openBtn = closestFromEventTarget(e.target, '[data-modal-open]');
    if(openBtn){
      const id = openBtn.getAttribute('data-modal-open');
      if(id) openModal(id);
    }
    const closeBtn = closestFromEventTarget(e.target, '[data-modal-close]');
    if(closeBtn){
      const modal = closeBtn.closest('[data-modal]');
      if(modal) closeModal(modal);
    }
    const clickedMenu = closestFromEventTarget(e.target, '[data-menu]');
    if(!clickedMenu) closeMenus();
  });

  document.querySelectorAll('[data-modal]').forEach(modal=>{
    modal.addEventListener('click', (e)=>{ if(e.target === modal) closeModal(modal); });
  });

  document.addEventListener('keydown', (e)=>{
    if(e.key === 'Escape'){
      closeMenus();
      document.querySelectorAll('[data-modal].is-open').forEach(m => closeModal(m));
    }
  });

  const profileForm  = document.getElementById('profileForm');
  const profileAlert = document.getElementById('profileAlert');

  function showProfileMsg(msg, type){
    if(!profileAlert) return;
    profileAlert.textContent = msg;
    profileAlert.className = 'form-alert ' + (type==='success' ? 'alert-success' : 'alert-error');
    profileAlert.style.display = 'block';
  }

  if(profileForm){
    profileForm.addEventListener('submit', async (e)=>{
      e.preventDefault();
      const fd   = new FormData(profileForm);
      const current = (fd.get('currentpass')||'').toString().trim();
      const pass = (fd.get('newpass')||'').toString().trim();
      const conf = (fd.get('confirm')||'').toString().trim();
      if(current.length < 8){ showProfileMsg('Ingresa tu contraseña actual.', 'error'); return; }
      if(pass.length < 8){ showProfileMsg('La contraseña debe tener al menos 8 caracteres.', 'error'); return; }
      if(pass !== conf){ showProfileMsg('Las contraseñas no coinciden.', 'error'); return; }

      try{
        const res  = await fetch(profileForm.action || 'account_update.php', { method:'POST', body: fd });
        const data = await res.json().catch(()=>({}));
        if(!res.ok || !data.ok){ showProfileMsg(data.error || 'No se pudo actualizar la contraseña.', 'error'); return; }
        showProfileMsg('Contraseña actualizada correctamente.', 'success');
        setTimeout(()=>{ const modal = document.getElementById('profileModal'); if(modal) modal.classList.remove('is-open'); }, 1200);
      }catch(_){
        showProfileMsg('Error de red. Intenta de nuevo.', 'error');
      }
    });
  }

  const csrf = document.querySelector('meta[name="csrf"]')?.getAttribute('content') || '';

  const filtersToggle = document.querySelector('[data-filters-toggle]');
  const filtersPanel = document.getElementById('leadFilters');
  if(filtersToggle && filtersPanel){
    filtersToggle.addEventListener('click', ()=>{
      const isOpen = filtersPanel.classList.toggle('is-open');
      filtersToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    });
  }

  document.querySelectorAll('.filters-form select').forEach(select=>{
    select.addEventListener('change', ()=>{
      const form = select.closest('form');
      if(!form) return;
      if(typeof form.requestSubmit === 'function') form.requestSubmit();
      else form.submit();
    });
  });

  document.querySelectorAll('.sales-status-select').forEach(select=>{
    let previousValue = select.value;

    select.addEventListener('focus', ()=>{
      previousValue = select.value;
    });

    select.addEventListener('change', async (e)=>{
      const input = e.currentTarget;
      const tr = input.closest('tr');
      const id = input.getAttribute('data-id') || tr?.getAttribute('data-id');
      const nextValue = input.value;
      if(!id) return;

      input.disabled = true;
      input.classList.add('is-saving');

      try{
        const fd = new FormData();
        fd.append('id', id);
        fd.append('sales_status', nextValue);
        fd.append('csrf', csrf);

        const res = await fetch('update_sales_status.php', { method:'POST', body: fd });
        const data = await res.json().catch(()=>({ ok:false }));

        if(!res.ok || !data.ok){
          input.value = previousValue;
          alert(data.error || 'No se pudo actualizar el status comercial. Intenta de nuevo.');
          return;
        }

        previousValue = nextValue;
        input.dataset.status = nextValue;
        const updatedCell = input.closest('tr')?.querySelector('.updated-cell');
        if(updatedCell && data.updated_at){
          updatedCell.textContent = data.updated_at;
          updatedCell.setAttribute('title', data.updated_at);
        }
        if(input.closest('.funnel-card')){
          window.location.reload();
        }
      }catch(_){
        input.value = previousValue;
        alert('Error de red al actualizar el status comercial.');
      }finally{
        input.disabled = false;
        input.classList.remove('is-saving');
      }
    });
  });

  document.querySelectorAll('.notes-input').forEach(input=>{
    let previousValue = input.value;

    input.addEventListener('focus', ()=>{
      previousValue = input.value;
    });

    input.addEventListener('change', async (e)=>{
      const field = e.currentTarget;
      const id = field.getAttribute('data-id');
      const notes = field.value;
      if(!id) return;

      field.disabled = true;
      field.classList.add('is-saving');

      try{
        const fd = new FormData();
        fd.append('id', id);
        fd.append('notes', notes);
        fd.append('csrf', csrf);

        const res = await fetch('update_lead_notes.php', { method:'POST', body: fd });
        const data = await res.json().catch(()=>({ ok:false }));

        if(!res.ok || !data.ok){
          field.value = previousValue;
          alert(data.error || 'No se pudieron actualizar las anotaciones. Intenta de nuevo.');
          return;
        }

        previousValue = notes;
        const updatedCell = field.closest('tr')?.querySelector('.updated-cell');
        if(updatedCell && data.updated_at){
          updatedCell.textContent = data.updated_at;
          updatedCell.setAttribute('title', data.updated_at);
        }
        if(field.closest('.funnel-card')){
          window.location.reload();
        }
      }catch(_){
        field.value = previousValue;
        alert('Error de red al actualizar las anotaciones.');
      }finally{
        field.disabled = false;
        field.classList.remove('is-saving');
      }
    });
  });

  document.querySelectorAll('.reminder-control').forEach(control=>{
    const atInput = control.querySelector('.reminder-at');
    const noteInput = control.querySelector('.reminder-note');
    const clearBtn = control.querySelector('.reminder-clear');
    const id = control.getAttribute('data-id');
    if(!id || !atInput || !noteInput) return;

    async function saveReminder(){
      control.classList.add('is-saving');
      atInput.disabled = true;
      noteInput.disabled = true;
      if(clearBtn) clearBtn.disabled = true;

      try{
        const fd = new FormData();
        fd.append('id', id);
        fd.append('reminder_at', atInput.value || '');
        fd.append('reminder_note', noteInput.value || '');
        fd.append('csrf', csrf);

        const res = await fetch('update_lead_reminder.php', { method:'POST', body: fd });
        const data = await res.json().catch(()=>({ ok:false }));

        if(!res.ok || !data.ok){
          alert(data.error || 'No se pudo actualizar el recordatorio.');
          return;
        }

        const updatedCell = control.closest('tr')?.querySelector('.updated-cell');
        if(updatedCell && data.updated_at){
          updatedCell.textContent = data.updated_at;
          updatedCell.setAttribute('title', data.updated_at);
        }
        if(control.closest('.funnel-card')){
          window.location.reload();
        }
      }catch(_){
        alert('Error de red al actualizar el recordatorio.');
      }finally{
        atInput.disabled = false;
        noteInput.disabled = false;
        if(clearBtn) clearBtn.disabled = false;
        control.classList.remove('is-saving');
      }
    }

    atInput.addEventListener('change', saveReminder);
    noteInput.addEventListener('change', saveReminder);
    if(clearBtn){
      clearBtn.addEventListener('click', ()=>{
        atInput.value = '';
        noteInput.value = '';
        saveReminder();
      });
    }
  });

})();
