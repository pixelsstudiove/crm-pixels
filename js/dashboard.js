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

  function escapeHtml(value){
    return String(value ?? '').replace(/[&<>"']/g, (char)=>({
      '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#039;'
    }[char]));
  }

  function ensureLeadModal(){
    let modal = document.getElementById('leadWorkflowModal');
    if(modal) return modal;
    modal = document.createElement('div');
    modal.id = 'leadWorkflowModal';
    modal.className = 'modal-backdrop';
    modal.setAttribute('data-modal', '');
    modal.innerHTML = `
      <div class="modal" role="dialog" aria-modal="true" aria-labelledby="leadWorkflowTitle">
        <h2 id="leadWorkflowTitle"></h2>
        <p class="subtitle" id="leadWorkflowSubtitle"></p>
        <div id="leadWorkflowBody"></div>
      </div>
    `;
    modal.addEventListener('click', (event)=>{
      if(event.target === modal) modal.classList.remove('is-open');
    });
    document.body.appendChild(modal);
    return modal;
  }

  function requestStatusChangeReason(previousLabel, nextLabel){
    return new Promise((resolve)=>{
      const modal = ensureLeadModal();
      const title = modal.querySelector('#leadWorkflowTitle');
      const subtitle = modal.querySelector('#leadWorkflowSubtitle');
      const body = modal.querySelector('#leadWorkflowBody');
      title.textContent = 'Motivo del cambio';
      subtitle.textContent = `${previousLabel || 'Status actual'} → ${nextLabel || 'Nuevo status'}`;
      body.innerHTML = `
        <label class="field">
          <span class="field-label">Motivo del cambio</span>
          <textarea id="statusChangeReason" maxlength="1000" placeholder="Ej: Cliente solicitó presupuesto, se validó interés o no respondió al seguimiento."></textarea>
        </label>
        <div class="form-alert alert-error" id="statusChangeError" style="display:none"></div>
        <div class="actions">
          <button type="button" class="btn-secondary" data-reason-cancel>Cancelar</button>
          <button type="button" class="btn-secondary" data-reason-save>Guardar cambio</button>
        </div>
      `;
      const textarea = body.querySelector('#statusChangeReason');
      const error = body.querySelector('#statusChangeError');
      const finish = (value)=>{
        modal.classList.remove('is-open');
        resolve(value);
      };
      body.querySelector('[data-reason-cancel]').addEventListener('click', ()=>finish(null), { once:true });
      body.querySelector('[data-reason-save]').addEventListener('click', ()=>{
        const reason = textarea.value.trim();
        if(reason.length < 4){
          error.textContent = 'Indica el motivo del cambio.';
          error.style.display = 'block';
          textarea.focus();
          return;
        }
        finish(reason);
      });
      modal.classList.add('is-open');
      window.setTimeout(()=>textarea.focus(), 30);
    });
  }

  async function openLeadHistory(leadId){
    const modal = ensureLeadModal();
    const title = modal.querySelector('#leadWorkflowTitle');
    const subtitle = modal.querySelector('#leadWorkflowSubtitle');
    const body = modal.querySelector('#leadWorkflowBody');
    title.textContent = 'Historial de cambios';
    subtitle.textContent = 'Bitácora comercial del lead.';
    body.innerHTML = '<p class="subtitle">Cargando historial...</p>';
    modal.classList.add('is-open');

    try{
      const res = await fetch(`lead_status_history.php?lead_id=${encodeURIComponent(leadId)}`, {
        headers:{ 'Accept':'application/json', 'X-Requested-With':'fetch' },
        cache:'no-store'
      });
      const data = await res.json().catch(()=>({ ok:false }));
      if(!res.ok || !data.ok) throw new Error(data.error || 'No se pudo cargar el historial.');
      const items = Array.isArray(data.items) ? data.items : [];
      body.innerHTML = items.length ? `
        <div class="history-list">
          ${items.map(item => `
            <article class="history-item">
              <strong>${escapeHtml(item.previous_label)} → ${escapeHtml(item.new_label)}</strong>
              <p>${escapeHtml(item.reason)}</p>
              <div class="history-meta">${escapeHtml(item.username || 'Sistema')} · ${escapeHtml(item.created_at || '')}</div>
            </article>
          `).join('')}
        </div>
      ` : '<p class="subtitle">Este lead aún no tiene cambios de status registrados.</p>';
    }catch(error){
      body.innerHTML = `<div class="form-alert alert-error" style="display:block">${escapeHtml(error.message || 'No se pudo cargar el historial.')}</div>`;
    }
  }

  function bindDashboardControls(){
    const filtersToggle = document.querySelector('[data-filters-toggle]');
    const filtersPanel = document.getElementById('leadFilters');
    if(filtersToggle && filtersPanel && !filtersToggle.dataset.bound){
      filtersToggle.dataset.bound = '1';
      filtersToggle.addEventListener('click', ()=>{
        const isOpen = filtersPanel.classList.toggle('is-open');
        filtersToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
      });
    }

    document.querySelectorAll('.filters-form select').forEach(select=>{
      if(select.dataset.bound) return;
      select.dataset.bound = '1';
      select.addEventListener('change', ()=>{
        const form = select.closest('form');
        if(!form) return;
        if(typeof form.requestSubmit === 'function') form.requestSubmit();
        else form.submit();
      });
    });

    document.querySelectorAll('.sales-status-select').forEach(select=>{
      if(select.dataset.bound) return;
      select.dataset.bound = '1';
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
        if(nextValue === previousValue) return;

        const previousLabel = input.querySelector(`option[value="${cssEscapeValue(previousValue)}"]`)?.textContent || previousValue;
        const nextLabel = input.options[input.selectedIndex]?.textContent || nextValue;
        const changeReason = await requestStatusChangeReason(previousLabel, nextLabel);
        if(changeReason === null){
          input.value = previousValue;
          return;
        }

        input.disabled = true;
        input.classList.add('is-saving');

        try{
          const fd = new FormData();
          fd.append('id', id);
          fd.append('sales_status', nextValue);
          fd.append('change_reason', changeReason);
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
          pollDashboard(true);
        }catch(_){
          input.value = previousValue;
          alert('Error de red al actualizar el status comercial.');
        }finally{
          input.disabled = false;
          input.classList.remove('is-saving');
        }
      });
    });

    document.querySelectorAll('[data-history-open]').forEach(button=>{
      if(button.dataset.bound) return;
      button.dataset.bound = '1';
      button.addEventListener('click', ()=>{
        const leadId = button.getAttribute('data-lead-id');
        if(leadId) openLeadHistory(leadId);
      });
    });

    document.querySelectorAll('.notes-input').forEach(input=>{
      if(input.dataset.bound) return;
      input.dataset.bound = '1';
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
      if(control.dataset.bound) return;
      control.dataset.bound = '1';
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
  }

  function dashboardShouldPauseUpdate(){
    if(document.hidden) return true;
    if(document.querySelector('.is-saving')) return true;
    const active = document.activeElement;
    if(!active) return false;
    const editableSelector = 'input, textarea, select, [contenteditable="true"]';
    return Boolean(active.closest?.('[data-funnel-wrap]') && active.matches?.(editableSelector));
  }

  function cssEscapeValue(value){
    if(window.CSS && typeof window.CSS.escape === 'function') return window.CSS.escape(value);
    return String(value).replace(/["\\]/g, '\\$&');
  }

  function captureFunnelPosition(funnel){
    const state = {
      windowX: window.scrollX,
      windowY: window.scrollY,
      columns: {},
      windowAnchor: null
    };
    if(!funnel) return state;

    funnel.querySelectorAll('.funnel-column[data-status]').forEach(column=>{
      const status = column.getAttribute('data-status') || '';
      const list = column.querySelector('.funnel-list');
      if(!status || !list) return;

      const isScrollableList = list.scrollHeight > list.clientHeight + 2;
      const viewportTop = isScrollableList ? list.getBoundingClientRect().top : 0;
      const viewportBottom = isScrollableList ? list.getBoundingClientRect().bottom : window.innerHeight;
      const columnState = {
        scrollTop: list.scrollTop,
        scrollLeft: list.scrollLeft,
        anchor: null
      };

      const cards = list.querySelectorAll('.funnel-card[data-conversation-id]');
      for(const card of cards){
        const rect = card.getBoundingClientRect();
        if(rect.bottom <= viewportTop || rect.top >= viewportBottom) continue;

        columnState.anchor = {
          conversationId: card.getAttribute('data-conversation-id') || '',
          offset: rect.top - viewportTop,
          mode: isScrollableList ? 'list' : 'window'
        };

        if(!isScrollableList && !state.windowAnchor){
          state.windowAnchor = columnState.anchor;
        }
        break;
      }

      state.columns[status] = columnState;
    });

    return state;
  }

  function restoreFunnelPosition(funnel, state){
    if(!funnel || !state) return;
    let restoredWindowAnchor = false;

    Object.entries(state.columns || {}).forEach(([status, columnState])=>{
      const column = funnel.querySelector(`.funnel-column[data-status="${cssEscapeValue(status)}"]`);
      const list = column?.querySelector('.funnel-list');
      if(!list) return;

      list.scrollTop = columnState.scrollTop || 0;
      list.scrollLeft = columnState.scrollLeft || 0;

      const anchor = columnState.anchor;
      if(!anchor?.conversationId) return;
      const card = list.querySelector(`.funnel-card[data-conversation-id="${cssEscapeValue(anchor.conversationId)}"]`);
      if(!card) return;

      if(anchor.mode === 'list'){
        const listTop = list.getBoundingClientRect().top;
        const currentOffset = card.getBoundingClientRect().top - listTop;
        list.scrollTop += currentOffset - anchor.offset;
        return;
      }

      if(!restoredWindowAnchor && state.windowAnchor?.conversationId === anchor.conversationId){
        const currentOffset = card.getBoundingClientRect().top;
        window.scrollBy(0, currentOffset - anchor.offset);
        restoredWindowAnchor = true;
      }
    });

    if(!restoredWindowAnchor){
      window.scrollTo(state.windowX || 0, state.windowY || 0);
    }
  }

  function updateFunnelInPlace(currentFunnel, nextFunnel){
    if(!currentFunnel || !nextFunnel) return false;
    const currentBoard = currentFunnel.querySelector('.funnel-board');
    const nextBoard = nextFunnel.querySelector('.funnel-board');
    if(!currentBoard || !nextBoard) return false;

    currentFunnel.className = nextFunnel.className;
    currentBoard.className = nextBoard.className;

    const currentColumns = new Map();
    currentBoard.querySelectorAll('.funnel-column[data-status]').forEach(column=>{
      currentColumns.set(column.getAttribute('data-status') || '', column);
    });

    nextBoard.querySelectorAll('.funnel-column[data-status]').forEach(nextColumn=>{
      const status = nextColumn.getAttribute('data-status') || '';
      const currentColumn = currentColumns.get(status);
      if(!currentColumn){
        currentBoard.appendChild(nextColumn.cloneNode(true));
        return;
      }

      currentColumn.className = nextColumn.className;
      const currentHeader = currentColumn.querySelector('.funnel-column-header');
      const nextHeader = nextColumn.querySelector('.funnel-column-header');
      if(currentHeader && nextHeader) currentHeader.replaceWith(nextHeader.cloneNode(true));

      const currentList = currentColumn.querySelector('.funnel-list');
      const nextList = nextColumn.querySelector('.funnel-list');
      if(currentList && nextList) currentList.innerHTML = nextList.innerHTML;
    });

    currentColumns.forEach((column, status)=>{
      if(!nextBoard.querySelector(`.funnel-column[data-status="${cssEscapeValue(status)}"]`)) column.remove();
    });

    currentFunnel.querySelectorAll(':scope > .funnel-limit-note, :scope > .funnel-pagination').forEach(el=>el.remove());
    nextFunnel.querySelectorAll(':scope > .funnel-limit-note, :scope > .funnel-pagination').forEach(el=>{
      currentFunnel.appendChild(el.cloneNode(true));
    });

    return true;
  }

  let dashboardPolling = false;
  async function pollDashboard(force){
    const root = document.querySelector('[data-dashboard-auto-update]');
    if(!root || dashboardPolling) return;
    if(!force && dashboardShouldPauseUpdate()) return;

    dashboardPolling = true;
    try{
      const url = new URL(window.location.href);
      url.searchParams.set('_dashboard_auto', Date.now().toString());
      const res = await fetch(url.toString(), {
        headers: { 'Accept': 'text/html', 'X-Requested-With': 'fetch' },
        cache: 'no-store'
      });
      if(!res.ok) return;
      const html = await res.text();
      const doc = new DOMParser().parseFromString(html, 'text/html');
      const nextSummary = doc.querySelector('[data-summary-grid]');
      const nextFunnel = doc.querySelector('[data-funnel-wrap]');
      const currentSummary = document.querySelector('[data-summary-grid]');
      const currentFunnel = document.querySelector('[data-funnel-wrap]');
      const funnelPosition = captureFunnelPosition(currentFunnel);
      if(nextSummary && currentSummary) currentSummary.replaceWith(nextSummary);
      if(nextFunnel && currentFunnel){
        if(!updateFunnelInPlace(currentFunnel, nextFunnel)){
          currentFunnel.replaceWith(nextFunnel);
        }
        window.requestAnimationFrame(()=>{
          restoreFunnelPosition(document.querySelector('[data-funnel-wrap]'), funnelPosition);
        });
      }
      bindDashboardControls();
    }catch(_){
      // El dashboard se intentará actualizar de nuevo en el siguiente ciclo.
    }finally{
      dashboardPolling = false;
    }
  }

  bindDashboardControls();

  if(document.querySelector('[data-dashboard-auto-update]')){
    window.setInterval(()=>pollDashboard(false), 5000);
  }

})();
