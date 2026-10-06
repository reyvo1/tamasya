<?php
declare(strict_types=1);

/**
 * TAMASYA FINAL12-PROD2 public production configuration tool.
 *
 * CLI (PHP only, Node is not required):
 *   php public_config_tool.php --production \
 *     --app-url=https://app.example.com \
 *     --site-url=https://www.example.com \
 *     [--public-root=/absolute/path/to/public_site] [--dry-run]
 *
 * Browser:
 *   1. Ensure admin_app/.env contains ADMIN_WEB_TOOLS_ENABLED=1 and a strong
 *      ADMIN_WEB_TOOLS_SECRET (>=32 chars), and use HTTPS.
 *   2. Open this file in the browser, enter the same values, and apply.
 *   3. Set ADMIN_WEB_TOOLS_ENABLED=0 again after commissioning.
 *
 * This tool writes only these four deployment files inside a validated public
 * site root: runtime-config.js, robots.txt, sitemap.xml, and .htaccess.
 * It never accepts or writes application/database secrets.
 */
require_once __DIR__.'/database_bootstrap.php'; // loads .env before the browser gate
require_once __DIR__.'/admin_web_tool_gate.php';

function tamasyaPublicConfigArg(array $argv, string $name, string $envName, string $fallback=''): string {
    $prefix='--'.$name.'=';
    foreach($argv as $value){
        if(str_starts_with((string)$value,$prefix)) return trim(substr((string)$value,strlen($prefix)));
    }
    $env=getenv($envName);
    return trim($env!==false?(string)$env:$fallback);
}

function tamasyaPublicConfigNormalizeUrl(string $raw,string $label,bool $production): array {
    $raw=trim($raw);
    if($raw==='') throw new RuntimeException($label.' wajib diisi.');
    if(strlen($raw)>2048) throw new RuntimeException($label.' terlalu panjang.');
    if(!filter_var($raw,FILTER_VALIDATE_URL)) throw new RuntimeException($label.' bukan URL valid.');
    $parts=parse_url($raw);
    if(!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) throw new RuntimeException($label.' harus berupa URL origin lengkap.');
    $scheme=strtolower((string)$parts['scheme']);
    if(!in_array($scheme,['http','https'],true)) throw new RuntimeException($label.' hanya boleh http/https.');
    if($production && $scheme!=='https') throw new RuntimeException($label.' production wajib HTTPS.');
    if(isset($parts['user']) || isset($parts['pass'])) throw new RuntimeException($label.' tidak boleh mengandung username/password.');
    if(isset($parts['query']) || isset($parts['fragment'])) throw new RuntimeException($label.' tidak boleh mengandung query/fragment.');
    $path=(string)($parts['path']??'');
    if($path!=='' && $path!=='/') throw new RuntimeException($label.' harus berupa origin tanpa path.');
    $host=strtolower(trim((string)$parts['host']));
    if($host==='') throw new RuntimeException($label.' hostname kosong.');
    if($production && preg_match('/(^|[.-])(staging|stage|dev|test|example|localhost|local)([.-]|$)/i',$host)) {
        throw new RuntimeException($label.' masih terlihat sebagai host non-production: '.$host);
    }
    $port=isset($parts['port'])?(int)$parts['port']:null;
    if($port!==null && ($port<1 || $port>65535)) throw new RuntimeException($label.' port tidak valid.');
    $hostForOrigin=str_contains($host,':')?'['.$host.']':$host;
    $origin=$scheme.'://'.$hostForOrigin.($port!==null?':'.$port:'');
    return ['origin'=>$origin,'host'=>$host,'scheme'=>$scheme,'port'=>$port];
}

function tamasyaPublicConfigResolveRoot(string $requested=''): string {
    $candidate=trim($requested);
    if($candidate==='') $candidate=trim((string)(getenv('TAMASYA_PUBLIC_SITE_ROOT')?:''));
    if($candidate==='') $candidate=dirname(__DIR__).DIRECTORY_SEPARATOR.'public_site';
    $real=realpath($candidate);
    if($real===false || !is_dir($real)) throw new RuntimeException('Public site root tidak ditemukan: '.$candidate);
    $required=['runtime-config.js','robots.txt','sitemap.xml','.htaccess'];
    foreach($required as $name){
        $path=$real.DIRECTORY_SEPARATOR.$name;
        if(!is_file($path)) throw new RuntimeException('Public site root ditolak karena file wajib tidak ada: '.$name);
        if(!is_readable($path)) throw new RuntimeException('File public site tidak dapat dibaca: '.$name);
    }
    $ht=(string)file_get_contents($real.DIRECTORY_SEPARATOR.'.htaccess');
    if(!str_contains($ht,'TAMASYA_API_ORIGIN:')) throw new RuntimeException('Public site root ditolak: marker TAMASYA_API_ORIGIN tidak ditemukan.');
    if(!preg_match("/connect-src 'self' [^;]+;/",$ht)) throw new RuntimeException('Public site root ditolak: CSP connect-src tidak ditemukan.');
    return $real;
}

