-- eliminar_leads_sin_canal_activo.sql
-- Limpia conversaciones/leads conversacionales que ya no tienen un canal activo.
-- Uso recomendado: ejecutar desde phpMyAdmin sobre la base de datos del CRM.
--
-- Que elimina:
-- 1. Conversaciones cuyo canal ya no existe o esta inactivo.
-- 2. Leads conversacionales que no tengan ninguna conversacion con canal activo.
-- 3. Mensajes, adjuntos, historial de status y logs relacionados.
-- 4. Contactos conversacionales que queden sin conversaciones.
--
-- Que conserva:
-- 1. Leads de formulario sin external_source y sin conversacion.
-- 2. Leads que tengan al menos una conversacion asociada a un canal activo.

START TRANSACTION;

DROP TEMPORARY TABLE IF EXISTS tmp_leads_without_active_channel;
DROP TEMPORARY TABLE IF EXISTS tmp_conversations_without_active_channel;
DROP TEMPORARY TABLE IF EXISTS tmp_contacts_to_review;

CREATE TEMPORARY TABLE tmp_leads_without_active_channel (
  id INT UNSIGNED NOT NULL,
  account_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (id, account_id)
) ENGINE=Memory;

CREATE TEMPORARY TABLE tmp_conversations_without_active_channel (
  id INT UNSIGNED NOT NULL,
  account_id INT UNSIGNED NOT NULL,
  contact_id INT UNSIGNED NULL,
  lead_id INT UNSIGNED NULL,
  PRIMARY KEY (id, account_id),
  KEY idx_contact_id (contact_id),
  KEY idx_lead_id (lead_id)
) ENGINE=Memory;

CREATE TEMPORARY TABLE tmp_contacts_to_review (
  id INT UNSIGNED NOT NULL,
  account_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (id, account_id)
) ENGINE=Memory;

-- Leads conversacionales que no tienen ninguna conversacion con canal activo.
INSERT IGNORE INTO tmp_leads_without_active_channel (id, account_id)
SELECT
  l.id,
  l.account_id
FROM leads l
LEFT JOIN conversations c
  ON c.lead_id = l.id
  AND c.account_id = l.account_id
LEFT JOIN instagram_channels ch
  ON ch.id = c.channel_id
  AND ch.account_id = c.account_id
  AND ch.is_active = 1
WHERE
  (
    COALESCE(l.external_source, '') <> ''
    OR c.id IS NOT NULL
  )
GROUP BY
  l.id,
  l.account_id
HAVING
  SUM(CASE WHEN ch.id IS NOT NULL THEN 1 ELSE 0 END) = 0;

-- Conversaciones sin canal activo, y todas las conversaciones de leads que seran eliminados.
INSERT IGNORE INTO tmp_conversations_without_active_channel (id, account_id, contact_id, lead_id)
SELECT
  c.id,
  c.account_id,
  c.contact_id,
  c.lead_id
FROM conversations c
LEFT JOIN instagram_channels ch
  ON ch.id = c.channel_id
  AND ch.account_id = c.account_id
  AND ch.is_active = 1
LEFT JOIN tmp_leads_without_active_channel tl
  ON tl.id = c.lead_id
  AND tl.account_id = c.account_id
WHERE
  tl.id IS NOT NULL
  OR ch.id IS NULL;

INSERT IGNORE INTO tmp_contacts_to_review (id, account_id)
SELECT DISTINCT
  contact_id,
  account_id
FROM tmp_conversations_without_active_channel
WHERE contact_id IS NOT NULL AND contact_id > 0;

-- Vista previa del alcance antes de ejecutar los DELETE dentro de esta transaccion.
SELECT 'leads_a_eliminar' AS elemento, COUNT(*) AS total FROM tmp_leads_without_active_channel
UNION ALL
SELECT 'conversaciones_a_eliminar', COUNT(*) FROM tmp_conversations_without_active_channel
UNION ALL
SELECT 'contactos_a_revisar', COUNT(*) FROM tmp_contacts_to_review;

DELETE ca
FROM conversation_attachments ca
JOIN tmp_conversations_without_active_channel tc
  ON tc.id = ca.conversation_id
  AND tc.account_id = ca.account_id;

DELETE cm
FROM conversation_messages cm
JOIN tmp_conversations_without_active_channel tc
  ON tc.id = cm.conversation_id
  AND tc.account_id = cm.account_id;

DELETE wl
FROM webhook_event_logs wl
JOIN tmp_conversations_without_active_channel tc
  ON tc.id = wl.conversation_id
  AND tc.account_id = wl.account_id;

DELETE wl
FROM webhook_event_logs wl
JOIN tmp_leads_without_active_channel tl
  ON tl.id = wl.lead_id
  AND tl.account_id = wl.account_id;

DELETE lsh
FROM lead_status_history lsh
JOIN tmp_leads_without_active_channel tl
  ON tl.id = lsh.lead_id
  AND tl.account_id = lsh.account_id;

DELETE c
FROM conversations c
JOIN tmp_conversations_without_active_channel tc
  ON tc.id = c.id
  AND tc.account_id = c.account_id;

DELETE l
FROM leads l
JOIN tmp_leads_without_active_channel tl
  ON tl.id = l.id
  AND tl.account_id = l.account_id;

DELETE cc
FROM conversation_contacts cc
JOIN tmp_contacts_to_review tr
  ON tr.id = cc.id
  AND tr.account_id = cc.account_id
LEFT JOIN conversations c
  ON c.contact_id = cc.id
  AND c.account_id = cc.account_id
WHERE c.id IS NULL;

COMMIT;

DROP TEMPORARY TABLE IF EXISTS tmp_contacts_to_review;
DROP TEMPORARY TABLE IF EXISTS tmp_conversations_without_active_channel;
DROP TEMPORARY TABLE IF EXISTS tmp_leads_without_active_channel;
