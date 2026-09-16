<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function out($ok,$message='',$extra=[]){http_response_code($ok?200:400);echo json_encode(array_merge(['ok'=>$ok,'message'=>$message],$extra),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
if($_SERVER['REQUEST_METHOD']!=='POST') out(false,'POST only');
$data=json_decode(file_get_contents('php://input'),true);if(!is_array($data)) out(false,'Invalid JSON.');
$plotW=(float)($data['plot_width']??0);$plotD=(float)($data['plot_depth']??0);if($plotW<=0||$plotD<=0) out(false,'Weka upana na urefu wa kiwanja kwa mita.');
$brief=trim((string)($data['brief']??$data['notes']??''));if($brief==='')out(false,'Andika maelezo ya nyumba unayotaka ili AI iweze kupanga spaces.');
$input=['project'=>trim((string)($data['project']??'House Project')),'client'=>trim((string)($data['client']??'')),'contact'=>trim((string)($data['contact']??'')),'location'=>trim((string)($data['location']??'')),'plot_width'=>$plotW,'plot_depth'=>$plotD,'road_side'=>(string)($data['road_side']??'North'),'style'=>trim((string)($data['style']??'')),'brief'=>$brief];
$key=getenv('OPENAI_API_KEY');
if(!$key && is_file(__DIR__.'/openai-config.php')) $key=trim((string)require __DIR__.'/openai-config.php');
if(!$key)out(false,'AI service haijaunganishwa kwa sasa. Weka OPENAI_API_KEY kwenye server.');

// Extract explicit hard requirements from the client's natural-language brief.
$hardBedrooms=null;
if(preg_match('/\b(?:bedrooms?|bed\s*rooms?|vyumba(?:\s+vya\s+kulala)?|vyumba)\s*(?:=|:|ni|ya)?\s*(\d{1,2})\b/iu',$brief,$m)) $hardBedrooms=(int)$m[1];
if($hardBedrooms===null && preg_match('/\b(?:vyumba\s*\d{1,2}|\d{1,2}\s+bedrooms?)\b/iu',$brief,$m)) { preg_match('/\d{1,2}/',$m[0],$n); if($n)$hardBedrooms=(int)$n[0]; }
$courtyardRequired=(bool)preg_match('/\b(courtyard|inner\s*courtyard|open\s*courtyard|patio\s+ya\s+katikati|uwanja\s+(?:wa\s+ndani|katikati)|nafasi\s+(?:ya\s+uwanja|wazi)\s+(?:katikati|ya\s+ndani)|open\s+space\s+katikati)\b/iu',$brief);

$constraints=[];
if($hardBedrooms!==null) $constraints[]='EXACTLY '.$hardBedrooms.' BEDROOMS. Do not create more or fewer bedrooms.';
if($courtyardRequired) $constraints[]='COURTYARD/OPEN CENTRAL SPACE IS MANDATORY. It must be represented as a real outdoor/open space in the plan, not as a bedroom or generic circulation.';
$constraintText=$constraints?implode("\n",$constraints):'No explicit exact bedroom count or courtyard requirement was detected; infer spaces from the brief.';

$system='You are a strict architectural space-planning engine. The client gives a natural-language brief, plot dimensions and optional style. You must obey explicit quantities and spaces as HARD CONSTRAINTS, not suggestions. Never replace an explicit room count with your own preference. Never inflate the program with extra bedrooms or other major rooms just to make the concept look impressive. If the client explicitly says 5 bedrooms, the output MUST contain exactly 5 bedroom objects and no additional bedroom hidden under another name. If the client explicitly requests a courtyard, central yard, inner yard or open space in the middle, it MUST be a real outdoor/open-space object named clearly (for example Courtyard/Uwanja wa Ndani) and must be included in the room/space list. Do not count a courtyard as a room. Preserve all explicit must-have spaces. If an explicit requirement cannot physically fit inside the supplied plot, do NOT silently change it: return needs_clarification=true with a concise explanation of the conflict. The client does NOT want an interview-style questionnaire for optional details; infer sensible dimensions and supporting spaces yourself.\n\nHARD CONSTRAINTS EXTRACTED BEFORE GENERATION:\n'.$constraintText.'\n\nThe plot dimensions are mandatory and must be respected. Use metric units. Generate a coherent preliminary architectural concept with realistic circulation, setbacks/yard logic, entrance, parking only when requested or clearly necessary, and sensible service spaces. Do not invent extra bedrooms. Keep the exterior concept consistent with the same floor-plan program and footprint. Return ONLY valid JSON with exactly these keys: needs_clarification, questions, title, summary, house_width, house_depth, measurements, room_experience, outside_preview, rooms. questions is normally empty. rooms is an array of objects with name,width,depth,type. type must be one of bedroom,bathroom,living,kitchen,dining,circulation,courtyard,utility,parking,other. For an explicit bedroom count, count only objects whose type is bedroom. For a required courtyard, include exactly one courtyard object unless the client explicitly asks for multiple courtyards. Do not use clarification merely to ask optional questions. This is preliminary planning, not structural engineering or final construction documentation.';
$user='CLIENT BRIEF:\n'.json_encode($input,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).'\n\nREPEAT THESE HARD CONSTRAINTS EXACTLY:\n'.$constraintText;
$body=json_encode(['model'=>'gpt-5.6-luna','input'=>[['role'=>'system','content'=>$system],['role'=>'user','content'=>$user]],'max_output_tokens'=>5000],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

$maxAttempts=3;$lastPlan=null;$lastError='';
for($attempt=1;$attempt<=$maxAttempts;$attempt++){
  $requestBody=$body;
  if($attempt>1){
    $repair='Previous plan failed validation. Regenerate the COMPLETE JSON plan. Do not explain the correction. You MUST satisfy these constraints: '.$constraintText.' The bedroom count is exact; courtyard requirement is mandatory; all spaces must fit the plot. Previous invalid plan: '.json_encode($lastPlan,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $requestBody=json_encode(['model'=>'gpt-5.6-luna','input'=>[['role'=>'system','content'=>$system],['role'=>'user','content'=>$user],['role'=>'user','content'=>$repair]],'max_output_tokens'=>5000],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  }
  $ch=curl_init('https://api.openai.com/v1/responses');curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>60,CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$key],CURLOPT_POSTFIELDS=>$requestBody]);$res=curl_exec($ch);$http=curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);curl_close($ch);
  if($res===false||$http<200||$http>=300){$lastError=$err?:'HTTP '.$http;continue;}
  $j=json_decode($res,true);$text=$j['output_text']??'';if(!$text&&isset($j['output']))foreach($j['output'] as $item)foreach(($item['content']??[]) as $c)if(isset($c['text']))$text.=$c['text'];$text=trim($text);$text=preg_replace('/^```(?:json)?\s*|\s*```$/i','',$text);$plan=json_decode($text,true);
  if(!is_array($plan)){$lastError='AI JSON invalid';continue;}
  $lastPlan=$plan;
  $plan['needs_clarification']=(bool)($plan['needs_clarification']??false);$plan['questions']=array_values(array_filter((array)($plan['questions']??[]),fn($q)=>trim((string)$q)!==''));
  if($plan['needs_clarification'])out(true,'AI inahitaji maelezo machache zaidi.',['plan'=>$plan,'ai'=>true]);
  if(empty($plan['rooms'])||!is_array($plan['rooms'])){$lastError='No rooms';continue;}
  $normalized=[];foreach(array_slice($plan['rooms'],0,40) as $r){$name=trim((string)($r['name']??''));if($name==='')continue;$w=(float)($r['width']??0);$d=(float)($r['depth']??0);$type=strtolower(trim((string)($r['type']??'other')));if($w<=0||$d<=0)continue;$normalized[]=['name'=>$name,'width'=>round($w,2),'depth'=>round($d,2),'type'=>$type];}
  if(!$normalized){$lastError='No valid dimensions';continue;}

  // Deterministic validation: explicit bedroom count and courtyard can never be overridden by the model.
  $bedCount=0;$courtyardCount=0;$area=0;foreach($normalized as $r){if($r['type']==='bedroom'||preg_match('/\bbed(room)?\b|chumba\s*(?:cha\s+kulala)?/iu',$r['name']))$bedCount++;if($r['type']==='courtyard'||preg_match('/courtyard|uwanja|patio|open\s*space/iu',$r['name']))$courtyardCount++;$area += $r['width']*$r['depth'];}
  if($hardBedrooms!==null && $bedCount!==$hardBedrooms){$lastError='Bedroom count mismatch: requested '.$hardBedrooms.', generated '.$bedCount;continue;}
  if($courtyardRequired && $courtyardCount<1){$lastError='Required courtyard missing';continue;}
  $houseW=(float)($plan['house_width']??min(max($plotW-1,1),$plotW));$houseD=(float)($plan['house_depth']??min(max($plotD-1,1),$plotD));
  if($houseW<=0||$houseD<=0||$houseW>$plotW||$houseD>$plotD){$lastError='House footprint exceeds plot';continue;}
  $plan['rooms']=$normalized;$plan['house_width']=round($houseW,2);$plan['house_depth']=round($houseD,2);$plan['title']=(string)($plan['title']??$input['project']);$plan['validation']=['requested_bedrooms'=>$hardBedrooms,'generated_bedrooms'=>$bedCount,'courtyard_required'=>$courtyardRequired,'courtyard_present'=>$courtyardCount>0,'plot_width'=>$plotW,'plot_depth'=>$plotD,'valid'=>true];
  out(true,'AI concept generated and validated.',['plan'=>$plan,'ai'=>true]);
}
out(false,'AI ilishindwa kufuata masharti ya mpango baada ya majaribio 3. Jaribu kubadilisha ukubwa wa kiwanja au maelezo ya brief.',['detail'=>$lastError]);
