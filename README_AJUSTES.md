# Formulario de diagnóstico comercial – Pixels Studio

Proyecto de captación de leads para **Pixels Studio**. Incluye formulario público reducido, dashboard privado, tracking de campañas/anuncios, embudo comercial e instalador de base de datos.

## 1. Formulario público

Campos visibles:

- Nombre y apellido.
- Número de WhatsApp con máscara venezolana `0000-0000000`.
- Correo electrónico.
- Instagram de la marca o negocio.
- Tipo de negocio.
- Si el tipo de negocio es **Otro**, aparece un campo adicional para indicar el área.
- Servicios que necesita.
- Objetivo principal.
- Mensaje adicional opcional.

Campos ocultos de tracking:

- `source_platform`
- `utm_source`
- `utm_medium`
- `utm_campaign`
- `utm_content`
- `utm_term`
- `ad_name`
- `ad_id`
- `gclid`
- `fbclid`
- `landing_url`
- `referrer`

## 2. Configuración de base de datos

Edita:

```php
config/db.php
```

Configura:

```php
$DB_HOST = 'localhost';
$DB_PORT = '3306';
$DB_NAME = 'NOMBRE_DE_TU_BASE';
$DB_USER = 'USUARIO_DE_TU_BASE';
$DB_PASS = 'CLAVE_DE_TU_BASE';
```

También puedes definir esos valores como variables de entorno:

```env
DB_HOST=
DB_PORT=
DB_NAME=
DB_USER=
DB_PASS=
```

## 3. Crear tablas automáticamente

El ZIP incluye:

```text
instalar_base_datos.php
crear_tablas.sql
actualizar_embudo_ventas.sql
```

Para instalar desde el navegador:

```text
https://tudominio.com/ruta-del-formulario/instalar_base_datos.php?key=pixels-install-2026
```

Haz clic en **Crear / actualizar base de datos**.

Después de ejecutarlo correctamente, elimina del servidor:

```text
instalar_base_datos.php
```

La clave del instalador se cambia en:

```php
config/app.php
```

Busca:

```php
'install_key' => (string) env_value('INSTALL_KEY', 'pixels-install-2026'),
```

## 4. Usuario administrador inicial

Por defecto:

```text
Usuario: admin
Clave: CambiaEstaClave#2026
```

El usuario se crea automáticamente al entrar por primera vez en:

```text
login.php
```

Solo se crea si la tabla `users` está vacía y si está activo:

```php
'allow_default_admin_seed' => true,
```

Después de iniciar sesión, cambia la contraseña desde el dashboard y luego coloca:

```php
'allow_default_admin_seed' => false,
```

## 5. Dashboard

El dashboard permite ver:

- Datos del lead.
- Instagram de la marca o negocio.
- Tipo de negocio.
- Servicio solicitado.
- Objetivo.
- Plataforma.
- Campaña.
- Anuncio.
- Status comercial editable.
- Anotaciones internas por lead.
- Botón directo a WhatsApp.

Estados del embudo comercial:

- Nuevo lead.
- Contactado.
- Diagnóstico agendado.
- Propuesta enviada.
- En negociación.
- Cliente ganado.
- Cliente perdido.
- No responde.
- No califica.

## 6. Campos eliminados en esta versión reducida

Para reducir fricción y evitar perder leads, esta versión ya no solicita:

- Ciudad / país.
- Situación actual de la marca.
- Inversión mensual estimada.
- Fecha estimada para comenzar.
- Nombre de la marca o negocio.

El instalador y el backend hacen que esos campos antiguos sean opcionales si ya existían en una base de datos previa, para que no bloqueen nuevos registros.

## 7. Ejemplos de URLs con tracking

URL base del formulario:

```text
https://tudominio.com/pixels/
```

Instagram Ads:

```text
https://tudominio.com/pixels/?utm_source=instagram&utm_medium=paid_social&utm_campaign=pixels_diagnostico&utm_content=anuncio_01&ad_name=video_diagnostico_marcas_01
```

Facebook Ads:

```text
https://tudominio.com/pixels/?utm_source=facebook&utm_medium=paid_social&utm_campaign=pixels_diagnostico&utm_content=anuncio_02&ad_name=carrusel_servicios_02
```

Google Ads:

```text
https://tudominio.com/pixels/?utm_source=google&utm_medium=cpc&utm_campaign=pixels_agencia_marketing&utm_content=busqueda_01&ad_name=google_busqueda_agencia
```

Meta Ads con valores dinámicos:

```text
https://tudominio.com/pixels/?utm_source={{site_source_name}}&utm_medium=paid_social&utm_campaign={{campaign.name}}&utm_content={{ad.name}}&utm_term={{adset.name}}&ad_name={{ad.name}}&ad_id={{ad.id}}
```

En Meta Ads también puedes colocar la URL base en el campo **URL del sitio web** y los parámetros en **Parámetros de URL**:

```text
utm_source={{site_source_name}}&utm_medium=paid_social&utm_campaign={{campaign.name}}&utm_content={{ad.name}}&utm_term={{adset.name}}&ad_name={{ad.name}}&ad_id={{ad.id}}
```

## 8. Logo

Sube el logo a:

```text
images/logo.png
```

Si no existe, el formulario mostrará un espacio reservado.

## 9. WhatsApp automático

Por defecto está desactivado. Para activarlo con Evolution API, configura estas variables de entorno o ajusta `config/app.php`:

```env
EVO_ENABLED=true
EVO_BASE=https://tu-servidor-evolution.com
EVO_INSTANCE=tu-instancia
EVO_APIKEY=tu-api-key
```

## 10. Archivos importantes

```text
index.php                  Formulario público
save_lead.php              Guarda leads y tracking
dashboard.php              Dashboard privado
update_sales_status.php    Actualiza status comercial
update_lead_notes.php      Actualiza anotaciones internas
login.php                  Login administrativo
config/app.php             Configuración general
config/db.php              Base de datos
crear_tablas.sql           Estructura completa
instalar_base_datos.php    Instalador automático temporal
```

## Filtros del dashboard

La vista `dashboard.php` incluye filtros para segmentar leads por:

- Objetivo principal
- Servicio solicitado
- Status comercial
- Tipo de negocio
- Plataforma de origen
- Campaña
- Anuncio

Los filtros se combinan con el buscador general y se mantienen durante la paginación. Para quitar todos los filtros, usa el botón **Limpiar** del panel de filtros.
