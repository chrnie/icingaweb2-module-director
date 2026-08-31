ALTER TABLE icinga_command_var
  ADD COLUMN entry_deltas TEXT DEFAULT NULL AFTER format;

ALTER TABLE icinga_host_var
  ADD COLUMN entry_deltas TEXT DEFAULT NULL AFTER format;

ALTER TABLE icinga_notification_var
  ADD COLUMN entry_deltas TEXT DEFAULT NULL AFTER format;

ALTER TABLE icinga_service_set_var
  ADD COLUMN entry_deltas TEXT DEFAULT NULL AFTER format;

ALTER TABLE icinga_service_var
  ADD COLUMN entry_deltas TEXT DEFAULT NULL AFTER format;

ALTER TABLE icinga_user_var
  ADD COLUMN entry_deltas TEXT DEFAULT NULL AFTER format;

ALTER TABLE branched_icinga_host
  ADD COLUMN var_deltas TEXT DEFAULT NULL;

ALTER TABLE branched_icinga_service
  ADD COLUMN var_deltas TEXT DEFAULT NULL;

ALTER TABLE branched_icinga_user
  ADD COLUMN var_deltas TEXT DEFAULT NULL;

ALTER TABLE branched_icinga_notification
  ADD COLUMN var_deltas TEXT DEFAULT NULL;

ALTER TABLE sync_property
  ADD COLUMN var_operator ENUM('=', '+=', '-=') DEFAULT NULL;

INSERT INTO director_schema_migration
  (schema_version, migration_time)
  VALUES (193, NOW());
