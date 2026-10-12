<?php
/* Copyright (C) 2026  Camile   <camilevahviraki@gmail.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file        class/runtime/theme.class.php
 * \ingroup     cyphtWebmail
 * \brief       Picks the Cypht theme that matches Dolibarr's dark mode setting.
 */
class CyphtTheme
{
	const DARK = 'darkly';
	const LIGHT = 'default';

	/**
	 * Base theme. 1 is always dark. 0 and 2 start light; with 2 the dark
	 * stylesheet is added when the browser prefers dark mode.
	 *
	 * @param int $darkMode Value of THEME_DARKMODEENABLED
	 * @return string Theme name
	 */
	public static function forDarkMode($darkMode)
	{
		return ((int) $darkMode) === 1 ? self::DARK : self::LIGHT;
	}

	/**
	 * Whether dark mode follows the browser setting.
	 *
	 * @param int $darkMode Value of THEME_DARKMODEENABLED
	 * @return bool
	 */
	public static function followsBrowser($darkMode)
	{
		return ((int) $darkMode) === 2;
	}
}
