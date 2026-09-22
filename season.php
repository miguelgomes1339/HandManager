<?php
declare(strict_types=1);
require_once __DIR__.'/includes/auth.php';
require_login();
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf();
 $season=(string)($_POST['season']??APP_SEASON);
 if(preg_match('/^20\d{2}\/20\d{2}$/',$season)) $_SESSION['season']=$season;
}
redirect(safe_return_url('index.php'));
