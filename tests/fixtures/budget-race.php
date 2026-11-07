<?php
// Disposable PostgreSQL integration check. No provider inference, application DB or Git writes.
require '/var/www/html/vendor/autoload.php';
$app=require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Models\{User,Organization,Project,Conversation,ModelCall};
use App\Services\AI\{UsageLedger,ProviderException};
if(config('app.env')!=='testing'||config('database.default')!=='pgsql'||!str_ends_with(config('database.connections.pgsql.database'),'_test')||config('database.connections.pgsql.url')){throw new RuntimeException('Dedicated test database required');}
config(['ai.daily_user_budget'=>1500,'ai.daily_project_budget'=>1500,'ai.daily_organization_budget'=>1500]);
$user=User::factory()->create();$org=Organization::create(['name'=>'Budget race fixture']);$project=Project::create(['organization_id'=>$org->id,'name'=>'Budget race']);$conversation=Conversation::create(['project_id'=>$project->id,'user_id'=>$user->id,'title'=>'Budget race']);
$directory=sys_get_temp_dir().'/budget-race-'.Str::uuid();mkdir($directory,0700);$children=[];
DB::disconnect();
try {
 for($i=0;$i<2;$i++){
  $pid=pcntl_fork();if($pid===-1){throw new RuntimeException('Fork unavailable');}
  if($pid===0){
   file_put_contents($directory.'/ready-'.$i,'ready');$deadline=microtime(true)+10;
   while(!file_exists($directory.'/go')){if(microtime(true)>$deadline){exit(3);}usleep(10000);}
   try{app(UsageLedger::class)->reserve(Conversation::findOrFail($conversation->id),['id'=>'synthetic','provider'=>'openai','input_price_per_million'=>1,'output_price_per_million'=>1,'cached_price_per_million'=>1],'message',(string)Str::uuid(),1,['input_tokens'=>1000,'output_reserved'=>0,'safety_reserved'=>0]);exit(0);}catch(ProviderException $e){exit($e->errorCode==='budget_exceeded'?2:3);}catch(Throwable){exit(3);}
  }
  $children[]=$pid;
 }
 $deadline=microtime(true)+10;while(count(glob($directory.'/ready-*'))<2){if(microtime(true)>$deadline){throw new RuntimeException('Barrier timed out');}usleep(10000);}file_put_contents($directory.'/go','go');
 $results=[];foreach($children as $pid){pcntl_waitpid($pid,$status);$results[]=pcntl_wexitstatus($status);}sort($results);
 if($results!==[0,2]||ModelCall::where('user_id',$user->id)->count()!==1){throw new RuntimeException('Concurrent budget cap failed');}
 echo "PASS: two concurrent PostgreSQL reservations; exactly one accepted, one budget-blocked\n";
}finally{
 foreach(glob($directory.'/*') as $file){unlink($file);}rmdir($directory);
 ModelCall::where('user_id',$user->id)->delete();$project->delete();$org->delete();$user->delete();
}
