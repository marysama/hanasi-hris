<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
class CurrentUserController extends Controller {
 public function __invoke(TenantContext $context): JsonResponse {
  $m=$context->membership();
  $permissions=DB::table('membership_roles as mr')->join('roles as r',function($join){$join->on('r.id','=','mr.role_id')->on('r.tenant_id','=','mr.tenant_id');})
   ->join('role_permissions as rp',function($join){$join->on('rp.role_id','=','r.id')->on('rp.tenant_id','=','r.tenant_id');})
   ->join('permissions as p','p.id','=','rp.permission_id')->where('mr.tenant_id',$m->tenant_id)->where('mr.membership_id',$m->id)
   ->where('r.status','active')->where(fn($query)=>$query->whereNull('mr.effective_from')->orWhere('mr.effective_from','<=',now()))
   ->where(fn($query)=>$query->whereNull('mr.effective_to')->orWhere('mr.effective_to','>=',now()))
   ->select('p.platform','p.code')->distinct()->get()->groupBy('platform')->map(fn($rows)=>$rows->pluck('code')->values());
  return response()->json(['user'=>['id'=>$m->user->id,'name'=>$m->user->name,'email'=>$m->user->email],'membership_id'=>$m->id,'tenant'=>['id'=>$m->tenant->id,'code'=>$m->tenant->code,'name'=>$m->tenant->name],'roles'=>$m->roles()->where('roles.status','active')->pluck('roles.code'),'permissions'=>$permissions]);
 }
}