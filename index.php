<?php
// index.php
declare(strict_types=1);
require_once __DIR__ . '/config/app.php';

$businessTypes = (array) app_config('lead_fields.business_type.options', []);
$services = (array) app_config('lead_fields.services_needed.options', []);
$objectives = (array) app_config('lead_fields.main_objective.options', []);
$logoPath = ltrim((string) app_config('brand.logo_path', 'images/logo.png'), '/');
$logoFile = __DIR__ . '/' . $logoPath;
$logoExists = $logoPath !== '' && is_file($logoFile);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover" />
  <title><?= h(app_config('ui.page_title', 'Formulario')) ?></title>
  <link rel="stylesheet" href="css/app.css">
</head>
<body>
  <main class="shell">
    <section class="form-card" aria-labelledby="form-title">
      <div class="panel">
        <header class="form-header">
          <div class="brand-logo-slot" aria-label="Espacio para el logo de la marca">
            <?php if ($logoExists): ?>
              <img src="<?= h($logoPath) ?>" alt="<?= h(app_config('brand.logo_alt', app_config('brand.name', 'Logo de la marca'))) ?>" class="brand-logo" />
            <?php else: ?>
              <span class="brand-logo-placeholder">Logo de Pixels Studio</span>
            <?php endif; ?>
          </div>

          <h1 class="title" id="form-title"><?= h(app_config('ui.form_heading', 'Diagnóstico gratuito')) ?></h1>
          <p class="subtitle"><?= h(app_config('ui.form_subtitle', 'Completa tus datos.')) ?></p>
        </header>

        <form class="form" action="save_lead.php" method="post" autocomplete="on" novalidate data-success-message="<?= h(app_config('ui.form_success', 'Registro satisfactorio.')) ?>">
          <!-- Campos ocultos de seguimiento. Se llenan automáticamente desde los parámetros de la URL. -->
          <input type="hidden" name="source_platform" id="source_platform" value="">
          <input type="hidden" name="utm_source" id="utm_source" value="">
          <input type="hidden" name="utm_medium" id="utm_medium" value="">
          <input type="hidden" name="utm_campaign" id="utm_campaign" value="">
          <input type="hidden" name="utm_content" id="utm_content" value="">
          <input type="hidden" name="utm_term" id="utm_term" value="">
          <input type="hidden" name="ad_name" id="ad_name" value="">
          <input type="hidden" name="ad_id" id="ad_id" value="">
          <input type="hidden" name="gclid" id="gclid" value="">
          <input type="hidden" name="fbclid" id="fbclid" value="">
          <input type="hidden" name="landing_url" id="landing_url" value="">
          <input type="hidden" name="referrer" id="referrer" value="">
          <input type="text" name="website_url" value="" tabindex="-1" autocomplete="off" class="hp-field" aria-hidden="true">

          <div class="grid">
            <label class="field" id="f-fullname" aria-label="Nombre y apellido">
              <span class="field-label">Nombre y apellido <em>*</em></span>
              <input type="text" name="fullname" placeholder="Escribe tu nombre y apellido" maxlength="120" required autocomplete="name" />
              <small class="err" data-for="fullname">El nombre y apellido es obligatorio (mín. 3 caracteres).</small>
            </label>

            <label class="field" id="f-phone" aria-label="Número de WhatsApp">
              <span class="field-label">Número de WhatsApp <em>*</em></span>
              <input type="tel" name="phone" placeholder="0000-0000000" inputmode="numeric" maxlength="12" pattern="[0-9]{4}-[0-9]{7}" title="Formato: 4 dígitos, guion, 7 dígitos (ej. 0412-1234567)" required autocomplete="tel" />
              <small class="err" data-for="phone">Teléfono inválido. Usa el formato 0000-0000000.</small>
            </label>

            <label class="field" id="f-email" aria-label="Correo electrónico">
              <span class="field-label">Correo electrónico <em>*</em></span>
              <input type="email" name="email" placeholder="correo@ejemplo.com" maxlength="150" required autocomplete="email" />
              <small class="err" data-for="email">Ingresa un correo electrónico válido.</small>
            </label>

            <label class="field" id="f-brand_instagram" aria-label="Instagram de la marca o negocio">
              <span class="field-label">Instagram de la marca o negocio <em>*</em></span>
              <input type="text" name="brand_instagram" placeholder="@tumarca" maxlength="120" required />
              <small class="err" data-for="brand_instagram">Indica el Instagram o escribe “no tiene”.</small>
            </label>

            <label class="field select-field" id="f-business_type" aria-label="<?= h(app_config('lead_fields.business_type.label', 'Tipo de negocio')) ?>">
              <span class="field-label"><?= h(app_config('lead_fields.business_type.label', 'Tipo de negocio')) ?> <em>*</em></span>
              <select name="business_type" required>
                <option value=""><?= h(app_config('lead_fields.business_type.placeholder', 'Selecciona una opción')) ?></option>
                <?php foreach ($businessTypes as $value => $label): ?>
                  <option value="<?= h($value) ?>"><?= h($label) ?></option>
                <?php endforeach; ?>
              </select>
              <small class="err" data-for="business_type">Selecciona el tipo de negocio.</small>
            </label>

            <label class="field" id="f-business_type_other" aria-label="Otra área de negocio" hidden>
              <span class="field-label">¿Cuál es tu área de negocio? <em>*</em></span>
              <input type="text" name="business_type_other" placeholder="Ej: clínica veterinaria, gimnasio, distribuidora..." maxlength="120" />
              <small class="err" data-for="business_type_other">Indica cuál es tu área de negocio.</small>
            </label>

            <div class="field check-field" id="f-services_needed" aria-label="<?= h(app_config('lead_fields.services_needed.label', 'Servicios')) ?>">
              <span class="field-label"><?= h(app_config('lead_fields.services_needed.label', 'Servicios')) ?> <em>*</em></span>
              <div class="check-grid">
                <?php foreach ($services as $value => $label): ?>
                  <label class="check-option">
                    <input type="checkbox" name="services_needed[]" value="<?= h($value) ?>">
                    <span><?= h($label) ?></span>
                  </label>
                <?php endforeach; ?>
              </div>
              <small class="err" data-for="services_needed">Selecciona al menos un servicio.</small>
            </div>

            <label class="field select-field" id="f-main_objective" aria-label="<?= h(app_config('lead_fields.main_objective.label', 'Objetivo principal')) ?>">
              <span class="field-label"><?= h(app_config('lead_fields.main_objective.label', 'Objetivo principal')) ?> <em>*</em></span>
              <select name="main_objective" required>
                <option value=""><?= h(app_config('lead_fields.main_objective.placeholder', 'Selecciona una opción')) ?></option>
                <?php foreach ($objectives as $value => $label): ?>
                  <option value="<?= h($value) ?>"><?= h($label) ?></option>
                <?php endforeach; ?>
              </select>
              <small class="err" data-for="main_objective">Selecciona el objetivo principal.</small>
            </label>

            <label class="field" id="f-message" aria-label="Mensaje adicional">
              <span class="field-label">Cuéntanos brevemente qué necesitas <small>(opcional)</small></span>
              <textarea name="message" maxlength="1200" placeholder="Ej: necesito mejorar mis redes, generar más ventas y crear contenido profesional para mi marca."></textarea>
              <small class="err" data-for="message">El mensaje no debe superar 1200 caracteres.</small>
            </label>
          </div>

          <p class="required-note"><em>*</em> Campos obligatorios.</p>
          <div id="formAlert" class="form-alert" aria-live="polite"></div>
          <button class="btn" id="submitBtn" type="submit"><?= h(app_config('ui.form_button', 'Enviar')) ?></button>
        </form>
      </div>
    </section>

    <div class="credit">Desarrollado por <strong><?= h(app_config('brand.developer', 'Pixels Studio')) ?></strong></div>
  </main>

  <script src="js/app.js" defer></script>
</body>
</html>
