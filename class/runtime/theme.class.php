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
 * \brief       Picks the webmail theme that matches the surrounding page,
 *              which the webmail is embedded in.
 */
class CyphtTheme
{
	const DARK = 'darkly';
	const LIGHT = 'default';

	/**
	 * The theme to load unconditionally. 1 means always dark; 0 and 2 both
	 * start light, because under 2 the dark half is layered on top only when
	 * the browser asks for it, exactly as the surrounding page does it.
	 *
	 * @param int $darkMode Value of THEME_DARKMODEENABLED
	 * @return string Theme name
	 */
	public static function forDarkMode($darkMode)
	{
		return ((int) $darkMode) === 1 ? self::DARK : self::LIGHT;
	}

	/**
	 * Whether the choice belongs to the browser rather than to the server.
	 *
	 * @param int $darkMode Value of THEME_DARKMODEENABLED
	 * @return bool
	 */
	public static function followsBrowser($darkMode)
	{
		return ((int) $darkMode) === 2;
	}
}
