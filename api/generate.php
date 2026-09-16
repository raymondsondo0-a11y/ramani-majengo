<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out($ok,$message='', $extra=[]){http_response_code($ok?200:400);echo json_encode(array_merge(['ok'=>$ok,'message'=>$message],$extra),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
if($_SERVER['REQUEST_METHOD']!=='POST') out(false,'POST only');
$raw=file_get_contents('php://input');$data=json_decode($raw,true);
if(!is_array($data)) out(false,'Invalid JSON.');
$rooms=$data['rooms']??[];
if(!$rooms) out(false,'Ongeza angalau chumba kimoja.');
$plotW=max(5,min(100,(float)($data['plot_width']??20)));$plotD=max(5,min(100,(float)($data['plot_depth']??30)));
$cleanRooms=[];foreach(array_slice($rooms,0,30) as $r){$name=trim((string)($r['name']??'Room'));$w=max(1.5,min(15,(float)($r['w']??3.5)));$d=max(1.5,min(15,(float)($r['d']??3.5)));$cleanRooms[]=['name'=>$name?:'Room','w'=>$w,'d'=>$d];}
$input=[
 'project'=>trim((string)($data['project']??'House Project')),
 'location'=>trim((string)($data['location']??'')),
 'plot_width'=>$plotW,'plot_depth'=>$plotD,
 'road_side'=>(string)($data['road_side']??'North'),
 'style'=>(string)($data['style']??'Modern'),
 'notes'=>trim((string)($data['notes']??'')),
 'rooms'=>$cleanRooms
];

function fallback_plan($d){
  $rooms=[];$totalW=0;$totalD=0;
  foreach($d['rooms'] as $r){$rooms[]=['name'=>$r['name'],'width'=>round($r['w'],1),'depth'=>round($r['d'],1)];$totalW=max($totalW,$r['w']);$totalD+=$r['d'];}
  $houseW=min($d['plot_width']-2,$totalW*2+1);$houseD=min($d['plot_depth']-2,max(8,$totalD/2+2));
  return ['title'=>strtoupper($d['project']),'summary'=>'Concept ya awali iliyopangwa kutokana na mahitaji yaliyowekwa.','house_width'=>round($houseW,1),'house_depth'=>round($houseD,1),'measurements'=>'Jengo la concept: '.round($houseW,1).' m × '.round($houseD,1).' m. Kiwanja: '.$d['plot_width'].' m × '.$d['plot_depth'].' m.','room_experience'=>'Entrance inaelekezwa upande wa '.$d['road_side'].'. Living room hutumika kama circulation hub kuelekea dining, kitchen na bedroom corridor.','outside_preview'=>'Muonekano wa nje wa concept una façade rahisi, veranda/entrance, windows na pitched roof; style: '.$d['style'].'.','rooms'=>$rooms];
}

$key=getenv('OPENAI_API_KEY');
if(!$key){out(true,'AI key haijawekwa; nimetengeneza concept ya msingi.', ['plan'=>fallback_plan($input),'ai'=>false]);}

$system='You are an architectural space-planning assistant. Create a PRELIMINARY residential concept, not a certified construction drawing. Never claim structural safety, code compliance, permit approval, soil suitability, or readiness to build. Use metric units. Respect plot dimensions, road side, requested rooms and notes. Return ONLY valid JSON with exactly these keys: title, summary, house_width, house_depth, measurements, room_experience, outside_preview, rooms. rooms is an array of objects with name,width,depth. Make room dimensions realistic and keep the house inside the plot with reasonable setbacks. Keep the requested room names, but you may add a circulation/hall if useful. Measurements should explain the overall footprint and important room dimensions. room_experience should describe movement from entrance through common areas to private rooms. outside_preview should describe the exterior view. Do not include markdown.';
$user='Project requirements:\n'.json_encode($input,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
$body=json_encode(['model'=>'gpt-5.6-luna','input'=>[['role'=>'system','content'=>$system],['role'=>'user','content'=>$user]],'max_output_tokens'=>3500],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
$ch=curl_init('https://api.openai.com/v1/responses');curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>45,CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$key],CURLOPT_POSTFIELDS=>$body]);$res=curl_exec($ch);$http=curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);curl_close($ch);
if($res===false||$http<200||$http>=300){out(true,'AI service haikupatikana kwa sasa; nimetengeneza concept ya msingi.', ['plan'=>fallback_plan($input),'ai'=>false,'detail'=>$err?:'HTTP '.$http]);}
$j=json_decode($res,true);$text=$j['output_text']??'';
if(!$text && isset($j['output'])){foreach($j['output'] as $item){foreach(($item['content']??[]) as $c){if(isset($c['text'])){$text.=$c['text'];}}}}
$text=trim($text);$text=preg_replace('/^```(?:json)?\s*|\s*```$/i','',$text);$plan=json_decode($text,true);
if(!is_array($plan)||empty($plan['rooms'])){out(true,'AI ilirudisha jibu lisiloweza kuchorwa; nimetengeneza fallback.', ['plan'=>fallback_plan($input),'ai'=>false]);}
// Normalize room keys for the frontend.
$normalized=[];foreach($plan['rooms'] as $r){$normalized[]=['name'=>(string)($r['name']??'Room'),'width'=>(float)($r['width']??3.5),'depth'=>(float)($r['depth']??3.5)];}
$plan['rooms']=$normalized;$plan['house_width']=(float)($plan['house_width']??12);$plan['house_depth']=(float)($plan['house_depth']??10);$plan['title']=(string)($plan['title']??$input['project']);
out(true,'AI concept generated.',['plan'=>$plan,'ai'=>true]);
