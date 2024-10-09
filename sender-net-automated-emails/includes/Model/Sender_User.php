<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Sender_Model')) {
	require_once 'Sender_Model.php';
}

class Sender_User extends Sender_Model
{

	protected $tableName = 'sender_automated_emails_users';

	protected $id;

	protected $first_name;

	protected $last_name;

	protected $email;

	protected $created;

	protected $updated;

	protected $wp_user_id;

    protected $sender_newsletter;

    protected $sender_subscriber_id;
}