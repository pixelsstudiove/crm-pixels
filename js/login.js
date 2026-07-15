(function(){
  const form = document.querySelector('form.form');
  if(!form) return;
  const alertBox = document.getElementById('formAlert');
  const passwordToggle = form.querySelector('[data-toggle-password]');
  const passwordInput = form.querySelector('#login-password');

  if(passwordToggle && passwordInput){
    passwordToggle.addEventListener('click', ()=>{
      const isVisible = passwordInput.type === 'text';
      passwordInput.type = isVisible ? 'password' : 'text';
      passwordToggle.textContent = isVisible ? 'Mostrar' : 'Ocultar';
      passwordToggle.setAttribute('aria-pressed', isVisible ? 'false' : 'true');
      passwordInput.focus();
    });
  }

  const fields = {
    username: {
      el: form.querySelector('input[name="username"]'),
      wrap: document.getElementById('f-username'),
      err: form.querySelector('.err[data-for="username"]'),
      test: v => v.trim().length >= 3,
      touched: false
    },
    password: {
      el: form.querySelector('input[name="password"]'),
      wrap: document.getElementById('f-password'),
      err: form.querySelector('.err[data-for="password"]'),
      test: v => v.trim().length >= 3,
      touched: false
    }
  };

  function setError(key, bad){
    const f = fields[key];
    if(!f || !f.wrap || !f.err) return;
    f.wrap.classList.toggle('invalid', bad && f.touched);
    f.err.style.display = bad && f.touched ? 'block' : 'none';
    if (bad && f.touched) f.err.textContent = 'Campo obligatorio (mín. 3 caracteres).';
  }

  function validate(key){
    const f = fields[key];
    if(!f || !f.el) return true;
    const bad = !f.test(f.el.value);
    setError(key, bad);
    return !bad;
  }

  Object.keys(fields).forEach(k => {
    const f = fields[k];
    if(!f.el) return;
    f.el.addEventListener('input', () => { f.touched = true; validate(k); });
    f.el.addEventListener('change', () => { f.touched = true; validate(k); });
  });

  form.addEventListener('submit', (e)=>{
    let ok = true;
    for(const k in fields){
      fields[k].touched = true;
      if(!validate(k)) ok = false;
    }
    if(!ok){
      e.preventDefault();
      if(alertBox){
        alertBox.textContent = 'Por favor completa los campos.';
        alertBox.className = 'form-alert alert-error';
        alertBox.style.display = 'block';
      }
    }
  });
})();
