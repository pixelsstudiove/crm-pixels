-- actualizar_embudo_ventas.sql
-- Agrega o ajusta campos nuevos del dashboard comercial de Pixels Studio.
-- Úsalo solo en instalaciones existentes donde la tabla `leads` ya fue creada.

ALTER TABLE `leads`
  ADD COLUMN `sales_status` VARCHAR(50) NOT NULL DEFAULT 'nuevo_lead';

ALTER TABLE `leads`
  ADD COLUMN `notes` TEXT NULL;

ALTER TABLE `leads`
  ADD COLUMN `business_type_other` VARCHAR(120) NULL;

ALTER TABLE `leads`
  ADD KEY `idx_sales_status` (`sales_status`);