function tamasyaPublicConfigAtomicWrite(string $path,string $content): void {
    $dir=dirname($path);
    if(!is_dir($dir) || !is_writable($dir)) throw new RuntimeException('Folder tidak writable: '.$dir);
    $mode=@fileperms($path);
    $tmp=@tempnam($dir,'.tamasya-config-');
    if($tmp===false) throw new RuntimeException('Tidak dapat membuat temporary file di '.$dir);
    try{
        if(@file_put_contents($tmp,$content,LOCK_EX)===false) throw new RuntimeException('Gagal menulis temporary file untuk '.basename($path));
        if($mode!==false) @chmod($tmp,$mode & 0777);
        if(!@rename($tmp,$path)) throw new RuntimeException('Gagal mengganti '.basename($path).' secara atomik.');
    }finally{
        if(is_file($tmp)) @unlink($tmp);
    }
}

function tamasyaPublicConfigBuild(string $appRaw,string $siteRaw,string $publicRoot,bool $production): array {
    $app=tamasyaPublicConfigNormalizeUrl($appRaw,'APP URL',$production);
    $site=tamasyaPublicConfigNormalizeUrl($siteRaw,'PUBLIC SITE URL',$production);
    $appOrigin=(string)$app['origin'];
    $siteOrigin=(string)$site['origin'];
    $apiUrl=$appOrigin.'/api.php';

    $runtime="(function () {\n  \"use strict\";\n\n  // Generated by TAMASYA public config tool. URLs only; never store secrets here.\n  const runtime = Object.freeze({\n    API_URL: ".json_encode($apiUrl,JSON_UNESCAPED_SLASHES).",\n    SITE_URL: ".json_encode($siteOrigin,JSON_UNESCAPED_SLASHES)."\n  });\n\n  window.TAMASYA_PUBLIC_RUNTIME = runtime;\n  window.TAMASYA_PUBLIC_API_URL = runtime.API_URL;\n  window.TAMASYA_PUBLIC_SITE_URL = runtime.SITE_URL;\n})();\n";
    $sitemap="<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n  <url>\n    <loc>".htmlspecialchars($siteOrigin.'/',ENT_XML1|ENT_QUOTES,'UTF-8')."</loc>\n    <changefreq>weekly</changefreq>\n    <priority>1.0</priority>\n  </url>\n</urlset>\n";
    $robots="User-agent: *\nAllow: /\nSitemap: ".$siteOrigin."/sitemap.xml\n";

    $htPath=$publicRoot.DIRECTORY_SEPARATOR.'.htaccess';
    $ht=(string)file_get_contents($htPath);
    $replaced=preg_replace('/^# TAMASYA_API_ORIGIN:.*$/m','# TAMASYA_API_ORIGIN: '.$appOrigin.' — prefer admin/tamasya_configurator.php; legacy Node/public_config_tool.php remain supported when domains change.',$ht,1,$markerCount);
    if(!is_string($replaced) || $markerCount!==1) throw new RuntimeException('Marker TAMASYA_API_ORIGIN tidak dapat diperbarui.');
    $ht=preg_replace("/connect-src 'self' [^;]+;/","connect-src 'self' ".$appOrigin.";",$replaced,1,$cspCount);
    if(!is_string($ht) || $cspCount!==1) throw new RuntimeException('CSP connect-src tidak dapat diperbarui.');

    return [
        'appOrigin'=>$appOrigin,
        'siteOrigin'=>$siteOrigin,
        'apiUrl'=>$apiUrl,
        'sameOrigin'=>$appOrigin===$siteOrigin,
        'files'=>[
            'runtime-config.js'=>$runtime,
            'robots.txt'=>$robots,
            'sitemap.xml'=>$sitemap,
            '.htaccess'=>$ht,
        ],
    ];
}

function tamasyaPublicConfigApply(string $appRaw,string $siteRaw,string $rootRaw,bool $production,bool $dryRun=false): array {
    $publicRoot=tamasyaPublicConfigResolveRoot($rootRaw);
    $built=tamasyaPublicConfigBuild($appRaw,$siteRaw,$publicRoot,$production);
    $original=[];
    foreach(array_keys($built['files']) as $name){
        $path=$publicRoot.DIRECTORY_SEPARATOR.$name;
        if(!is_writable($path) && !is_writable(dirname($path))) throw new RuntimeException('File tidak writable: '.$path);
        $original[$name]=(string)file_get_contents($path);
    }
    if(!$dryRun){
        $written=[];
        try{
            foreach($built['files'] as $name=>$content){
                tamasyaPublicConfigAtomicWrite($publicRoot.DIRECTORY_SEPARATOR.$name,(string)$content);
                $written[]=$name;
            }
        }catch(Throwable $e){
            $rollbackErrors=[];
            foreach(array_reverse($written) as $name){
                try{tamasyaPublicConfigAtomicWrite($publicRoot.DIRECTORY_SEPARATOR.$name,$original[$name]);}
                catch(Throwable $rollback){$rollbackErrors[]=$name.': '.$rollback->getMessage();}
            }
            if($rollbackErrors) throw new RuntimeException($e->getMessage().' Rollback tidak lengkap: '.implode('; ',$rollbackErrors),0,$e);
            throw $e;
        }
    }
    return [
        'success'=>true,
        'dryRun'=>$dryRun,
        'production'=>$production,
        'publicSiteRoot'=>$publicRoot,
        'appOrigin'=>$built['appOrigin'],
        'siteOrigin'=>$built['siteOrigin'],
        'apiUrl'=>$built['apiUrl'],
        'sameOrigin'=>$built['sameOrigin'],
        'updatedFiles'=>array_keys($built['files']),
        'message'=>$dryRun?'Validasi berhasil; tidak ada file yang diubah.':'Public production config berhasil diterapkan.',
    ];
}

