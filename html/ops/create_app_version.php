<?php
// This file is part of BOINC.
// http://boinc.berkeley.edu
// Copyright (C) 2021 University of California
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

// web-based app version creation.

require_once("../inc/util_ops.inc");

function show_form() {
    admin_page_head("Create app version");
    echo "
        <form action=create_app_version.php method=post ENCTYPE=\"multipart/form-data\">"
    ;

    form_input_text("App name", "app_name");
    form_input_text("Version number", "version_num");
    form_input_text("Platform name", "platform_name");
    form_input_text("Plan class", "plan_class");
    form_input_hidden("action", "create");
    echo "
        <input size=80 style=\"background-color: white;\" type=file name=\"new_file[]\" multiple>
        <p>
        <input class=\"btn btn-success\" type=submit value=OK>
        </form>
    ";
    admin_page_tail();
}

function create_version() {
    $app_name = post_str("app_name");
    $version_num = post_str("version_num");
    $platform_name = post_str("platform_name");
    $plan_class = post_str("plan_class");

    $app_name = BoincDb::escape_string($app_name);
    $app = BoincApp::lookup("name='$app_name'");
    if (!$app) {
        admin_error_page("No such app");
    }
    $platform_name = BoincDb::escape_string($platform_name);
    $platform = BoincPlatform::lookup("name='$platform_name'");
    if (!$platform) {
        admin_error_page("No such platform");
    }

    // make directories as needed
    //
    $apps_dir = "../../apps";
    $app_dir = "$apps_dir/$app_name";
    if (!is_dir($app_dir)) {
        mkdir($app_dir);
    }
    $version_dir = "$app_dir/$version_num";
    if (!is_dir($version_dir)) {
        mkdir($version_dir);
    }
    $platform_dir = "$version_dir/$platform_name";
    if ($plan_class) {
        $platform_dir .= "__$plan_class";
    }
    if (is_dir($platform_dir)) {
        admin_error_page("App version dir already exists");
    }
    mkdir($platform_dir);

    // copy files to app version dir
    //
    $count = count($_FILES['new_file']['tmp_name']);
    for ($i=0; $i<$count; $i++) {
        $tmp_name = $_FILES['new_file']['tmp_name'][$i];
        if (!is_uploaded_file($tmp_name)) {
            admin_error_page("$tmp_name is not uploaded file");
        }
        $name = $_FILES['new_file']['name'][$i];
        if (strstr($name, "/")) {
            admin_error_page("no / allowed");
        }
        $ret = rename($tmp_name, "$platform_dir/$name");
        if (!$ret) {
            admin_error_page("can't rename $tmp_name to $platform_dir/$name");
        }
    }

    admin_page_head("Updating app versions");
    echo "<pre>\n";
    $cmd = "cd ../..; bin/update_versions --no_conf";
    system($cmd);
    echo "</pre>\n";
    admin_page_tail();
}

// creating app versions must be secure
//
auth_ops_privilege();   // user must be S_ADMIN or S_DEV
if (!parse_bool(get_config(), "enable_web_app_version_creation")) {
    admin_error_page("Disabled");
}


$action = post_str("action", true);
if ($action == 'create') {
    create_version();
} else {
    show_form();
}

?>
