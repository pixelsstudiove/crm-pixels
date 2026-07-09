(function () {
  const form = document.querySelector('form.form');
  if (!form) return;

  const alertBox = document.getElementById('formAlert');
  const btn = document.getElementById('submitBtn');
  const phoneInput = form.querySelector('input[name="phone"]');
  const businessTypeSelect = form.querySelector('select[name="business_type"]');
  const businessTypeOtherField = document.getElementById('f-business_type_other');
  const businessTypeOtherInput = form.querySelector('input[name="business_type_other"]');
  if (!alertBox || !btn || !phoneInput) return;

  function normalizePlatform(value, referrer) {
    const raw = String(value || '').trim().toLowerCase();
    const ref = String(referrer || '').trim().toLowerCase();
    const source = raw || ref;
    if (/instagram|\big\b/.test(source)) return 'instagram';
    if (/facebook|\bfb\b|meta/.test(source)) return 'facebook';
    if (/google|gclid/.test(source)) return 'google';
    if (/tiktok/.test(source)) return 'tiktok';
    if (/whatsapp|wa\.me/.test(source)) return 'whatsapp';
    if (/youtube/.test(source)) return 'youtube';
    if (/linkedin/.test(source)) return 'linkedin';
    if (/email|mail/.test(source)) return 'email';
    if (!raw && !ref) return 'directo';
    return raw || 'referido';
  }

  function populateTrackingFields() {
    const params = new URLSearchParams(window.location.search);
    const trackingFields = ['utm_source','utm_medium','utm_campaign','utm_content','utm_term','ad_name','ad_id','gclid','fbclid'];
    trackingFields.forEach((field) => {
      const input = document.getElementById(field);
      if (input) input.value = params.get(field) || '';
    });

    const landingUrl = document.getElementById('landing_url');
    if (landingUrl) landingUrl.value = window.location.href;
    const referrer = document.getElementById('referrer');
    if (referrer) referrer.value = document.referrer || '';

    const sourcePlatform = document.getElementById('source_platform');
    if (sourcePlatform) {
      sourcePlatform.value = normalizePlatform(params.get('source_platform') || params.get('utm_source') || '', document.referrer || '');
    }
  }
  populateTrackingFields();

  function formatPhone(value) {
    const digits = String(value || '').replace(/\D/g, '').slice(0, 11);
    return digits.length > 4 ? digits.slice(0, 4) + '-' + digits.slice(4) : digits;
  }
  phoneInput.addEventListener('input', (e) => { e.target.value = formatPhone(e.target.value); });
  phoneInput.addEventListener('paste', (e) => {
    e.preventDefault();
    const text = (e.clipboardData || window.clipboardData).getData('text');
    phoneInput.value = formatPhone(text);
    phoneInput.dispatchEvent(new Event('input', { bubbles: true }));
  });

  const fields = {
    fullname: { el: form.querySelector('input[name="fullname"]'), wrap: document.getElementById('f-fullname'), err: form.querySelector('.err[data-for="fullname"]'), test: (v) => v.trim().length >= 3, touched: false },
    phone: { el: phoneInput, wrap: document.getElementById('f-phone'), err: form.querySelector('.err[data-for="phone"]'), test: (v) => /^[0-9]{4}-[0-9]{7}$/.test(v.trim()), touched: false },
    email: { el: form.querySelector('input[name="email"]'), wrap: document.getElementById('f-email'), err: form.querySelector('.err[data-for="email"]'), test: (v) => /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/i.test(v.trim()), touched: false },
    brand_instagram: { el: form.querySelector('input[name="brand_instagram"]'), wrap: document.getElementById('f-brand_instagram'), err: form.querySelector('.err[data-for="brand_instagram"]'), test: (v) => v.trim().length >= 2, touched: false },
    business_type: { el: form.querySelector('select[name="business_type"]'), wrap: document.getElementById('f-business_type'), err: form.querySelector('.err[data-for="business_type"]'), test: (v) => v.trim().length > 0, touched: false },
    business_type_other: { el: businessTypeOtherInput, wrap: businessTypeOtherField, err: form.querySelector('.err[data-for="business_type_other"]'), test: (v) => !businessTypeSelect || businessTypeSelect.value !== 'otro' || v.trim().length >= 2, touched: false },
    services_needed: { el: document.getElementById('f-services_needed'), wrap: document.getElementById('f-services_needed'), err: form.querySelector('.err[data-for="services_needed"]'), test: () => form.querySelectorAll('input[name="services_needed[]"]:checked').length > 0, touched: false },
    main_objective: { el: form.querySelector('select[name="main_objective"]'), wrap: document.getElementById('f-main_objective'), err: form.querySelector('.err[data-for="main_objective"]'), test: (v) => v.trim().length > 0, touched: false },
    message: { el: form.querySelector('textarea[name="message"]'), wrap: document.getElementById('f-message'), err: form.querySelector('.err[data-for="message"]'), test: (v) => v.trim().length <= 1200, touched: false },
  };

  function setError(key, hasError, message, forceShow = false) {
    const field = fields[key];
    if (!field || !field.wrap || !field.err) return;
    if (typeof message === 'string' && message) field.err.textContent = message;
    const shouldShow = hasError && (field.touched || forceShow);
    field.wrap.classList.toggle('invalid', shouldShow);
    field.err.style.display = shouldShow ? 'block' : 'none';
  }
  function validateField(key, forceShow = false) {
    const field = fields[key];
    if (!field || !field.test) return true;
    const value = field.el && 'value' in field.el ? field.el.value : '';
    const invalid = !field.test(value);
    setError(key, invalid, undefined, forceShow);
    return !invalid;
  }
  function validateAll(forceShow = false) {
    let valid = true;
    for (const key in fields) if (!validateField(key, forceShow)) valid = false;
    return valid;
  }
  function keyFromElement(element) {
    for (const key in fields) if (fields[key].el === element) return key;
    if (element && element.name === 'services_needed[]') return 'services_needed';
    return null;
  }


  function toggleBusinessOther() {
    if (!businessTypeSelect || !businessTypeOtherField || !businessTypeOtherInput) return;
    const shouldShow = businessTypeSelect.value === 'otro';
    businessTypeOtherField.hidden = !shouldShow;
    businessTypeOtherInput.required = shouldShow;
    if (!shouldShow) {
      businessTypeOtherInput.value = '';
      fields.business_type_other.touched = false;
      setError('business_type_other', false);
    }
  }

  if (businessTypeSelect) {
    businessTypeSelect.addEventListener('change', () => {
      toggleBusinessOther();
      if (fields.business_type_other) validateField('business_type_other');
    });
    toggleBusinessOther();
  }

  Object.values(fields).forEach((field) => {
    if (!field.el) return;
    const eventTarget = field.el;
    ['input','change'].forEach((eventName) => {
      eventTarget.addEventListener(eventName, () => {
        field.touched = true;
        const key = keyFromElement(eventTarget);
        if (key) validateField(key);
      });
    });
  });
  form.querySelectorAll('input[name="services_needed[]"]').forEach((checkbox) => {
    checkbox.addEventListener('change', () => {
      fields.services_needed.touched = true;
      validateField('services_needed');
    });
  });

  function showAlert(message, type) {
    alertBox.innerHTML = message;
    alertBox.className = 'form-alert ' + (type === 'success' ? 'alert-success' : 'alert-error');
    alertBox.style.display = 'block';
  }

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (!validateAll(true)) {
      showAlert('Por favor corrige los campos marcados.', 'error');
      return;
    }
    btn.disabled = true;
    alertBox.style.display = 'none';
    alertBox.className = 'form-alert';

    try {
      const response = await fetch(form.getAttribute('action') || 'save_lead.php', { method: 'POST', body: new FormData(form) });
      let data = {};
      try { data = await response.json(); } catch (_) {}
      if (response.status >= 500) {
        showAlert('No pudimos registrar tu solicitud en este momento. Intenta nuevamente en unos minutos.', 'error');
        btn.disabled = false;
        return;
      }
      if (response.status === 409 || response.status === 422 || (data && data.ok === false && data.errors)) {
        if (data.errors && typeof data.errors === 'object') {
          for (const key in data.errors) {
            if (fields[key]) { fields[key].touched = true; setError(key, true, data.errors[key], true); }
          }
        }
        showAlert(response.status === 409 ? 'Ya existe un registro con ese número telefónico.' : 'Por favor corrige los campos marcados.', 'error');
        btn.disabled = false;
        return;
      }
      if (!response.ok || (data && data.ok === false)) {
        showAlert('Ocurrió un problema al registrar. Revisa la conexión con el servidor.', 'error');
        btn.disabled = false;
        return;
      }
      showAlert(form.dataset.successMessage || 'Registro satisfactorio.', 'success');
      form.reset();
      toggleBusinessOther();
      populateTrackingFields();
    } catch (err) {
      showAlert('No se pudo conectar con el servidor. Intenta nuevamente.', 'error');
    } finally {
      btn.disabled = false;
    }
  });
})();
