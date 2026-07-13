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
  <title>CRM conversacional para Instagram y WhatsApp | <?= h($brandName) ?></title>
  <meta name="description" content="Centraliza conversaciones de Instagram, gestiona leads por embudo y prepara tu operación comercial para WhatsApp desde un CRM moderno." />
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
      <div class="nav-links" aria-label="Secciones">
        <a href="#producto">Producto</a>
        <a href="#flujo">Flujo</a>
        <a href="#beneficios">Beneficios</a>
        <a href="#planes">Planes</a>
      </div>
      <div class="nav-actions">
        <a class="btn ghost" href="login.php">Iniciar sesión</a>
        <a class="btn primary" href="#demo">Solicitar demo</a>
      </div>
    </nav>

    <section class="hero" id="producto" aria-labelledby="hero-title">
      <div class="hero-content">
        <p class="eyebrow"><span>Nuevo</span> CRM conversacional para equipos de venta</p>
        <h1 id="hero-title">Convierte mensajes en ventas organizadas.</h1>
        <p class="hero-copy">CRM Pixels une inbox, embudo comercial, seguimiento y archivos multimedia para que cada conversación avance con contexto, velocidad y control.</p>
        <div class="hero-actions">
          <a class="btn primary lift" href="login.php">Entrar al CRM</a>
          <a class="btn play" href="#flujo"><span aria-hidden="true">▶</span> Ver cómo funciona</a>
        </div>
        <div class="trust-row" aria-label="Canales compatibles">
          <span>Instagram DM</span>
          <span>Meta Ads</span>
          <span>WhatsApp próximamente</span>
        </div>
      </div>

      <div class="product-stage" aria-label="Vista previa del CRM Pixels">
        <div class="side-rail" aria-hidden="true">
          <span></span><span></span><span></span><span></span>
        </div>
        <div class="dashboard-preview">
          <div class="preview-topbar">
            <div>
              <strong>Inbox conversacional</strong>
              <small>Instagram · Activo</small>
            </div>
            <span class="live-pill">En vivo</span>
          </div>
          <div class="preview-grid">
            <aside class="preview-list">
              <article class="conversation active">
                <strong>Jeferson Herrera</strong>
                <span>Quiero cotizar una campaña</span>
              </article>
              <article class="conversation">
                <strong>Wynwood Park</strong>
                <span>Adjunto recibido: imagen</span>
              </article>
              <article class="conversation">
                <strong>Contacto nuevo</strong>
                <span>¿Tienen disponibilidad?</span>
              </article>
            </aside>
            <section class="preview-chat">
              <div class="bubble inbound">Hola, quiero información del servicio.</div>
              <div class="bubble outbound">¡Claro! Te comparto opciones y presupuesto.</div>
              <div class="bubble media">
                <span></span>
                <p>Imagen enviada</p>
              </div>
            </section>
          </div>
        </div>
        <aside class="assistant-card">
          <span>Embudo</span>
          <strong>Nuevo lead</strong>
          <div class="mini-progress"><i></i></div>
          <small>Seguimiento automático</small>
        </aside>
        <aside class="metric-card">
          <strong>24h</strong>
          <span>Control de ventana Meta</span>
        </aside>
      </div>
    </section>

    <section class="logo-strip" aria-label="Integraciones principales">
      <span>Instagram</span>
      <span>Meta Ads</span>
      <span>Cloudflare R2</span>
      <span>WhatsApp API</span>
      <span>Multi cuenta</span>
    </section>

    <section class="feature-section" id="beneficios">
      <div class="section-heading">
        <p class="section-kicker">Funciones</p>
        <h2>Todo lo necesario para vender desde conversaciones.</h2>
        <a class="btn ghost dark" href="login.php">Explorar CRM</a>
      </div>
      <div class="feature-grid">
        <article class="feature-card blue">
          <span class="feature-icon">✦</span>
          <h3>Inbox centralizado</h3>
          <p>Gestiona conversaciones, respuestas, imágenes y audios sin salir del CRM.</p>
        </article>
        <article class="feature-card amber">
          <span class="feature-icon">◎</span>
          <h3>Embudo comercial</h3>
          <p>Cada chat se convierte en oportunidad y avanza por status comerciales claros.</p>
        </article>
        <article class="feature-card green">
          <span class="feature-icon">↗</span>
          <h3>Seguimiento real</h3>
          <p>Notas, historial de cambios y alertas de ventana Meta para cuidar cada contacto.</p>
        </article>
        <article class="feature-card rose">
          <span class="feature-icon">▣</span>
          <h3>SaaS multi cuenta</h3>
          <p>Crea cuentas, canales y equipos separados para operar clientes distintos.</p>
        </article>
      </div>
    </section>

    <section class="workflow" id="flujo">
      <div class="workflow-visual" aria-hidden="true">
        <div class="phone-frame">
          <div class="phone-header"></div>
          <div class="phone-message left">Hola, vi el anuncio</div>
          <div class="phone-message right">Te atiendo por aquí</div>
          <div class="phone-chart">
            <span style="height:42%"></span>
            <span style="height:68%"></span>
            <span style="height:52%"></span>
            <span style="height:82%"></span>
          </div>
        </div>
      </div>
      <div class="workflow-copy">
        <p class="section-kicker">Operación comercial</p>
        <h2>Del DM al cierre, sin perder el hilo.</h2>
        <ul class="check-list">
          <li>Recibe mensajes desde campañas de Meta Ads.</li>
          <li>Convierte conversaciones en leads del embudo.</li>
          <li>Responde con texto, imágenes y audios desde el CRM.</li>
          <li>Prepara el mismo flujo para WhatsApp cuando lo activemos.</li>
        </ul>
        <a class="btn dark-solid" href="#demo">Quiero verlo funcionando</a>
      </div>
    </section>

    <section class="stats-band" aria-label="Indicadores del CRM">
      <div><strong>1</strong><span>Inbox por canal</span></div>
      <div><strong>24h</strong><span>Alertas de ventana Meta</span></div>
      <div><strong>5</strong><span>Imágenes por envío</span></div>
      <div><strong>∞</strong><span>Cuentas cliente</span></div>
    </section>

    <section class="plans" id="planes">
      <div class="section-heading compact">
        <p class="section-kicker">Escala</p>
        <h2>Diseñado para crecer como SaaS.</h2>
      </div>
      <article class="testimonial-card">
        <span>“</span>
        <p>Centraliza la operación comercial sin obligar al equipo a perseguir conversaciones en distintas apps.</p>
        <strong>CRM Pixels</strong>
      </article>
      <article class="plan-card highlighted">
        <small>Más popular</small>
        <h3>Conversacional</h3>
        <p>Para equipos que venden desde Instagram y necesitan control del embudo.</p>
        <ul>
          <li>Inbox Instagram</li>
          <li>Embudo por status</li>
          <li>Usuarios y roles</li>
        </ul>
        <a class="btn primary" href="login.php">Entrar al panel</a>
      </article>
      <article class="plan-card">
        <small>Próximo</small>
        <h3>Omnicanal</h3>
        <p>La siguiente fase para conectar WhatsApp y ampliar el flujo de atención.</p>
        <ul>
          <li>WhatsApp API</li>
          <li>Más automatizaciones</li>
          <li>Reportes por canal</li>
        </ul>
        <a class="btn ghost dark" href="#demo">Solicitar demo</a>
      </article>
    </section>

    <section class="final-cta" id="demo">
      <div>
        <h2>Listo para ordenar tus conversaciones comerciales.</h2>
        <p>Activa un CRM moderno para atender, clasificar y cerrar oportunidades desde Instagram, con WhatsApp en la ruta.</p>
      </div>
      <a class="btn light" href="mailto:pixelstudiove@gmail.com?subject=Demo%20CRM%20Pixels">Solicitar demo</a>
    </section>

    <footer class="landing-footer">
      <div>
        <a class="brand-mark" href="index.php" aria-label="<?= h($brandName) ?>">
          <?php if ($logoExists): ?><img src="<?= h($logoPath) ?>" alt="<?= h($brandName) ?>"><?php else: ?><span class="brand-symbol">P</span><?php endif; ?>
          <strong><?= h($brandName) ?></strong>
        </a>
        <p>CRM conversacional para equipos que venden desde redes sociales.</p>
      </div>
      <div class="footer-links">
        <a href="#producto">Producto</a>
        <a href="#beneficios">Beneficios</a>
        <a href="login.php">Acceso</a>
      </div>
    </footer>
  </main>
</body>
</html>
