<?php
namespace App\Console\Commands;
use App\Models\AuditLog;
use App\Models\LegalEntity;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Models\UserScopeGrant;
use Database\Seeders\PermissionCatalogSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
class CreateTenantCommand extends Command {
 protected $signature='hanasi:tenant:create';
 protected $description='Create a tenant, first legal entity and tenant administrator from the console.';
 public function handle(): int {
  $code=strtolower(trim($this->ask('Tenant code'))); $name=trim($this->ask('Tenant/group name'));
  $entityCode=strtolower(trim($this->ask('First legal entity code'))); $legalName=trim($this->ask('First legal entity legal name'));
  $adminName=trim($this->ask('Administrator name')); $email=strtolower(trim($this->ask('Administrator email')));
  $password=$this->secret('Administrator password'); $confirmation=$this->secret('Confirm password');
  if (!preg_match('/^[a-z0-9][a-z0-9_-]{1,49}$/',$code) || !preg_match('/^[a-z0-9][a-z0-9_-]{1,49}$/',$entityCode)) { $this->error('Codes must be 2-50 lowercase letters, numbers, hyphens or underscores.'); return self::FAILURE; }
  if (!$name || !$legalName || !$adminName || !filter_var($email,FILTER_VALIDATE_EMAIL) || !$password || $password!==$confirmation) { $this->error('Required values are missing or passwords do not match.'); return self::FAILURE; }
  if (Tenant::where('code',$code)->exists() || User::where('email',$email)->exists()) { $this->error('Tenant code or user email already exists.'); return self::FAILURE; }
  [$tenant,$user]=DB::transaction(function() use($code,$name,$entityCode,$legalName,$adminName,$email,$password) {
   app(PermissionCatalogSeeder::class)->run();
   $tenant=Tenant::create(['code'=>$code,'name'=>$name,'status'=>'active']);
   LegalEntity::create(['tenant_id'=>$tenant->id,'code'=>$entityCode,'legal_name'=>$legalName,'status'=>'active']);
   $user=User::create(['name'=>$adminName,'email'=>$email,'password'=>$password]);
   $membership=TenantMembership::create(['tenant_id'=>$tenant->id,'user_id'=>$user->id,'status'=>'pending']);
   $role=Role::create(['tenant_id'=>$tenant->id,'code'=>'tenant_admin','name'=>'Tenant Administrator','status'=>'active']);
   $role->permissions()->syncWithPivotValues(Permission::where('platform','management_dashboard')->pluck('id')->all(),['tenant_id'=>$tenant->id]);
   $membership->roles()->attach($role->id,['tenant_id'=>$tenant->id,'assigned_by'=>$user->id]);
   UserScopeGrant::create(['tenant_id'=>$tenant->id,'membership_id'=>$membership->id,'scope_type'=>'tenant','scope_id'=>null,'granted_by'=>$user->id]);
   $membership->status='active'; $membership->save();
   AuditLog::create(['tenant_id'=>$tenant->id,'actor_user_id'=>$user->id,'action'=>'tenant.bootstrap_created','subject_type'=>Tenant::class,'subject_id'=>(string)$tenant->id,'changes'=>['created'=>['tenant'=>$tenant->code]],'context'=>['source'=>'console bootstrap'],'created_at'=>now()]);
   return [$tenant,$user];
  });
  $this->info("Tenant {$tenant->code} created with first legal entity and administrator {$user->email}.");
  $this->line('No public tenant registration route is provided.');
  return self::SUCCESS;
 }
}
