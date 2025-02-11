<?php

# Written by Simon Josefsson <simon@josefsson.org>.
# Copyright (c) 2009-2013 Yubico AB
# All rights reserved.
#
# Redistribution and use in source and binary forms, with or without
# modification, are permitted provided that the following conditions are
# met:
#
#   * Redistributions of source code must retain the above copyright
#     notice, this list of conditions and the following disclaimer.
#
#   * Redistributions in binary form must reproduce the above
#     copyright notice, this list of conditions and the following
#     disclaimer in the documentation and/or other materials provided
#     with the distribution.
#
# THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
# "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
# LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
# A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
# OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
# SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
# LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
# DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
# THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
# (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
# OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.

ob_start();
require_once 'ykksm-config.php';
require_once 'ykksm-utils.php';
ob_end_clean();

openlog("ykksm", LOG_PID, $logfacility)
  or die("ERR Syslog open error\n");

$otp = $_REQUEST["otp"];
if (!$otp) {
  syslog(LOG_INFO, "No OTP provided");
  die("ERR No OTP provided\n");
 }

if (!preg_match("/^([cbdefghijklnrtuv]{0,16})([cbdefghijklnrtuv]{32})$/",
		$otp, $matches)) {
  syslog(LOG_INFO, "Invalid OTP format: $otp");
  die("ERR Invalid OTP format\n");
 }
$id = $matches[1];
$modhex_ciphertext = $matches[2];

# Oracle support in PDO is highly experimental, OCI is used instead
# Unfortunately PDO and OCI APIs are different...
$use_oci = substr($db_dsn,0,3) === 'oci';

if (!$use_oci) {
  try {
    $dbh = new PDO($db_dsn, $db_username, $db_password, $db_options);
    # Force column names to be lower case. Otherwise Firebird will not
    # work because all access happens with lower case constants.
    $dbh->setAttribute(PDO::ATTR_CASE, PDO::CASE_LOWER);
   } catch (PDOException $e) {
    syslog(LOG_ERR, "Database error: " . $e->getMessage());
    die("ERR Database error\n");
   }
 }
else {
  # "oci:" prefix needs to be removed before passing db_dsn to OCI
  $db_dsn = substr($db_dsn, 4);
  $dbh = oci_connect($db_username, $db_password, $db_dsn);
  if (!$dbh) {
    $error = oci_error();
    syslog(LOG_err, "Database error: " . $error["message"]);
    die("ERR Database error\n");
   }
 }

$sql = "SELECT aeskey, internalname FROM yubikeys " .
       "WHERE publicname = '$id' AND ";

if (!$use_oci) {
  $sql .= "(active OR active = 'true')";
  $result = $dbh->query($sql);
  if (!$result) {
    syslog(LOG_ERR, "Database query error.  Query: " . $sql . " Error: " .
           print_r ($dbh->errorInfo (), true));
    die("ERR Database error\n");
   }

  $row = $result->fetch(PDO::FETCH_ASSOC);
  $aeskey = $row['aeskey'];
  $internalname = $row['internalname'];
 }
else {
  $sql .= "active = 1";
  $result = oci_parse($dbh, $sql);
  $execute = oci_execute($result);
  if (!$execute) {
    $error = oci_error($result);
    syslog(LOG_ERR, 'Database query error.   Query:  ' . $sql . 'Error: CODE : ' . $error["code"] .
           ' MESSAGE : ' . $error["message"] . ' POSITION : ' . $error["offset"] .
           ' STATEMENT : ' . $error["sqltext"]);
    die("ERR Database error\n");
   }

  $row = oci_fetch_array($result, OCI_ASSOC);
  $aeskey = $row['AESKEY'];
  $internalname = $row['INTERNALNAME'];
 }

if (!$aeskey) {
  syslog(LOG_INFO, "Unknown yubikey: " . $otp);
  die("ERR Unknown yubikey\n");
 }

$ciphertext = modhex2hex($modhex_ciphertext);
$plaintext = aes128ecb_decrypt($aeskey, $ciphertext);

$uid = substr($plaintext, 0, 12);
if (strcmp($uid, $internalname) != 0) {
  syslog(LOG_ERR, "UID error: $otp $plaintext: $uid vs $internalname");
  die("ERR Corrupt OTP\n");;
 }

if (!crc_is_good($plaintext)) {
  syslog(LOG_ERR, "CRC error: $otp: $plaintext");
  die("ERR Corrupt OTP\n");
 }

# Mask out interesting fields
$counter = substr($plaintext, 14, 2) . substr($plaintext, 12, 2);
$low = substr($plaintext, 18, 2) . substr($plaintext, 16, 2);
$high = substr($plaintext, 20, 2);
$use = substr($plaintext, 22, 2);

$out = "OK counter=$counter low=$low high=$high use=$use";

syslog(LOG_INFO, "SUCCESS OTP $otp PT $plaintext $out")
  or die("ERR Log error\n");

print "$out\n";

# Close database connection.
$dbh = null;

?>
