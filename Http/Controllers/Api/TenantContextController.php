<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\TenantMembership;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
class TenantContextController extends Controller {
 public function update(Request $r,AuditLogger $audit): JsonResponse {
  $d=$r->validate(['membership_id'=>['required','integer']]);
  $m=TenantMembership::with('tenant')->whereKey($d['membership_id'])->where('user_id',$r->user()->getAuthIdentifier())->where('status','active')->first();
  if (!$m || $m->tenant->status!=='active') return response()->json(['message'=>'Membership is not available to this account.'],403);
  if (!$r->hasSession()) return response()->json(['message'=>'Select tenant context through an authenticated first-party session.'],409);
  $r->session()->put('active_tenant_membership_id',$m->id);
  $m->forceFill(['last_used_at'=>now()])->save();
  $audit->record('auth.tenant_selected',$m,[],['membership_id'=>$m->id],(int)$m->tenant_id);
  return response()->json(['membership_id'=>$m->id,'tenant'=>['id'=>$m->tenant->id,'code'=>$m->tenant->code,'name'=>$m->tenant->name]]);
 }
}
