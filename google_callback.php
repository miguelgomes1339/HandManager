<?php
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

if (!empty($_SESSION['user_id'])) redirect('index.php');

$state=(string)($_GET['state']??'');
$expected=(string)($_SESSION['google_oauth_state']??'');
unset($_SESSION['google_oauth_state']);

if(!$state || !$expected || !hash_equals($expected,$state)){
    flash('error','Não foi possível validar o pedido do Google. Tenta novamente.');
    redirect('login.php');
}

if(isset($_GET['error'])){
    flash('error','O início de sessão com Google foi cancelado.');
    redirect('login.php');
}

$code=(string)($_GET['code']??'');
if($code===''){
    flash('error','O Google não devolveu um código de autenticação.');
    redirect('login.php');
}

$redirectUri = (defined('GOOGLE_REDIRECT_URI') && GOOGLE_REDIRECT_URI!=='')
    ? GOOGLE_REDIRECT_URI
    : ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off' ? 'https' : 'http').'://'.$_SERVER['HTTP_HOST'].rtrim(dirname($_SERVER['PHP_SELF']),'/\\').'/google_callback.php');

function hm_http_post(string $url,array $data): array {
    if(!function_exists('curl_init')) throw new RuntimeException('A extensão cURL do PHP não está ativa.');
    $ch=curl_init($url);
    curl_setopt_array($ch,[
        CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>http_build_query($data),
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_TIMEOUT=>20,
        CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded']
    ]);
    $raw=curl_exec($ch);
    if($raw===false){$err=curl_error($ch);curl_close($ch);throw new RuntimeException($err);}
    $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json=json_decode($raw,true);
    if($status<200 || $status>=300 || !is_array($json)) throw new RuntimeException('Resposta inválida do Google.');
    return $json;
}
function hm_http_get_json(string $url,string $token): array {
    $ch=curl_init($url);
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_TIMEOUT=>20,
        CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token]
    ]);
    $raw=curl_exec($ch);
    if($raw===false){$err=curl_error($ch);curl_close($ch);throw new RuntimeException($err);}
    $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json=json_decode($raw,true);
    if($status<200 || $status>=300 || !is_array($json)) throw new RuntimeException('Não foi possível obter os dados da conta Google.');
    return $json;
}

try{
    $token=hm_http_post('https://oauth2.googleapis.com/token',[
        'code'=>$code,
        'client_id'=>GOOGLE_CLIENT_ID,
        'client_secret'=>GOOGLE_CLIENT_SECRET,
        'redirect_uri'=>$redirectUri,
        'grant_type'=>'authorization_code'
    ]);

    $access=(string)($token['access_token']??'');
    if($access==='') throw new RuntimeException('Token Google em falta.');

    $profile=hm_http_get_json('https://openidconnect.googleapis.com/v1/userinfo',$access);
    $email=mb_strtolower(trim((string)($profile['email']??'')));
    $name=trim((string)($profile['name']??''));
    $verified=(bool)($profile['email_verified']??false);

    if(!$verified || !filter_var($email,FILTER_VALIDATE_EMAIL)){
        throw new RuntimeException('A conta Google não disponibilizou um email verificado.');
    }
    if($name==='') $name=strstr($email,'@',true) ?: 'Utilizador Google';

    $st=db()->prepare("SELECT * FROM utilizadores WHERE Email=? LIMIT 1");
    $st->execute([$email]);
    $u=$st->fetch();

    if(!$u){
        $randomHash=password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT);
        $st=db()->prepare("INSERT INTO utilizadores (Nome,Email,Password,TipoUtilizador,Estado) VALUES (?,?,?,?,?)");
        $st->execute([$name,$email,$randomHash,'Atleta','Ativo']);
        $id=(int)db()->lastInsertId();
        $st=db()->prepare("SELECT * FROM utilizadores WHERE IdUtilizador=? LIMIT 1");
        $st->execute([$id]);
        $u=$st->fetch();
        activity_log('Registo Google','utilizadores',$id,'Conta criada através do Google');
    }

    if(!$u || ($u['Estado']??'')!=='Ativo'){
        throw new RuntimeException('Esta conta encontra-se inativa.');
    }

    session_regenerate_id(true);
    $_SESSION['user_id']=$u['IdUtilizador'];
    $_SESSION['user_name']=$u['Nome'];
    $_SESSION['user_role']=$u['TipoUtilizador'];

    if(column_exists('utilizadores','UltimoAcesso')){
        try{db()->prepare("UPDATE utilizadores SET UltimoAcesso=NOW() WHERE IdUtilizador=?")->execute([$u['IdUtilizador']]);}catch(Throwable){}
    }
    activity_log('Login Google','utilizadores',(int)$u['IdUtilizador']);
    redirect('index.php');
}catch(Throwable $e){
    flash('error','Falha no login Google: '.$e->getMessage());
    redirect('login.php');
}
