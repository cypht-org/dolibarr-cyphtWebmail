-- Copyright (C) 2026  Camile   <camilevahviraki@gmail.com>
--
-- This program is free software: you can redistribute it and/or modify
-- it under the terms of the GNU Lesser General Public License as published
-- by the Free Software Foundation, either version 2.1 of the License, or
-- (at your option) any later version.
--
-- This program is distributed in the hope that it will be useful,
-- but WITHOUT ANY WARRANTY; without even the implied warranty of
-- MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
-- GNU Lesser General Public License for more details.
--
-- You should have received a copy of the GNU Lesser General Public License
-- along with this program.  If not, see https://www.gnu.org/licenses/.
--
-- Migration 004: the module number moved from 500000 to 207680

UPDATE llx_user_rights SET fk_id = 20768001 WHERE fk_id = 50000001 AND NOT EXISTS (SELECT 1 FROM llx_rights_def WHERE id = 50000001 AND module <> 'cyphtwebmail');
UPDATE llx_user_rights SET fk_id = 20768002 WHERE fk_id = 50000002 AND NOT EXISTS (SELECT 1 FROM llx_rights_def WHERE id = 50000002 AND module <> 'cyphtwebmail');
UPDATE llx_usergroup_rights SET fk_id = 20768001 WHERE fk_id = 50000001 AND NOT EXISTS (SELECT 1 FROM llx_rights_def WHERE id = 50000001 AND module <> 'cyphtwebmail');
UPDATE llx_usergroup_rights SET fk_id = 20768002 WHERE fk_id = 50000002 AND NOT EXISTS (SELECT 1 FROM llx_rights_def WHERE id = 50000002 AND module <> 'cyphtwebmail');
DELETE FROM llx_rights_def WHERE id IN (50000001, 50000002) AND module = 'cyphtwebmail';
