<?php
/*
Plugin Name: Two Bright Lights Publishing Tool
Plugin URI:  https://git.xogrp.com/TwoBrightLights/wordpress-plugin
Description: Two Bright Lights Publishing Tool allows you to automatically mark your submission as published upon publishing your post in WordPress. No more copying and pasting the feature link into Two Bright Lights, the information will be transferred over to the system and will be included in the vendor notifications.
Version: 1.2
Author: Two Bright Lights
Author URI: https://www.twobrightlights.com
License: GPL
*/

add_action('transition_post_status', 'tbltools_publishSubmission', 10, 3);

function tbltools_publishSubmission($new_status, $old_status, $post) {
	if($new_status == 'publish' && $old_status == 'draft'){
		$postID = $post->ID;
		$userID = get_post_custom_values('tbl_user_id', $postID);
		$submissionID = get_post_custom_values('tbl_submission_id', $postID);
		$postLink = get_post_permalink($postID);
		if(!empty($submissionID) && !empty($userID)){
			$postData = array('userID'=>$userID[0], 'submissionID'=>$submissionID[0], 'postLink'=>$postLink, 'submit_to_other'=>1);
			$apiUrl = 'https://twobrightlights.com/apis/1.0/updateSubmissionStatus';
			$args = array(
				'body' => $postData,
				'timeout' => '5',
				'redirection' => '5',
				'httpversion' => '1.0',
				'blocking' => true,
				'sslverify' => false,
				'headers' => array(),
				'cookies' => array(),
			);
			wp_remote_post($apiUrl, $args);
		}
	}
}

add_action('rest_api_init', 'init_rest_routes');

function init_rest_routes() {
	register_rest_route('tbl/v1', 'post', array(
		'methods' => 'POST',
		'callback' =>'tbl_add_post',
		'permission_callback' => 'check_authorization'
	));
}

function tbl_add_post($request) {
	$body = $request->get_params();
	$title = $body['title'];
	$content = $body['content'];
	$tbl_user_id = $body['tbl_user_id'];
	$tbl_submission_id = $body['tbl_submission_id'];

	$id = wp_insert_post(
		array(
			'post_title' => $title,
			'post_content' => $content,
		)
	);
	update_post_meta($id, 'tbl_user_id', $tbl_user_id);
	update_post_meta($id, 'tbl_submission_id', $tbl_submission_id);
	return array(
		'id' => $id,
		'title' => $title,
		'content' => $content,
		'tbl_user_id' => $tbl_user_id,
		'tbl_submission_id' => $tbl_submission_id,
	);
}

function check_authorization() {
	$headers = getallheaders();
	$creds = array();
	$pair = array();
	try{
		$authorization = $headers['Authorization'];
		$raw_auth = base64_decode(explode(' ',$authorization)[1]);
		$pair = explode(':', $raw_auth);
	} catch(Exception $e) {
		echo $e;
		return false;
	}
	$username = $pair[0];
	$password = $pair[1];
	$creds['user_login'] = $username;
	$creds['user_password'] = $password;
	$creds['remember'] = false;
	$user = wp_signon( $creds, false );
	if(is_wp_error($user)) {
		return false;
	}
	wp_set_current_user( $user->ID, $user->user_login );
	return current_user_can('edit_posts');
}

function json_basic_auth_handler($user) {
	global $wp_json_basic_auth_error;
	$wp_json_basic_auth_error = null;
	// Don't authenticate twice
	if ( ! empty( $user ) ) {
		return $user;
	}
	// Check that we're trying to authenticate
	if ( !isset( $_SERVER['PHP_AUTH_USER'] ) ) {
		return $user;
	}
	$username = $_SERVER['PHP_AUTH_USER'];
	$password = $_SERVER['PHP_AUTH_PW'];

	remove_filter( 'determine_current_user', 'json_basic_auth_handler', 20 );
	$user = wp_authenticate( $username, $password );
	add_filter( 'determine_current_user', 'json_basic_auth_handler', 20 );
	if ( is_wp_error( $user ) ) {
		$wp_json_basic_auth_error = $user;
		return null;
	}
	$wp_json_basic_auth_error = true;
	return $user->ID;
}
add_filter( 'determine_current_user', 'json_basic_auth_handler', 20 );
function json_basic_auth_error($error) {
	// Passthrough other errors
	if ( ! empty( $error ) ) {
		return $error;
	}
	global $wp_json_basic_auth_error;
	return $wp_json_basic_auth_error;
}
add_filter( 'rest_authentication_errors', 'json_basic_auth_error' );