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
-- Duplicates sql/llx_cyphtwebmail_config.sql on purpose: that file is only
-- read on activation, and a files only upgrade never activates.
--
-- Ordering works because CyphtConfig::get() returns its default when the
-- table is missing: the read fails, this creates it, the version goes in.
-- Re-runnable; run_sql() lets "already exists" pass.

CREATE TABLE llx_cyphtwebmail_config(
	rowid              integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity             integer DEFAULT 1 NOT NULL,
	name               varchar(64) NOT NULL,
	value              text,
	date_creation      datetime NOT NULL,
	tms                timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;

ALTER TABLE llx_cyphtwebmail_config ADD UNIQUE INDEX uk_cyphtwebmail_config_name (entity, name);
