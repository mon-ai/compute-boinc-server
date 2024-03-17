<?php
// This file is part of BOINC.
// http://boinc.berkeley.edu
// Copyright (C) 2015 University of California
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

// Page for downloading the BOINC client, with support for autoattach:
// https://github.com/BOINC/boinc/wiki/SimpleAttach
// Note: to use autoattach:
// 1) You need to have the client versions file
//      run html/ops/get_versions.php
// 2) Put your project ID (ask DPA if you don't have one)
//      in config.xml as <project_id>x</project_id>
//
// There's a logged-in user.
//
// Autoattach case: if project has an ID and client is Win or Mac:
//    - find latest version for that platform (regular and vbox)
//    - Create a login token.
//    - Show download button(s)
//      The download will be via concierge, using the login token.
// Otherwise:
//    - show link to download page on BOINC web site,
//      and instructions for what to do after that.
//
// VirtualBox:
// config.xml entries:
// <need_vbox/>     This project requires VBox
// <recommend_vbox> This project can use VBox
//
// Windows has combined BOINC/VBox installers.
// For other platforms, direct user to VBox download page
// before installing BOINC
//

// Can also be called as a web RPC;
// see https://github.com/BOINC/boinc/wiki/WebRpc#download
//  rpc              this says it's an RPC
//  user_agent       web browser info
//  authenticator    the account to link to
// returns an XML doc of the form
// <download_info>
//   [ <manual/> ]  // not win or mac - tell user to visit BOINC download page
//   <project_id>X</project_id>

require_once("../inc/util.inc");
require_once("../inc/account.inc");

define("VBOX_DOWNLOAD_URL", "https://www.virtualbox.org/wiki/Downloads");

// take the user agent string reported by web browser,
// and return best guess for platform
//
function get_platform($user_agent) {
    if (strstr($user_agent, 'Windows')) {
        if (strstr($user_agent, 'Win64')||strstr($user_agent, 'WOW64')) {
            return 'windows_x86_64';
        } else {
            return 'windows_intelx86';
        }
    } else if (strstr($user_agent, 'Mac')) {
        if (strstr($user_agent, 'PPC Mac OS X')) {
            return 'powerpc-apple-darwin';
        } else {
            return 'x86_64-apple-darwin';
        }
    } else if (strstr($user_agent, 'Android')) {
        // Check for Android before Linux,
        // since Android contains the Linux kernel and the
        // web browser user agent string lists Linux too.
        //
        return 'arm-android-linux-gnu';
    } else if (strstr($user_agent, 'Linux')) {
        if (strstr($user_agent, 'x86_64')) {
            return 'x86_64-pc-linux-gnu';
        } else {
            return 'i686-pc-linux-gnu';
        }
    } else {
        return null;
    }
}

function is_windows() {
    global $user_agent;
    if (strstr($user_agent, 'Windows')) {
        return true;
    }
    return false;
}

function is_windows_or_mac() {
    global $user_agent;
    if (strstr($user_agent, 'Windows')) return true;
    if (strstr($user_agent, 'Mac')) return true;
    return false;
}

// find release version for user's platform
//
function get_version($user_agent, $dev) {
    $v = simplexml_load_file("versions.xml");
    $p = get_platform($user_agent);
    foreach ($v->version as $i=>$v) {
        if ((string)$v->dbplatform != $p) {
            continue;
        }
        if (strstr((string)$v->description, "Recommended")) {
            return $v;
        }
        if ($dev) {
            if (strstr((string)$v->description, "Development")) {
                return $v;
            }
        }
    }
    return null;
}

function download_button($v, $project_id, $token, $user, $green) {
    return sprintf(
        '<form action="https://boinc.berkeley.edu/concierge.php" method="post">
        <input type=hidden name=project_id value="%d">
        <input type=hidden name=token value="%s">
        <input type=hidden name=user_id value="%d">
        <input type=hidden name=filename value="%s">
        <button class="btn %s">
        <font size=2><u>Download BOINC</u></font>
        <br>for %s (%s MB)
        <br><small>BOINC %s</small></button>
        </form>
        ',
        $project_id,
        $token,
        $user->id,
        (string)$v->filename,
        $green?"btn-success":"btn-info",
        (string)$v->platform,
        (string)$v->size_mb,
        (string)$v->version_num
    );
}

