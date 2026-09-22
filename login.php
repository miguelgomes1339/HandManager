<?php
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

if (!empty($_SESSION['user_id'])) redirect('index.php');
$error='';
$count=(int)db()->query("SELECT COUNT(*) FROM utilizadores")->fetchColumn();

if($_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf();
 $email=trim((string)($_POST['email']??''));
 $pass=(string)($_POST['password']??'');
 $st=db()->prepare("SELECT * FROM utilizadores WHERE Email=? AND Estado='Ativo' LIMIT 1");
 $st->execute([$email]);
 $u=$st->fetch();
 if($u && password_verify($pass,$u['Password'])){
   session_regenerate_id(true);
   $_SESSION['user_id']=$u['IdUtilizador'];
   $_SESSION['user_name']=$u['Nome'];
   $_SESSION['user_role']=$u['TipoUtilizador'];
   if(column_exists('utilizadores','UltimoAcesso')){
     try{db()->prepare("UPDATE utilizadores SET UltimoAcesso=NOW() WHERE IdUtilizador=?")->execute([$u['IdUtilizador']]);}catch(Throwable){}
   }
   activity_log('Login','utilizadores',(int)$u['IdUtilizador']);
   redirect('index.php');
 }
 $error='Email ou password incorretos.';
}
?>
<!doctype html>
<html lang="pt-PT">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Entrar · HandManager</title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="login-shell">
<section class="login-hero">
  <img src="assets/logo.svg" alt="HandManager">
  <div>
    <h1>Gestão desportiva de andebol, num só lugar.</h1>
    <p>Plantéis, competições, jogos, treino, saúde, performance e relatórios ligados diretamente à tua base MySQL.</p>
  </div>
  <p>Projeto PAP · HandManager</p>
</section>

<section class="login-card">
  <h2>Iniciar sessão</h2>
  <p>Escolhe como queres entrar no HandManager.</p>

  <?php render_flash(); ?>
  <?php if($error):?><div class="flash error"><?=h($error)?></div><?php endif;?>
  <?php if($count===0):?><div class="flash error">Ainda não existe nenhum utilizador. <a href="setup.php"><b>Criar o primeiro administrador</b></a>.</div><?php endif;?>

  <a class="btn google-btn" href="google_login.php" aria-label="Continuar com Google">
    <span class="google-g">G</span>
    <span>Continuar com Google</span>
  </a>

  <div class="auth-divider"><span>ou</span></div>

  <form method="post">
    <?=csrf_field()?>
    <div class="field"><label>Email</label><input type="email" name="email" required autocomplete="username"></div>
    <div class="field"><label>Password</label><input type="password" name="password" required autocomplete="current-password"></div>
    <button class="btn primary" type="submit">Entrar</button>
  </form>

  <a class="btn create-account-btn" href="registar.php">Criar conta</a>

  <div class="helper auth-help">
    Já tens conta? Entra com email e password ou usa o Google.<br>
    Ainda não tens? Carrega em <b>Criar conta</b>.
  </div>
</section>
</div>
</body>
</html>
