-- Copyright (C) 2026  Camile   <camilevahviraki@gmail.com>
--
-- This program is free software: you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation, either version 3 of the License, or
-- (at your option) any later version.
--
-- This program is distributed in the hope that it will be useful,
-- but WITHOUT ANY WARRANTY; without even the implied warranty of
-- MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
-- GNU General Public License for more details.
--
-- You should have received a copy of the GNU General Public License
-- along with this program.  If not, see https://www.gnu.org/licenses/.
--
-- Migration 001: the module configuration table.
--
-- Duplicates sql/llx_cyphtwebmail_config.sql on purpose. That file is only
-- read when the module is activated, and an upgrade that merely replaced the
-- files never activates anything, so an existing installation would otherwise
-- have new code looking for a table nobody created.
--
-- The upgrade runner reads its own version out of this table, and a failed
-- read returns the default of 0, so the ordering works: the read fails, the
-- migration creates the table, and the version is written into it.
--
-- Safe to re-run. run_sql() is called with the default error handling, which
-- lets "table already exists" pass.

CREATE TABLE llx_cyphtwebmail_config(
	rowid              integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity             integer DEFAULT 1 NOT NULL,
	name               varchar(64) NOT NULL,
	value              text,
	date_creation      datetime NOT NULL,
	tms                timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;

ALTER TABLE llx_cyphtwebmail_config ADD UNIQUE INDEX uk_cyphtwebmail_config_name (entity, name);
