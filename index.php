<?php
// index.php
declare(strict_types=1);
require_once __DIR__ . '/config/app.php';

$brandName = (string) app_config('brand.name', 'Pixels Studio');
$logoPath = ltrim((string) app_config('brand.logo_path', 'images/logo.png'), '/');
$logoFile = __DIR__ . '/' . $logoPath;
$logoExists = $logoPath !== '' && is_file($logoFile);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <title>CRM Pixels - Gestión de clientes de manera profesional</title>
  <meta name="description" content="Atiende Instagram DM, organiza conversaciones en un embudo comercial y prepara tu operación para WhatsApp desde CRM Pixels." />
  <link rel="stylesheet" href="css/landing.css?v=<?= (int) @filemtime(__DIR__ . '/css/landing.css') ?>">
</head>
<body class="landing-page">
  <main class="landing-shell">
    <nav class="landing-nav" aria-label="Navegación principal">
      <a class="brand-mark" href="index.php" aria-label="<?= h($brandName) ?>">
        <?php if ($logoExists): ?>
          <img src="<?= h($logoPath) ?>" alt="<?= h(app_config('brand.logo_alt', $brandName)) ?>">
        <?php else: ?>
          <span class="brand-symbol">P</span>
        <?php endif; ?>
        <strong><?= h($brandName) ?></strong>
      </a>
      <div class="nav-actions">
        <a class="btn ghost" href="login.php">Iniciar sesión</a>
        <a class="btn primary" href="#demo">Solicitar una demo</a>
      </div>
    </nav>

    <section class="hero" id="producto" aria-labelledby="hero-title">
      <div class="hero-content">
        <p class="eyebrow"><span>CRM conversacional</span> Instagram hoy, WhatsApp en la ruta</p>
        <h1 id="hero-title">Convierte conversaciones de <span class="channel-highlight">Instagram</span> y <span class="channel-highlight">WhatsApp</span> en <span class="sales-highlight">ventas</span></h1>
        <p class="hero-copy">CRM Pixels organiza cada DM como una oportunidad: responde desde el inbox, mueve el contacto por el embudo y conserva notas, audios, imágenes e historial comercial en una sola pantalla.</p>
        <div class="hero-actions">
          <a class="btn primary lift" href="#demo">Agendar demo</a>
          <a class="btn play" href="login.php"><span aria-hidden="true">↗</span> Entrar al CRM</a>
        </div>
        <div class="trust-row" aria-label="Canales compatibles">
          <span>Instagram Direct</span>
          <span>Meta Ads</span>
          <span>WhatsApp API en ruta</span>
        </div>
      </div>

      <div class="product-stage" aria-label="Vista previa del CRM Pixels">
        <div class="signal-board" aria-hidden="true">
          <span class="signal-node node-ig">Nuevo DM</span>
          <span class="signal-node node-wa">Multimedia</span>
          <span class="signal-node node-sales">Venta</span>
          <span class="signal-line line-one"></span>
          <span class="signal-line line-two"></span>
          <span class="signal-line line-three"></span>
        </div>
        <div class="dashboard-preview">
          <div class="preview-topbar">
            <div>
              <strong>Inbox de ventas</strong>
              <small>Instagram · Conversación abierta</small>
            </div>
            <span class="live-pill">Activo</span>
          </div>
          <div class="preview-grid">
            <aside class="preview-list">
              <article class="conversation active">
                <strong>Cliente desde anuncio</strong>
                <span>Quiero saber precios</span>
              </article>
              <article class="conversation">
                <strong>Lead calificado</strong>
                <span>Audio recibido</span>
              </article>
              <article class="conversation">
                <strong>Seguimiento</strong>
                <span>Propuesta enviada</span>
              </article>
            </aside>
            <section class="preview-chat">
              <div class="bubble inbound">Hola, vengo del anuncio.</div>
              <div class="bubble outbound">Perfecto, te atiendo desde aquí y guardo el avance.</div>
              <div class="bubble media">
                <span></span>
                <p>Imagen y audio listos</p>
              </div>
            </section>
          </div>
        </div>
        <aside class="assistant-card">
          <span>Status comercial</span>
          <strong>En conversación</strong>
          <div class="mini-progress"><i></i></div>
          <small>Historial guardado</small>
        </aside>
        <aside class="metric-card">
          <strong>24h</strong>
          <span>Ventana Meta visible</span>
        </aside>
      </div>
    </section>

    <section class="logo-strip" id="canales" aria-label="Clientes activos">
      <div class="logo-marquee" aria-hidden="true">
        <div class="logo-track">
          <span>Pixels Studio</span>
          <span>FerreFerreira</span>
          <span>Wynwood Park</span>
          <span>Multichat Pixels</span>
          <span>Tiendas Maiso</span>
          <span>CRM Pixels</span>
          <span>Pixels Studio</span>
          <span>FerreFerreira</span>
          <span>Wynwood Park</span>
          <span>Multichat Pixels</span>
          <span>Tiendas Maiso</span>
          <span>CRM Pixels</span>
        </div>
      </div>
    </section>

    <section class="feature-section" id="beneficios">
      <div class="section-heading">
        <p class="section-kicker">Lo que resuelve</p>
        <h2>Una operación comercial conectada al inbox.</h2>
        <a class="btn ghost dark" href="login.php">Ver plataforma</a>
      </div>
      <div class="feature-grid">
        <article class="feature-card blue">
          <span class="feature-icon">✦</span>
          <h3>Responder sin cambiar de app</h3>
          <p>El equipo atiende mensajes, imágenes y audios desde un inbox creado para ventas.</p>
        </article>
        <article class="feature-card amber">
          <span class="feature-icon">◎</span>
          <h3>Embudo nacido del chat</h3>
          <p>Cada conversación entra al pipeline con status comercial, notas y responsable.</p>
        </article>
        <article class="feature-card green">
          <span class="feature-icon">↗</span>
          <h3>Menos contactos perdidos</h3>
          <p>Alertas de ventana Meta y última actividad ayudan a responder antes de que venza el chat.</p>
        </article>
        <article class="feature-card rose">
          <span class="feature-icon">▣</span>
          <h3>Cuentas separadas</h3>
          <p>Administra clientes, canales y vendedores sin mezclar bandejas ni permisos.</p>
        </article>
      </div>
    </section>

    <section class="workflow" id="flujo">
      <div class="workflow-visual" aria-hidden="true">
        <div class="phone-frame">
          <div class="phone-header"></div>
          <div class="phone-message left">Hola, ¿me das precio?</div>
          <div class="phone-message right">Sí, ya abrí tu ficha.</div>
          <div class="phone-chart">
            <span style="height:42%"></span>
            <span style="height:68%"></span>
            <span style="height:52%"></span>
            <span style="height:82%"></span>
          </div>
        </div>
      </div>
      <div class="workflow-copy">
        <p class="section-kicker">Proceso real</p>
        <h2>Del primer mensaje al próximo seguimiento.</h2>
        <ul class="check-list">
          <li>Conecta una cuenta de Instagram profesional y recibe los DMs en el CRM.</li>
          <li>Clasifica la intención comercial sin salir de la conversación.</li>
          <li>Envía texto, imágenes y audios mientras el historial se actualiza solo.</li>
          <li>Escala el mismo modelo para WhatsApp cuando el canal esté activo.</li>
        </ul>
        <a class="btn dark-solid" href="#demo">Quiero revisar el flujo</a>
      </div>
    </section>

    <section class="stats-band" aria-label="Indicadores del CRM">
      <div><strong>DM</strong><span>Leads desde Instagram</span></div>
      <div><strong>24h</strong><span>Control de ventana Meta</span></div>
      <div><strong>5</strong><span>Fotos por mensaje</span></div>
      <div><strong>R2</strong><span>Archivos servidos desde Cloudflare</span></div>
    </section>

    <section class="plans" id="saas">
      <div class="section-heading compact">
        <p class="section-kicker">Casos de uso</p>
        <h2>Construido para vender y para operar cuentas cliente.</h2>
      </div>
      <article class="testimonial-card">
        <span>“</span>
        <p>La prioridad no es acumular mensajes: es saber quién escribió, qué necesita, quién lo atiende y cuál es el próximo paso comercial.</p>
        <strong>Filosofía del producto</strong>
      </article>
      <article class="plan-card highlighted">
        <small>Disponible</small>
        <h3>Instagram Sales Inbox</h3>
        <p>Para empresas que reciben conversaciones desde campañas, historias o perfil.</p>
        <ul>
          <li>DMs convertidos en leads</li>
          <li>Historial comercial por contacto</li>
          <li>Respuestas con multimedia</li>
        </ul>
        <a class="btn primary" href="login.php">Entrar al panel</a>
      </article>
      <article class="plan-card">
        <small>En ruta</small>
        <h3>WhatsApp conectado</h3>
        <p>La siguiente fase para que el CRM atienda el canal donde se cierran muchas ventas.</p>
        <ul>
          <li>Un inbox por canal</li>
          <li>Embudo compartido</li>
          <li>Roles por cuenta cliente</li>
        </ul>
        <a class="btn ghost dark" href="#demo">Solicitar demo</a>
      </article>
    </section>

    <section class="demo-section" id="demo" aria-labelledby="demo-title">
      <div class="demo-copy">
        <p class="section-kicker">Agendar demo</p>
        <h2 id="demo-title">Veamos cómo CRM Pixels encaja en tu operación comercial.</h2>
        <p>Déjanos tus datos y revisamos contigo cómo conectar tus conversaciones, vendedores y embudo en una sola plataforma.</p>
      </div>
      <form class="demo-form" action="mailto:pixelstudiove@gmail.com" method="post" enctype="text/plain">
        <label>
          <span>Nombre y apellido</span>
          <input type="text" name="nombre" autocomplete="name" placeholder="Ej. Jeferson Herrera" required>
        </label>
        <label>
          <span>Teléfono Venezuela</span>
          <input id="demo-phone" type="tel" name="telefono" inputmode="numeric" autocomplete="tel" placeholder="+58 412-1234567" maxlength="16" required>
        </label>
        <label>
          <span>Correo electrónico</span>
          <input type="email" name="correo" autocomplete="email" placeholder="correo@empresa.com" required>
        </label>
        <label>
          <span>Área de negocio</span>
          <select name="area_negocio" required>
            <option value="">Selecciona una opción</option>
            <option value="ecommerce">E-commerce</option>
            <option value="servicios-profesionales">Servicios profesionales</option>
            <option value="educacion">Educación o formación</option>
            <option value="inmobiliaria">Inmobiliaria</option>
            <option value="salud-bienestar">Salud y bienestar</option>
            <option value="retail-restaurantes">Retail o restaurantes</option>
            <option value="agencia-marketing">Agencia o marketing</option>
            <option value="otro">Otro</option>
          </select>
        </label>
        <button class="btn primary" type="submit">Solicitar demo</button>
      </form>
    </section>

    <footer class="landing-footer">
      <div>
        <a class="brand-mark" href="index.php" aria-label="<?= h($brandName) ?>">
          <?php if ($logoExists): ?><img src="<?= h($logoPath) ?>" alt="<?= h($brandName) ?>"><?php else: ?><span class="brand-symbol">P</span><?php endif; ?>
          <strong><?= h($brandName) ?></strong>
        </a>
        <p>CRM conversacional para equipos que venden desde Instagram y preparan su operación para WhatsApp.</p>
      </div>
      <div class="footer-links">
        <a href="#demo">Demo</a>
        <a href="login.php">Acceso</a>
      </div>
    </footer>
  </main>
  <script>
    (() => {
      const phone = document.getElementById('demo-phone');
      if (!phone) return;

      phone.addEventListener('input', () => {
        let digits = phone.value.replace(/\D/g, '');
        if (digits.startsWith('58')) digits = digits.slice(2);
        digits = digits.slice(0, 10);

        const carrier = digits.slice(0, 3);
        const first = digits.slice(3, 6);
        const second = digits.slice(6, 10);
        let value = '+58';
        if (carrier) value += ` ${carrier}`;
        if (first) value += `-${first}`;
        if (second) value += second;
        phone.value = value;
      });
    })();
  </script>
</body>
</html>
