-- vaciar_registros.sql
-- Resetea el CRM casi como recién instalado.
--
-- Elimina:
-- - Todas las cuentas cliente creadas.
-- - Todos los usuarios que no sean super_admin.
-- - Canales de Instagram conectados.
-- - Conversaciones, contactos, mensajes y adjuntos.
-- - Logs/eventos del webhook.
-- - Leads y su historial de cambios de status.
--
-- Conserva:
-- - Usuarios con rol super_admin.
-- - Una cuenta base vacia para que el super_admin pueda iniciar sesion.
--
-- Ejecutar desde phpMyAdmin sobre la base de datos del CRM.
-- IMPORTANTE: Esta accion no elimina archivos ya subidos a R2.

SET FOREIGN_KEY_CHECKS = 0;

TRUNCATE TABLE `lead_status_history`;
TRUNCATE TABLE `conversation_attachments`;
TRUNCATE TABLE `conversation_messages`;
TRUNCATE TABLE `webhook_event_logs`;
TRUNCATE TABLE `conversations`;
TRUNCATE TABLE `conversation_contacts`;
TRUNCATE TABLE `instagram_channels`;
TRUNCATE TABLE `leads`;

DELETE FROM `users`
WHERE `role` <> 'super_admin';

DELETE FROM `accounts`;

INSERT INTO `accounts` (`id`, `name`, `slug`, `status`)
VALUES (1, 'Pixels Studio', 'pixels-studio', 'active');

UPDATE `users`
SET `account_id` = 1
WHERE `role` = 'super_admin';

ALTER TABLE `accounts` AUTO_INCREMENT = 2;
ALTER TABLE `leads` AUTO_INCREMENT = 1;
ALTER TABLE `instagram_channels` AUTO_INCREMENT = 1;
ALTER TABLE `conversation_contacts` AUTO_INCREMENT = 1;
ALTER TABLE `conversations` AUTO_INCREMENT = 1;
ALTER TABLE `conversation_messages` AUTO_INCREMENT = 1;
ALTER TABLE `conversation_attachments` AUTO_INCREMENT = 1;
ALTER TABLE `webhook_event_logs` AUTO_INCREMENT = 1;
ALTER TABLE `lead_status_history` AUTO_INCREMENT = 1;

SET FOREIGN_KEY_CHECKS = 1;