function download_button_vbox($v, $project_id, $token, $user) {
    // if no vbox version exists for platform, don't show vbox button
    if(!$v->vbox_filename) {
        return;
    }
    return sprintf(
        '<form action="https://boinc.berkeley.edu/concierge.php" method="post">
        <input type=hidden name=project_id value="%d">
        <input type=hidden name=token value="%s">
        <input type=hidden name=user_id value="%d">
        <input type=hidden name=filename value="%s">
        <button class="btn btn-success">
        <font size=+1><u>Download BOINC + VirtualBox</u></font>
        <br>for %s (%s MB)
        <br><small>BOINC %s, VirtualBox %s</small></a>
        </form>
        ',
        $project_id,
        $token,
        $user->id,
        (string)$v->vbox_filename,
        (string)$v->platform,
        (string)$v->vbox_size_mb,
        (string)$v->version_num,
        (string)$v->vbox_version
    );
}

function show_vbox_info($where) {
    global $need_vbox, $recommend_vbox;

    if ($need_vbox || $recommend_vbox) {
        echo "<p>";
        if ($need_vbox) {
            echo tra("This project requires VirtualBox.");
        } else if ($recommend_vbox) {
            echo tra("This project recommends VirtualBox.");
        }
        echo " ";
        switch ($where) {
        case "installed":
            echo tra(
                "If it is not installed on this computer, get it %1here%2, then restart BOINC.",
                "<a href=".VBOX_DOWNLOAD_URL.">",
                "</a>"
            );
            break;
        case "direct":
        case "main":
            if (is_windows()) {
                echo tra("Use the BOINC+VirtualBox installer.");
            } else {
                echo tra(
                    "If it is not installed on this computer, get it %1here%2.",
                    "<a href=".VBOX_DOWNLOAD_URL.">",
                    "</a>"
                );
            }
        }
        echo "<p>";
    }
}

