/* Copyright (C) 2026  Camile   <camilevahviraki@gmail.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published
 * by the Free Software Foundation; either version 2.1 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * Makes the Cypht iframe fill the window below the Dolibarr header.
 */
(function () {
	'use strict';

	var FRAME_ID = 'cyphtwebmail-frame';
	var MIN_HEIGHT = 400;

	// Padding, borders and margins of the page under the iframe.
	function spaceBelow(frame) {
		var total = 0;

		for (var el = frame.parentElement; el && el !== document.documentElement; el = el.parentElement) {
			var style = window.getComputedStyle(el);
			total += parseFloat(style.paddingBottom) + parseFloat(style.borderBottomWidth) + parseFloat(style.marginBottom);
		}

		return total;
	}

	function fit(frame) {
		var top = frame.getBoundingClientRect().top + window.pageYOffset;
		var height = Math.floor(window.innerHeight - top - spaceBelow(frame));

		frame.style.height = Math.max(MIN_HEIGHT, height) + 'px';
	}

	function start() {
		var frame = document.getElementById(FRAME_ID);
		if (!frame) {
			return;
		}

		function refit() {
			fit(frame);
		}

		refit();
		window.addEventListener('resize', refit);
		window.addEventListener('load', refit);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', start);
	} else {
		start();
	}
})();
