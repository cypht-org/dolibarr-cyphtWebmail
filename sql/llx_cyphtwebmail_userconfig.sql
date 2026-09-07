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
-- Cypht settings and mail accounts, one row per user.


CREATE TABLE llx_cyphtwebmail_userconfig(
	rowid              integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity             integer DEFAULT 1 NOT NULL,
	fk_user            integer NOT NULL,
	config             mediumtext,
	date_creation      datetime NOT NULL,
	tms                timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