// We can't use auto-attach; direct user to the BOINC download page
//
function direct_to_boinc() {
    global $master_url;
    page_head(tra("Download BOINC"));
    text_start();
    echo "<p>";
    echo tra("To download and install BOINC,
            click on the link below and follow the instructions.
    ");
    echo "<p>";
    show_button(
        "https://boinc.berkeley.edu/download.php",
        tra("Go to the BOINC download page."),
        null, null, 'target=_new'
    );
    show_vbox_info("direct");

    if (parse_bool(get_config(), 'account_manager')) {
        echo sprintf(
            "<p><p>%s<p>",
            tra("When BOINC first runs it will ask you to select a project.
                Cancel out of this dialog,
                then select <b>Tools / Use Account Manager</b>
                to connect BOINC to your %1 account.
                See <a href=%2>detailed instructions</a>.",
                PROJECT,
                'https://boinc.berkeley.edu/wiki/Account_managers'
            )
        );
    } else {
        echo sprintf(
            "<p><p>%s<p>",
            tra("When BOINC first runs it will ask you to select a project.
                Select '%1' from the list,
                or enter this project's URL:<p>%2",
                PROJECT,
                $master_url
            )
        );
    }
    text_end();
    page_tail();
}

function show_download_page($user, $user_agent, $dev) {
    global $need_vbox, $project_id;

    // If no project ID, we can't use simplified install
    //
    if (!$project_id || !is_windows_or_mac()) {
        direct_to_boinc();
        return;
    }
    $v = get_version($user_agent, $dev);

    // if we can't figure out the user's platform,
    // take them to the download page on the BOINC site
    //
    if (!$v) {
        direct_to_boinc();
        return;
    }

    page_head("Download software");

    $phrase = "";
    if ($need_vbox) {
        $dlv = tra("the current versions of BOINC and VirtualBox");
        $phrase = tra("these versions are");
        $dl = tra("BOINC and VirtualBox");
    } else {
        $dlv = tra("the current version of BOINC");
        $phrase = tra("this version is");
        $dl = "BOINC";
    }
    echo tra("To participate in %1, %2 must be installed on your computer.", PROJECT, $dlv);
    echo"
        <p>
    ";
    echo tra("If %1 already installed, %2click here%3.",
        $phrase,
        "<a href=download_software.php?action=installed>",
        "</a>"
    );
    echo "
        <p>
    ";

    show_vbox_info("main");

    $token = make_login_token($user);
    echo "<table border=0 cellpadding=20>\n";
    if ($v->vbox_filename) {
        table_row(
            "",
            download_button_vbox($v, $project_id, $token, $user),
            "&nbsp;&nbsp;",
            download_button($v, $project_id, $token, $user, false),
            ""
        );
    } else {
        table_row("", download_button($v, $project_id, $token, $user, true), "");
    }
    echo "</table>\n";
    echo "<p><p>";
    echo tra("When the download is finished, open the downloaded file to install %1.", $dl);
    echo "<p><p>";
    echo tra("All done? %1Click here to finish%2.", "<a href=welcome.php>", "</a>");
    page_tail();
}

// if user already has BOINC installed, tell them how to attach.
//
function installed() {
    global $config, $need_vbox, $recommend_vbox;
    $am = parse_bool($config, "account_manager");
    if ($am) {
        page_head(tra("Use %1", PROJECT));
        echo sprintf("%s
            <ul>
            <li> %s
            <li> %s
            <li> %s
            <li> %s
            </ul>
            ",
            tra("To use %1 on this computer:", PROJECT),
            tra("In the BOINC manager, go to the Tools menu"),
            tra("Select Use Account Manager"),
            tra("Select %1 from the list", PROJECT),
            tra("Enter your %1 email address and password.", PROJECT)
        );
    } else {
        page_head(tra("Add %1", PROJECT));
        show_vbox_info("installed");
        echo sprintf("%s
            <ul>
            <li> %s
            <li> %s
            <li> %s
            <li> %s
            </ul>
            ",
            tra("To add %1 on this computer:", PROJECT),
            tra("In the BOINC manager, go to the Tools menu"),
            tra("Select Add Project"),
            tra("Select %1 from the list", PROJECT),
            tra("Enter your %1 email address and password.", PROJECT)
        );
    }
    echo "<p><p>";
    echo sprintf('<a href=home.php class="btn btn-success">%s</a>
        ',
        tra('Continue to your home page')
    );
    page_tail();
}

// RPC handler
//
function handle_get_info() {
    require_once("../inc/xml.inc");
    global $config, $user;
    xml_header();
    $rpc_key = get_str('rpc_key');
    if ($rpc_key != parse_config($config, "<rpc_key>")) {
        xml_error(-1, "RPC key mismatch");
    }
    $user = BoincUser::lookup_auth(get_str('auth'));
    if (!$user) {
        xml_error(-1, "user not found");
    }
    $project_id = parse_config($config, '<project_id>');
    if (!$project_id) {
        xml_error(-1, "no project ID");
    }
    $user_agent = get_str('user_agent');
    $v = get_version($user_agent, false);
    if (!$v) {
        xml_error(-1, "no version for platform");
    }

    $want_vbox = parse_bool($config, 'need_vbox')
        || parse_bool($config, 'recommend_vbox')
    ;

    $token = make_login_token($user);
    echo sprintf(
'<download_info>
    <project_id>%s</project_id>
    <token>%s</token>
    <user_id>%d</user_id>
    <platform>%s</platform>
    <boinc>
        <filename>%s</filename>
        <size_mb>%s</size_mb>
        <boinc_version>%s</boinc_version>
    </boinc>
',
        $project_id,
        $token,
        $user->id,
        (string)$v->platform,
        (string)$v->filename,
        (string)$v->size_mb,
        (string)$v->version_num
    );
    if ($v->vbox_filename && $want_vbox) {
        echo sprintf(
'   <boinc_vbox>
        <filename>%s</filename>
        <size_mb>%s</size_mb>
        <boinc_version>%s</boinc_version>
        <vbox_version>%s</vbox_version>
    </boinc_vbox>
',
            (string)$v->vbox_filename,
            (string)$v->vbox_size_mb,
            (string)$v->version_num,
            (string)$v->vbox_version
        );
    }
    echo '</download_info>
';
}

// get config.xml items
//
$need_vbox = parse_bool($config, "need_vbox");
$recommend_vbox = parse_bool($config, "recommend_vbox");
$project_id = parse_config($config, "<project_id>");

$action = get_str("action", true);

if ($action == "installed") {
    installed();
} else if ($action == 'get_info') {
    handle_get_info();
} else {
    $dev = get_str("dev", true);
    $user_agent = get_str("user_agent", true);      // for debugging
    if (!$user_agent) {
        $user_agent = $_SERVER['HTTP_USER_AGENT'];
    }
    $user = get_logged_in_user();
    show_download_page($user, $user_agent, $dev);
}

?>
