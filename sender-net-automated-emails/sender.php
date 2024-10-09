<?php

if (!defined('ABSPATH')) {
    exit;
}

/*
Plugin Name: Sender.net email marketing
Plugin URI: https://sender.net
description: If you're looking for a plugin that will turn your email & SMS marketing into a highly-profitable marketing channel — look no further, Sender's here to help. User-friendly and a super effective tool that will ease your marketing efforts instantly.
Version: 2.7.5
Author: Sender
Author URI: https://sender.net
License: GPL2
Text Domain: sender-net-automated-emails
Domain Path: /languages/
*/

if (!class_exists('Sender_Automated_Emails')) {
    require_once("includes/Sender_Automated_Emails.php");
}

new Sender_Automated_Emails(__FILE__);