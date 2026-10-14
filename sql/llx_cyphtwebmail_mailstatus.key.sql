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


ALTER TABLE llx_cyphtwebmail_mailstatus ADD UNIQUE INDEX uk_cyphtwebmail_mailstatus (fk_user);
ALTER TABLE llx_cyphtwebmail_mailstatus ADD CONSTRAINT fk_cyphtwebmail_mailstatus_fk_user FOREIGN KEY (fk_user) REFERENCES llx_user (rowid) ON DELETE CASCADE;
