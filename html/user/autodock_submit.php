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

// job submission for Autodock
//
// The input files of a batch are
// - a zip file of per-job input files
// - a text file of job descriptors (input file, cmdline)
// Use sandbox for these input files
//
// stages:
// submit form
//      names of input files
//      "prepare" button
// prepare
//      check for existence of input files
//      create batch
//      create batches/userid/batchid dir
//      unzip job input files
//      check validity
//      estimate completion time?
//      show error messages or "submit" button
// submit
//      stage input files (phys name bf_batchid_name)
//      create jobs

require_once("../inc/util.inc");
require_once("../inc/submit_db.inc");
require_once("../inc/sandbox.inc");

function show_submit_form($user) {
    page_head("Submit Autodock jobs");
    echo "
        <p>
        See <a href=autodock_submit.php?action=info>instructions on submitting Autodock jobs</a>.
        <p>
        Prior to submitting a batch, you must upload
        a Zip file of job input files,
        and a text file of job descriptors, into your
        <a href=sandbox.php>sandbox</a>.
        <p>
    ";
    form_start("autodock_submit.php");
    form_input_hidden("action", "prepare");
    //echo sandbox_file_select($user, "input_zip_filename");
    form_input_text("Input files zip file", "input_zip_filename");
    form_input_text("Job description file", "job_desc_filename");
    form_submit("Prepare batch");
    form_end();
    page_tail();
}

function prepare_batch($user, $app) {
    // check for existence of input files
    //
    $input_zip_filename = get_str("input_zip_filename");
    $input_zip_path = sandbox_physical_path_name($user, $input_zip_filename);
    if (!$input_zip_path) {
        error_page("File $input_zip_filename is not in your sandbox");
    }
    $job_desc_filename = get_str("job_desc_filename");
    $job_desc_path = sandbox_physical_path_name($user, $job_desc_filename);
    if (!$job_desc_path) {
        error_page("File $job_desc_filename is not in your sandbox");
    }

    // create batch
    //
    $now = time();
    $batch_name = sprintf("autodock %s", time_str($now));
    $batch_id = BoincBatch::insert(
        sprintf(
            "(user_id, create_time, name, app_id, state) values (%d, %f, '%s', %d, %d)",
            $user->id, $now, $batch_name, $app->id, BATCH_STATE_INIT
        )
    );

    // create batch dir; copy input files there and unzip
    //
    if (!is_dir("batches/$user->id")) {
        mkdir("batches/$user->id");
    }
    $batch_dir = sprintf("batches/%d/%d", $user->id, $batch_id);
    mkdir($batch_dir);
    copy($input_zip_path, "$batch_dir/$input_zip_filename");
    system("unzip $batch_dir/$input_zip_filename -d $batch_dir > /dev/null 2>&1");
    // error check input files??

    // copy job descs file to batch dir; name it "job_descs"
    //
    copy($job_desc_path, "$batch_dir/job_descs");

    // validate input files.
    // Make a list of the job input files that are referenced in job descs.
    // A file could be referenced in multiple descs, or not at all
    //
    $lines = file("$batch_dir/job_descs");
    $files = fopen("$batch_dir/files", "w");
    $njobs = 0;
    foreach ($lines as $line) {
        $x = explode(",", trim($line));
        if (count($x) < 1) continue;
        fwrite($files, $x[0]."\n");
        $njobs++;
    }
    fclose($files);
    system("sort $batch_dir/files | uniq > $batch_dir/files_uniq");

    $errors = "";
    $lines = file("$batch_dir/files_uniq");
    foreach ($lines as $line) {
        $fname = trim($line);
        $path = "$batch_dir/$fname";
        if (!file_exists($path)) {
            $errors .= sprintf("<p>Missing input file %s\n", $fname);
            continue;
        }
        $z = file_get_contents($path);
        $ret = @json_decode($z);
        if (!$ret) {
            $errors .= sprintf("<p>JSON parse error in %s:<pre>%s</pre>\n", $fname, $z);
            continue;
        }
        // Autodock-specific checks?
    }
    if ($errors) {
        error_page($errors);
    }

    // update batch with # of jobs
    //
    $batch = BoincBatch::lookup_id($batch_id);
    $batch->update("njobs=$njobs");

    page_head("Batch prepared");
    echo "
        The input files for your Autodock batch are valid.
        Your batch has $njobs jobs.
        <p>
    ";
    form_start("autodock_submit.php");
    form_input_hidden("action", "submit");
    form_input_hidden("batch_id", $batch->id);
    form_submit("Submit batch");
    form_end();
    page_tail();
}

$fanout = parse_config($config, "<uldl_dir_fanout>");
$download_dir = parse_config($config, "<download_dir>");

