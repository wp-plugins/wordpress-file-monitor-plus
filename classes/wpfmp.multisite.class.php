<?php
/*  Copyright 2012  Scott Cariss  (email : scott@l3rady.com)

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

// Not a WordPress context? Stop.
! defined( 'ABSPATH' ) and exit;

// Only load class if it hasn't already been loaded
if ( ! class_exists( 'sc_WordPressFileMonitorPlusMultiSite' ) )
{
    class sc_WordPressFileMonitorPlusMultiSite
    {
        static public function init()
        {
            // To do in future...
            // Make settings move to network admin. In current WordPress land it proves to be difficult
            // with the way I have things setup. I will come back to this later.
        }
    }
}
?>