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
-- Mail counts for the Webmail box on the home page, one row per user.


CREATE TABLE llx_cyphtwebmail_mailstatus(
	rowid              integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity             integer DEFAULT 1 NOT NULL,
	fk_user            integer NOT NULL,
	nb_unread          integer DEFAULT 0 NOT NULL,
	nb_flagged         integer DEFAULT 0 NOT NULL,
	contact_unread     text,
	date_check         datetime NOT NULL,
	tms                timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
