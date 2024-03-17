#! /usr/bin/env php
<?php
// This file is part of BOINC.
// http://boinc.berkeley.edu
// Copyright (C) 2018 University of California
//
// BOINC is free software; you can redistribute it and/or modify it
// under the terms of the GNU Lesser General Public License
// as published by the Free Software Foundation,
// either version 3 of the License, or (at your option) any later version.
//
// BOINC is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
// See the GNU Lesser General Public License for more details.
//
// You should have received a copy of the GNU Lesser General Public License
// along with BOINC.  If not, see <http://www.gnu.org/licenses/>.

// get XML client version list from BOINC server

$x = file_put_contents(
    "../user/versions.xml",
    file_get_contents("https://boinc.berkeley.edu/download_all.php?xml=1&dev=1")
);

// The above may fail if PHP was not configured to use SSL.
// Try wget instead.
//
if (!$x) {
    echo "\nThat didn't work - using wget instead\n";

    $x = system("wget -O ../user/versions.xml https://boinc.berkeley.edu/download_all.php?xml=1&dev=1");

    if ($x === false) {
        echo "wget didn't work either\n";
    }
}

?>
