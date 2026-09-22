<?php
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';
$pdo=db();$error='';$ok='';
$count=(int)$pdo->query("SELECT COUNT(*) FROM utilizadores")->fetchColumn();

if($_SERVER['REQUEST_METHOD']==='POST' && $count===0){
 verify_csrf();
 $name=trim((string)($_POST['name']??''));$email=trim((string)($_POST['email']??''));$p=(string)($_POST['password']??'');$p2=(string)($_POST['password2']??'');
 if($name===''||!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($p)<8||$p!==$p2){$error='Preenche corretamente os campos. A password deve ter pelo menos 8 caracteres e coincidir.';}
 else{
  $st=$pdo->prepare("INSERT INTO utilizadores (Nome,Email,Password,TipoUtilizador,Estado) VALUES (?,?,?,?,?)");
  $st->execute([$name,$email,password_hash($p,PASSWORD_DEFAULT),'Administrador','Ativo']);
  $ok='Administrador criado. Já podes iniciar sessão.';$count=1;
 }
}
?><!doctype html><html lang="pt-PT"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Configuração · HandManager</title><link rel="stylesheet" href="assets/css/style.css"></head><body>
<div class="login-shell">
<section class="login-hero"><img src="assets/logo.svg" alt="HandManager"><div><h1>Configuração inicial</h1><p>Esta página cria apenas o primeiro administrador da aplicação.</p></div><p>Depois de criares a conta, podes gerir todos os restantes utilizadores dentro do HandManager.</p></section>
<section class="login-card"><h2>Primeiro administrador</h2>
<?php if($error):?><div class="flash error"><?=h($error)?></div><?php endif;?>
<?php if($ok):?><div class="flash success"><?=h($ok)?></div><a class="btn primary" href="login.php">Ir para o login</a>
<?php elseif($count>0):?><div class="flash success">Já existe pelo menos um utilizador.</div><a class="btn primary" href="login.php">Ir para o login</a>
<?php else:?><form method="post"><?=csrf_field()?>
<div class="field"><label>Nome</label><input name="name" required></div>
<div class="field"><label>Email</label><input type="email" name="email" required></div>
<div class="field"><label>Password</label><input type="password" name="password" minlength="8" required></div>
<div class="field"><label>Repetir password</label><input type="password" name="password2" minlength="8" required></div>
<button class="btn primary">Criar administrador</button></form><?php endif;?>
</section></div></body></html>