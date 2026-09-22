<?php
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

if (!empty($_SESSION['user_id'])) redirect('index.php');

if (!defined('GOOGLE_CLIENT_ID') || !defined('GOOGLE_CLIENT_SECRET') || GOOGLE_CLIENT_ID==='' || GOOGLE_CLIENT_SECRET==='') {
    flash('error','O login Google ainda precisa de ser configurado. Abre config/config.php e coloca o GOOGLE_CLIENT_ID e GOOGLE_CLIENT_SECRET.');
    redirect('login.php');
}

$state=bin2hex(random_bytes(24));
$_SESSION['google_oauth_state']=$state;

$redirectUri = (defined('GOOGLE_REDIRECT_URI') && GOOGLE_REDIRECT_URI!=='')
    ? GOOGLE_REDIRECT_URI
    : ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off' ? 'https' : 'http').'://'.$_SERVER['HTTP_HOST'].rtrim(dirname($_SERVER['PHP_SELF']),'/\\').'/google_callback.php');

$params=[
    'client_id'=>GOOGLE_CLIENT_ID,
    'redirect_uri'=>$redirectUri,
    'response_type'=>'code',
    'scope'=>'openid email profile',
    'state'=>$state,
    'access_type'=>'online',
    'prompt'=>'select_account'
];

redirect('https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query($params));
