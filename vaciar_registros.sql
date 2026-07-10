-- vaciar_registros.sql
-- Limpia los registros operativos del CRM para empezar a recibir leads desde cero.
--
-- Conserva:
-- - Usuarios y roles del sistema.
-- - Canales de Instagram conectados, para no tener que volver a autorizar el fanpage.
--
-- Ejecutar desde phpMyAdmin sobre la base de datos del CRM.

SET FOREIGN_KEY_CHECKS = 0;

TRUNCATE TABLE `conversation_messages`;
TRUNCATE TABLE `conversations`;
TRUNCATE TABLE `conversation_contacts`;
TRUNCATE TABLE `leads`;

UPDATE `instagram_channels`
SET `last_event_at` = NULL;

SET FOREIGN_KEY_CHECKS = 1;

-- Opcional: si quieres desconectar tambien los canales de Instagram, ejecuta aparte:
-- TRUNCATE TABLE `instagram_channels`;
