<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\TenantMembership;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
class AuthController extends Controller {
 public function store(Request $r,AuditLogger $audit): JsonResponse {
  $d=$r->validate(['email'=>['required','email'],'password'=>['required','string'],'membership_id'=>['nullable','integer']]);
  $guard=Auth::guard('web');
  if (!$guard->attempt(['email'=>$d['email'],'password'=>$d['password']])) throw ValidationException::withMessages(['email'=>['Email or password is incorrect.']]);
  Auth::shouldUse('web');
  if (!$r->hasSession()) { $guard->logout(); return response()->json(['message'=>'Use the configured stateful Sanctum frontend.'],409); }
  $r->session()->regenerate();
  $members=TenantMembership::with('tenant')->where('user_id',$guard->id())->where('status','active')->whereHas('tenant',fn($q)=>$q->where('status','active'))->get();
  if ($members->isEmpty()) { $guard->logout(); $r->session()->invalidate(); return response()->json(['message'=>'No active tenant membership exists.'],403); }
  $m=isset($d['membership_id'])?$members->firstWhere('id',(int)$d['membership_id']):($members->count()===1?$members->first():null);
  if (isset($d['membership_id']) && !$m) { $guard->logout(); $r->session()->invalidate(); return response()->json(['message'=>'Membership is not available to this account.'],403); }
  if (!$m) return response()->json(['status'=>'tenant_selection_required','memberships'=>$members->map(fn($x)=>['id'=>$x->id,'tenant'=>['id'=>$x->tenant->id,'code'=>$x->tenant->code,'name'=>$x->tenant->name]])],409);
  $r->session()->put('active_tenant_membership_id',$m->id); $m->forceFill(['last_used_at'=>now()])->save();
  $audit->record('auth.tenant_selected',$m,[],['membership_id'=>$m->id],(int)$m->tenant_id);
  return response()->json(['user'=>['id'=>$guard->id(),'name'=>$guard->user()->name,'email'=>$guard->user()->email],'membership_id'=>$m->id,'tenant'=>['id'=>$m->tenant->id,'code'=>$m->tenant->code,'name'=>$m->tenant->name]]);
 }
 public function destroy(Request $r): JsonResponse {
  $token=$r->user()?->currentAccessToken();
  if ($token instanceof PersonalAccessToken) $token->delete();
  Auth::guard('web')->logout();
  if ($r->hasSession()) { $r->session()->forget('active_tenant_membership_id'); $r->session()->invalidate(); $r->session()->regenerateToken(); }
  Auth::forgetGuards();
  Auth::shouldUse('web');
  return response()->json(['message'=>'Signed out.']);
 }
}
