<?php
namespace App\Console\Commands;
use App\Models\LegalEntity;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Models\UserScopeGrant;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
class CreateTenantUserCommand extends Command {
 protected $signature='hanasi:tenant:user:create';
 protected $description='Create or attach a login account to a tenant with a role and data scope.';
 public function handle(AuditLogger $audit): int {
  $tenantCode=strtolower(trim($this->ask('Tenant code')));
  $tenant=Tenant::where('code',$tenantCode)->where('status','active')->first();
  if (!$tenant) { $this->error('Active tenant was not found.'); return self::FAILURE; }
  $email=strtolower(trim($this->ask('User email')));
  if (!filter_var($email,FILTER_VALIDATE_EMAIL)) { $this->error('A valid email is required.'); return self::FAILURE; }
  $existing=User::where('email',$email)->first();
  $name=$existing?->name ?? trim($this->ask('User name'));
  $roleCode=strtolower(trim($this->ask('Role code in this tenant')));
  $role=Role::where('tenant_id',$tenant->id)->where('code',$roleCode)->where('status','active')->first();
  if (!$role) { $this->error('An active role with that code was not found in this tenant.'); return self::FAILURE; }
  $scopeType=$this->choice('Data scope',['tenant','legal_entity'],'tenant');
  $scopeId=null;
  if ($scopeType==='legal_entity') {
   $scopeId=(int)$this->ask('Legal entity ID');
   if (!LegalEntity::where('tenant_id',$tenant->id)->where('status','active')->whereKey($scopeId)->exists()) { $this->error('Active legal entity was not found in this tenant.'); return self::FAILURE; }
  }
  if ($existing && TenantMembership::where('tenant_id',$tenant->id)->where('user_id',$existing->id)->exists()) { $this->error('This account already has a membership in that tenant.'); return self::FAILURE; }
  $password=null;
  if (!$existing) {
   $password=$this->secret('Initial password');
   $confirmation=$this->secret('Confirm password');
   if (!$name || !$password || $password!==$confirmation) { $this->error('Name is required and passwords must match.'); return self::FAILURE; }
  }
  $membership=DB::transaction(function() use($tenant,$existing,$name,$email,$password,$role,$scopeType,$scopeId,$audit) {
   $user=$existing ?? User::create(['name'=>$name,'email'=>$email,'password'=>$password]);
   $membership=TenantMembership::create(['tenant_id'=>$tenant->id,'user_id'=>$user->id,'status'=>'pending']);
   $membership->roles()->attach($role->id,['tenant_id'=>$tenant->id]);
   UserScopeGrant::create(['tenant_id'=>$tenant->id,'membership_id'=>$membership->id,'scope_type'=>$scopeType,'scope_id'=>$scopeId]);
   $membership->status='active';
   $membership->save();
   $audit->record('membership.created',$membership,[],['source'=>'console user provisioning','scope_type'=>$scopeType],(int)$tenant->id);
   return $membership;
  });
  $this->info("Membership {$membership->id} is active in tenant {$tenant->code} with role {$role->code} and {$scopeType} scope.");
  if (!$existing) $this->line('The account password was set during this controlled console operation.');
  return self::SUCCESS;
 }
}