function stage_file($batch_dir, $name, $batch) {
    global $fanout, $download_dir;
    $phys_name = sprintf("bf_%d_%s", $batch->id, $name);
    $path = dir_hier_path($phys_name, $download_dir, $fanout);
    if (!copy("$batch_dir/$name", $path)) {
        error_page("Can't copy $batch_dir/$name to $path");
    }
}

function submit_batch($user, $app, $batch_id) {
    page_head("Autodock batch submitted");
    $batch = BoincBatch::lookup_id($batch_id);
    if (!$batch) {
        error_page("no such batch");
    }
    if ($batch->user_id != $user->id) {
        error_page("not your batch");
    }
    if ($batch->state != BATCH_STATE_INIT) {
        error_page("already submitted");
    }

    // stage input files.
    // Name them bf_batchid_name
    //
    $batch_dir = sprintf("batches/%d/%d", $user->id, $batch_id);
    $lines = file("$batch_dir/files_uniq");
    foreach ($lines as $line) {
        $name = trim($line);
        stage_file($batch_dir, $name, $batch);
    }

    // create jobs
    //
    $jobs = "";
    $lines = file("$batch_dir/job_descs");
    foreach ($lines as $line) {
        $x = explode(",", trim($line));
        if (count($x) < 1) continue;
        if (count($x) >= 2) {
            $jobs .= sprintf(' --command_line "%s"', $x[1]);
        }
        $jobs .= sprintf("bf_%d_%s", $batch_id, $x[0]);
        $jobs .= "\n";
    }
    $errfile = "/tmp/create_work_" . getmypid() . ".err";
    $cmd = sprintf("cd %s; ./bin/create_work --appname %s --batch %d",
        project_dir(), $app->name, $batch_id
    );
    $cmd .= " --stdin >$errfile 2>&1";
    $h = popen($cmd, "w");
    if ($h === false) {
        xml_error(-1, "can't run create_work");
    }
    fwrite($h, $jobs);
    $ret = pclose($h);
    if ($ret) {
        $err = file_get_contents($errfile);
        unlink($errfile);
        error_page("create_work failed: $err");
    }
    unlink($errfile);

    $batch->update(sprintf("state=%d", BATCH_STATE_IN_PROGRESS));

    echo "
        <p>
        Your Autodock batch (ID $batch_id) has been submitted.
        <p>
        <a href=submit.php?action=query_batch&batch_id=$batch_id>Monitor its progress</a>
        <p>
        <a href=submit.php>View all your batches</a>
    ";
    page_tail();
}

function show_info() {
    page_head("Submitting AutoDock jobs");
    echo "
        This project lets you submit batches of AutoDock jobs.
        <p>
        Each job has
        <ul>
        <li> A JSON-format input file.
        <li> A command line containing arguments to AutoDock.
        </ul>
        For descriptions of these, see
        <a href=https://autodock.scripps.edu>the AutoDock web site</a>.
        <p>
        To submit a batch, you must create two files:
        <ol>
        <li> A zip file containing all of your input files.
        <li> A 'job description file' with one line per job.
            Each line is of the form 'input_filename,command-line'.
        </ol>
        For example, if your input files are input0.json and input1.json,
        the job description file might look like
        <p>
        <pre>
        input0.json,--arg=5 --verbose
        input1.json,--arg=4 --verbose</pre>
        Once you have prepared these files,
        the steps in submitting the jobs are:
        <ol>
        <li> Upload the .zip file and the job description file
            to your <a href=sandbox.php>file sandbox</a>
            on this project's server.
        <li> Fill out the <a href=autodock_submit.php>AutoDock
            job submission form</a> with the names of these files.
        <li>
            click Prepare; this will show if if there are any errors
            in your input files.
            If so, fix them and upload again.
        <li> If there are no errors, click Submit.
            This will queue the jobs for execution.
            Depending on system load, it may take a while for them to complete.
        <li> <a href=submit.php>Monitor</a> the progress
            of the batch, and download the output files when it's complete.
        </ol>
    ";
    page_tail();
}

if (!parse_bool($config, "enable_autodock_submit")) {
    error_page("Autodock not enabled");
}

$action = get_str('action', true);
if ($action == "info") {
    show_info();
    exit;
}

$user = get_logged_in_user();
$app = BoincApp::lookup("name='autodock'");
if (!$app) error_page("no autodock app");

switch ($action) {
case '':
    show_submit_form($user);
    break;
case 'prepare':
    prepare_batch($user, $app);
    break;
case 'submit':
    $batch_id = get_int("batch_id");
    submit_batch($user, $app, $batch_id);
    break;
default: error_page("no such action $action");
}

?>
