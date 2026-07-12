# Despliegue SaaS en servidor

Checklist para aplicar esta version en `chat.pixelstudiove.com`.

## 1. Actualizar codigo

En cPanel, usa **Git Version Control > Pull or Deploy** sobre la rama `features-instagram-leads`.

Si lo haces por terminal dentro del hosting:

```bash
git pull origin features-instagram-leads
```

## 2. Ejecutar instalador

Abre el instalador con la clave configurada en `config/local.php` o `config/app.php`:

```text
https://chat.pixelstudiove.com/instalar_base_datos.php?key=TU_CLAVE_DE_INSTALACION
```

No publiques esa clave ni la dejes en capturas.

## 3. Revisar cuentas y usuarios

Entra como super admin y valida:

- `accounts.php`: cuentas creadas y estado correcto.
- `users.php`: cada usuario asignado a su cuenta.
- `channels.php`: cada canal conectado a la cuenta correcta.

Las cuentas suspendidas bloquean el acceso de sus usuarios no super admin.

## 4. Revisar vistas operativas

Como super admin:

- En el embudo puedes filtrar por cuenta.
- En inbox puedes filtrar por cuenta, canal, busqueda y estado.
- En eventos puedes filtrar logs por cuenta y reparar historial solo de esa cuenta.

Como admin o vendedor:

- Solo deben ver conversaciones, leads, canales y eventos de su propia cuenta.

## 5. Cierre

Despues del despliegue, cierra sesion y vuelve a entrar para renovar permisos de sesion.
