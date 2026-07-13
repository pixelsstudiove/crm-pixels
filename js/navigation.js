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

  document.addEventListener('click', (event)=>{
    const trigger = closestFromEventTarget(event.target, '[data-menu-trigger]');
    if(trigger){
      event.preventDefault();
      const menu = trigger.closest('[data-menu]');
      const shouldOpen = menu && !menu.classList.contains('is-open');
      closeMenus(menu);
      setMenuOpen(menu, Boolean(shouldOpen));
      return;
    }

    if(!closestFromEventTarget(event.target, '[data-menu]')) closeMenus();
  });

  document.addEventListener('keydown', (event)=>{
    if(event.key === 'Escape') closeMenus();
  });
})();
