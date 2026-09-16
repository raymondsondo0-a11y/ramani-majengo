<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function reply($ok,$message='',$extra=[]){http_response_code($ok?200:400);echo json_encode(array_merge(['ok'=>$ok,'message'=>$message],$extra),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
if($_SERVER['REQUEST_METHOD']!=='POST') reply(false,'POST only');
$data=json_decode(file_get_contents('php://input'),true);
if(!is_array($data)) reply(false,'Invalid JSON.');
$key=getenv('OPENAI_API_KEY');
if(!$key && is_file(__DIR__.'/openai-config.php')) $key=trim((string)require __DIR__.'/openai-config.php');
if(!$key) reply(false,'AI service haijaunganishwa kwa sasa.');
$rooms=is_array($data['rooms']??null)?$data['rooms']:[];
$prompt='Create a photorealistic professional architectural exterior visualization of the same preliminary house concept represented by this floor-plan data. Respect the house footprint proportions, room dimensions, entrance side, circulation logic and stated architectural style. The result must look like a realistic buildable human-designed house, with coherent massing, rooflines, doors and windows, natural daylight, landscaping and driveway. Show the complete building from an eye-level three-quarter front view. No floor plan, no labels, no dimensions, no text, no watermark, no cartoon, no fantasy elements. House footprint: '.(float)($data['house_width']??0).'m wide x '.(float)($data['house_depth']??0).'m deep. Plot: '.(float)($data['plot_width']??0).'m x '.(float)($data['plot_depth']??0).'m. Road side: '.(string)($data['road_side']??'North').'. Style: '.(string)($data['style']??'contemporary').'. Rooms: '.json_encode($rooms,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).'. Exterior planning notes: '.(string)($data['outside_preview']??'').'. Client brief: '.(string)($data['brief']??'');
$body=json_encode(['model'=>'gpt-image-1','prompt'=>$prompt,'size'=>'1536x1024','quality'=>'high','output_format'=>'png'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
$ch=curl_init('https://api.openai.com/v1/images/generations');curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>120,CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$key],CURLOPT_POSTFIELDS=>$body]);
$res=curl_exec($ch);$http=curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);curl_close($ch);
if($res===false||$http<200||$http>=300) reply(false,'Exterior image generation failed.',['detail'=>$err?:'HTTP '.$http]);
$json=json_decode($res,true);$b64=$json['data'][0]['b64_json']??'';if(!$b64) reply(false,'Image API returned no image data.');
$dir=__DIR__.'/../generated/exteriors';if(!is_dir($dir)&&!@mkdir($dir,0755,true)) reply(false,'Server could not create image folder.');
$name='exterior_'.date('Ymd_His').'_'.bin2hex(random_bytes(4)).'.png';$path=$dir.'/'.$name;$bytes=base64_decode($b64,true);if($bytes===false||@file_put_contents($path,$bytes)===false) reply(false,'Server could not save the generated image.');
reply(true,'Exterior concept generated.',['image_url'=>'generated/exteriors/'.$name]);