$isCli=tamasyaAdminToolIsCli();
if($isCli){
    $argv=$_SERVER['argv']??[];
    $production=in_array('--production',$argv,true);
    $dryRun=in_array('--dry-run',$argv,true);
    try{
        $result=tamasyaPublicConfigApply(
            tamasyaPublicConfigArg($argv,'app-url','TAMASYA_APP_URL',trim((string)(getenv('APP_URL')?:'https://app.nolink.my.id'))),
            tamasyaPublicConfigArg($argv,'site-url','TAMASYA_PUBLIC_SITE_URL',trim((string)(getenv('PUBLIC_SITE_URL')?:'https://tamasya.nolink.my.id'))),
            tamasyaPublicConfigArg($argv,'public-root','TAMASYA_PUBLIC_SITE_ROOT',''),
            $production,
            $dryRun
        );
        tamasyaAdminToolEmit($result);
        exit(0);
    }catch(Throwable $e){
        tamasyaAdminToolEmit(['success'=>false,'error'=>$e->getMessage()],1,true);
        exit(1);
    }
}

tamasyaAdminToolBeginWeb('TAMASYA Public Production Config');
$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
if($method!=='POST'){
    $defaultRoot=trim((string)(getenv('TAMASYA_PUBLIC_SITE_ROOT')?:''));
    $defaultApp=trim((string)(getenv('TAMASYA_APP_URL')?:getenv('APP_URL')?:'https://app.nolink.my.id'));
    $defaultSite=trim((string)(getenv('TAMASYA_PUBLIC_SITE_URL')?:getenv('PUBLIC_SITE_URL')?:'https://tamasya.nolink.my.id'));
    $fields='<label for="app_url">Admin/API production origin</label>'
        .'<input id="app_url" name="app_url" type="text" value="'.htmlspecialchars($defaultApp,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'" required placeholder="https://app.domain-anda.com">'
        .'<label for="site_url">Public website production origin</label>'
        .'<input id="site_url" name="site_url" type="text" value="'.htmlspecialchars($defaultSite,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'" required placeholder="https://www.domain-anda.com">'
        .'<label for="public_root">Public site root di server (opsional)</label>'
        .'<input id="public_root" name="public_root" type="text" value="'.htmlspecialchars($defaultRoot,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'" placeholder="Kosong = folder public_site sibling / gunakan TAMASYA_PUBLIC_SITE_ROOT">'
        .'<label><input type="checkbox" name="dry_run" value="1"> Validasi saja / dry-run (tidak menulis file)</label>'
        .'<label><input type="checkbox" name="confirm" value="APPLY_PUBLIC_CONFIG" required> Saya memastikan kedua domain di atas adalah domain deployment yang benar.</label>';
    tamasyaAdminToolForm(
        'TAMASYA Public Production Config',
        'Generator browser aman untuk runtime-config.js, robots.txt, sitemap.xml, dan CSP public site. Production selalu mewajibkan HTTPS dan menolak hostname staging/dev/test/example/local.',
        $fields,
        'Validasi / Terapkan Public Config'
    );
}

tamasyaAdminToolAuthorizeWeb();
if((string)($_POST['confirm']??'')!=='APPLY_PUBLIC_CONFIG'){
    tamasyaAdminToolEmit(['success'=>false,'error'=>'Konfirmasi APPLY_PUBLIC_CONFIG wajib.'],400,true);exit;
}
try{
    $result=tamasyaPublicConfigApply(
        (string)($_POST['app_url']??''),
        (string)($_POST['site_url']??''),
        (string)($_POST['public_root']??''),
        true,
        (string)($_POST['dry_run']??'')==='1'
    );
    $result['securityReminder']='Set ADMIN_WEB_TOOLS_ENABLED=0 kembali setelah commissioning selesai.';
    tamasyaAdminToolEmit($result,200,false);
}catch(Throwable $e){
    tamasyaAdminToolEmit(['success'=>false,'error'=>$e->getMessage()],400,true);
}
