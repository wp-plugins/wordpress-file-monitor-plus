/*  Copyright 2011  Scott Cariss  (email : scott@l3rady.com)

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

jQuery(function ($) {
	$('select[name="cron_method"]').change(function() {
		if($('select[name="cron_method"]').val() == "wordpress") {
			$('#sc_wpfmp_cron_other').hide();
			$('#sc_wpfmp_cron_wordpress').show();
		} else {
			$('#sc_wpfmp_cron_wordpress').hide();
			$('#sc_wpfmp_cron_other').show();
		}
	}).trigger("change");
	$('select[name="notify_by_email"]').change(function() {
		if($('select[name="notify_by_email"]').val() == 1) {
			$('#sc_wpfmp_from_address, #sc_wpfmp_notify_address').show();
		} else {
			$('#sc_wpfmp_from_address, #sc_wpfmp_notify_address').hide();
		}
	}).trigger("change");
});