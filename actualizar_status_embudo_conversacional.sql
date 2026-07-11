-- actualizar_status_embudo_conversacional.sql
-- Migra estados comerciales antiguos a la estructura conversacional actual.
-- Ejecutar solo en instalaciones existentes.

UPDATE `leads`
SET `sales_status` = 'interesado'
WHERE `sales_status` = 'diagnostico_agendado';

UPDATE `leads`
SET `sales_status` = 'en_seguimiento'
WHERE `sales_status` = 'en_negociacion';

UPDATE `leads`
SET `sales_status` = 'cliente_perdido'
WHERE `sales_status` = 'no_califica';
