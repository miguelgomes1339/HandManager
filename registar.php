<?php
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

if (!empty($_SESSION['user_id'])) redirect('index.php');

$error='';
$name='';
$email='';

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    $name=trim((string)($_POST['nome']??''));
    $email=mb_strtolower(trim((string)($_POST['email']??'')));
    $pass=(string)($_POST['password']??'');
    $confirm=(string)($_POST['confirm_password']??'');

    if(mb_strlen($name)<3){
        $error='Indica o teu nome completo.';
    } elseif(!filter_var($email,FILTER_VALIDATE_EMAIL)){
        $error='Indica um email válido.';
    } elseif(strlen($pass)<6){
        $error='A password deve ter pelo menos 6 caracteres.';
    } elseif($pass!==$confirm){
        $error='As passwords não coincidem.';
    } else {
        $st=db()->prepare("SELECT IdUtilizador FROM utilizadores WHERE Email=? LIMIT 1");
        $st->execute([$email]);
        if($st->fetch()){
            $error='Já existe uma conta com este email.';
        } else {
            $hash=password_hash($pass,PASSWORD_DEFAULT);
            $st=db()->prepare("INSERT INTO utilizadores (Nome,Email,Password,TipoUtilizador,Estado) VALUES (?,?,?,?,?)");
            $st->execute([$name,$email,$hash,'Atleta','Ativo']);
            $id=(int)db()->lastInsertId();
            activity_log('Registo de conta','utilizadores',$id,'Conta criada pelo formulário público');
            flash('success','Conta criada com sucesso. Já podes iniciar sessão.');
            redirect('login.php');
        }
    }
}
?>
<!doctype html>
<html lang="pt-PT">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Criar conta · HandManager</title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="login-shell register-shell">
<section class="login-hero">
  <img src="assets/logo.svg" alt="HandManager">
  <div>
    <h1>Cria a tua conta.</h1>
    <p>Entra no HandManager para consultares a informação desportiva e utilizares as funcionalidades disponíveis para o teu perfil.</p>
  </div>
  <p>Projeto PAP · HandManager</p>
</section>

<section class="login-card">
  <h2>Criar conta</h2>
  <p>Preenche os dados abaixo.</p>

  <?php if($error):?><div class="flash error"><?=h($error)?></div><?php endif;?>

  <a class="btn google-btn" href="google_login.php">
    <span class="google-g">G</span>
    <span>Registar com Google</span>
  </a>

  <div class="auth-divider"><span>ou</span></div>

  <form method="post">
    <?=csrf_field()?>
    <div class="field"><label>Nome</label><input type="text" name="nome" value="<?=h($name)?>" required autocomplete="name"></div>
    <div class="field"><label>Email</label><input type="email" name="email" value="<?=h($email)?>" required autocomplete="email"></div>
    <div class="field"><label>Password</label><input type="password" name="password" required autocomplete="new-password"></div>
    <div class="field"><label>Confirmar password</label><input type="password" name="confirm_password" required autocomplete="new-password"></div>
    <button class="btn primary" type="submit">Criar conta</button>
  </form>

  <a class="btn create-account-btn" href="login.php">← Voltar ao início de sessão</a>
  <div class="helper">As novas contas são criadas como <b>Atleta</b>. Um administrador pode alterar posteriormente o tipo de utilizador.</div>
</section>
</div>
</body>
</html>
