<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClientAccount;
use App\Models\Department;
use App\Models\OrgNode;
use App\Models\Position;
use App\Models\TenantMembership;
use App\Models\UserScopeGrant;
use App\Models\WorkLocation;
use App\Services\AuditLogger;
use App\Services\OrganizationHierarchyService;
use App\Services\TenantContext;
use App\Services\TenantScopeService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class OrganizationController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantScopeService $scopes,
        private readonly OrganizationHierarchyService $hierarchy,
        private readonly AuditLogger $audit,
    ) {}

    public function indexClients() { return $this->listing(ClientAccount::class, 'client'); }
    public function showClient(int $client) { return $this->show(ClientAccount::class, 'client', $client); }
    public function storeClient(Request $request) {
        $data=$this->validated($request,[
            'code'=>['required','string','max:50',Rule::unique('client_accounts','code')->where('tenant_id',$this->tenantId())],
            'legal_name'=>['required','string','max:180'],'display_name'=>['nullable','string','max:180'],
        ]);
        $this->requireTenantScope();
        $row=DB::transaction(function() use($data) {
            $row=ClientAccount::create($data+['tenant_id'=>$this->tenantId(),'status'=>'active']);
            $this->audit->record('organization.client.created',$row,['after'=>$row->only(['code','legal_name','display_name','status'])]);
            return $row;
        });
        return response()->json($row,201);
    }
    public function updateClient(Request $request,int $client) {
        $row=$this->find(ClientAccount::class,'client',$client);
        $data=$this->validated($request,[
            'code'=>['sometimes','required','string','max:50',Rule::unique('client_accounts','code')->where('tenant_id',$this->tenantId())->ignore($row->id)],
            'legal_name'=>['sometimes','required','string','max:180'],'display_name'=>['sometimes','nullable','string','max:180'],
            'status'=>['sometimes','required',Rule::in(['active','inactive'])],
        ]);
        if(($data['status']??null)==='inactive' && $row->workLocations()->where('status','active')->exists()) throw ValidationException::withMessages(['status'=>['Nonaktifkan Work Location aktif milik Client ini terlebih dahulu.']]);
        return $this->updateRow($row,$data,'organization.client.updated');
    }
    public function deactivateClient(int $client) {
        $row=$this->find(ClientAccount::class,'client',$client);
        if($row->workLocations()->where('status','active')->exists()) throw ValidationException::withMessages(['status'=>['Nonaktifkan Work Location aktif milik Client ini terlebih dahulu.']]);
        return $this->deactivateModel($row,'organization.client.deactivated');
    }

    public function indexOrgNodes() { return $this->listing(OrgNode::class, 'org_node'); }
    public function showOrgNode(int $orgNode) { return $this->show(OrgNode::class, 'org_node', $orgNode); }
    public function storeOrgNode(Request $request) {
        $data=$this->validated($request,[
            'node_type'=>['required',Rule::in(['company','branch','zone','area','building'])],
            'parent_id'=>['nullable','integer'],'legal_entity_id'=>['nullable','integer'],
            'code'=>['required','string','max:50',Rule::unique('org_nodes','code')->where('tenant_id',$this->tenantId())],
            'name'=>['required','string','max:180'],
        ]);
        $row=DB::transaction(function() use($data) {
            $this->hierarchy->lockTenantOrganization($this->tenantId());
            $this->authorizeNodePlacement($data['parent_id']??null,$data['legal_entity_id']??null);
            $this->hierarchy->validateOrgNode($this->tenantId(),$data['node_type'],$data['parent_id']??null,$data['legal_entity_id']??null);
            $row=OrgNode::create($data+['tenant_id'=>$this->tenantId(),'status'=>'active']);
            $this->audit->record('organization.org_node.created',$row,['after'=>$row->only(['node_type','parent_id','legal_entity_id','code','name','status'])]);
            return $row;
        });
        return response()->json($row,201);
    }
    public function updateOrgNode(Request $request,int $orgNode) {
        $data=$this->validated($request,[
            'node_type'=>['sometimes','required',Rule::in(['company','branch','zone','area','building'])],
            'parent_id'=>['sometimes','nullable','integer'],'legal_entity_id'=>['sometimes','nullable','integer'],
            'code'=>['sometimes','required','string','max:50',Rule::unique('org_nodes','code')->where('tenant_id',$this->tenantId())->ignore($orgNode)],
            'name'=>['sometimes','required','string','max:180'],'status'=>['sometimes','required',Rule::in(['active','inactive'])],
        ]);
        return DB::transaction(function() use($data,$orgNode) {
            $this->hierarchy->lockTenantOrganization($this->tenantId());
            $row=$this->find(OrgNode::class,'org_node',$orgNode); $before=$row->only(['node_type','parent_id','legal_entity_id','code','name','status']);
            $type=$data['node_type']??$row->node_type; $parent=array_key_exists('parent_id',$data)?$data['parent_id']:$row->parent_id; $entity=array_key_exists('legal_entity_id',$data)?$data['legal_entity_id']:$row->legal_entity_id;
            $placementChanged=array_key_exists('node_type',$data)||array_key_exists('parent_id',$data)||array_key_exists('legal_entity_id',$data);
            if($placementChanged) $this->authorizeNodePlacement($parent,$entity);
            if($placementChanged||($data['status']??null)==='active') {
                $this->hierarchy->validateOrgNode($this->tenantId(),$type,$parent,$entity,(int)$row->id);
                if($type!==$row->node_type) $this->hierarchy->validateChildrenForTypeChange($row,$type);
            }
            if(($data['status']??null)==='inactive') $this->hierarchy->validateNodeDeactivation($row);
            $row->fill($data); $row->save();
            $this->audit->record('organization.org_node.updated',$row,['before'=>$before,'after'=>$row->only(array_keys($data))]);
            return response()->json($row->fresh());
        });
    }
    public function deactivateOrgNode(int $orgNode) {
        return DB::transaction(function() use($orgNode) {
            $this->hierarchy->lockTenantOrganization($this->tenantId());
            $row=$this->find(OrgNode::class,'org_node',$orgNode); $this->hierarchy->validateNodeDeactivation($row);
            return $this->deactivateModel($row,'organization.org_node.deactivated');
        });
    }

    public function indexWorkLocations() { return $this->listing(WorkLocation::class, 'work_location'); }
    public function showWorkLocation(int $workLocation) { return $this->show(WorkLocation::class, 'work_location', $workLocation); }
    public function storeWorkLocation(Request $request) {
        $data=$this->validated($request,$this->locationRules());
        $this->validateLocationPair($data['latitude']??null,$data['longitude']??null);
        $this->authorizeLocationLinks($data['client_account_id']??null,$data['org_node_id']??null);
        $this->validateActiveLocationLinks($data['client_account_id']??null,$data['org_node_id']??null);
        $row=DB::transaction(function() use($data) {
            $row=WorkLocation::create($data+['tenant_id'=>$this->tenantId(),'status'=>'active']);
            $this->audit->record('organization.work_location.created',$row,['after'=>$row->only(['client_account_id','org_node_id','code','name','address','latitude','longitude','attendance_radius_meters','status'])]);
            return $row;
        });
        return response()->json($row,201);
    }
    public function updateWorkLocation(Request $request,int $workLocation) {
        $row=$this->find(WorkLocation::class,'work_location',$workLocation); $before=$row->only(['client_account_id','org_node_id','code','name','address','latitude','longitude','attendance_radius_meters','status']);
        $rules=$this->locationRules(true); $data=$this->validated($request,$rules);
        $client=array_key_exists('client_account_id',$data)?$data['client_account_id']:$row->client_account_id;
        $node=array_key_exists('org_node_id',$data)?$data['org_node_id']:$row->org_node_id;
        if(array_key_exists('client_account_id',$data)||array_key_exists('org_node_id',$data)) $this->authorizeLocationLinks($client,$node);
        $latitude=array_key_exists('latitude',$data)?$data['latitude']:$row->latitude;
        $longitude=array_key_exists('longitude',$data)?$data['longitude']:$row->longitude;
        $this->validateLocationPair($latitude,$longitude);
        if(($data['status']??null)==='active'||array_key_exists('client_account_id',$data)||array_key_exists('org_node_id',$data)) $this->validateActiveLocationLinks($client,$node);
        return $this->updateRow($row,$data,'organization.work_location.updated',$before);
    }
    public function deactivateWorkLocation(int $workLocation) { return $this->deactivate(WorkLocation::class,'work_location',$workLocation,'organization.work_location.deactivated'); }

    public function indexDepartments() { return $this->listing(Department::class, 'department'); }
    public function showDepartment(int $department) { return $this->show(Department::class, 'department', $department); }
    public function storeDepartment(Request $request) {
        $data=$this->validated($request,[
            'org_node_id'=>['nullable','integer'],'code'=>['required','string','max:50',Rule::unique('departments','code')->where('tenant_id',$this->tenantId())],
            'name'=>['required','string','max:180'],
        ]);
        $row=DB::transaction(function() use($data) {
            $this->hierarchy->lockTenantOrganization($this->tenantId());
            if(!empty($data['org_node_id'])) $this->requireAllowed('org_node',(int)$data['org_node_id']); else $this->requireTenantScope();
            $row=Department::create($data+['tenant_id'=>$this->tenantId(),'status'=>'active']);
            $this->audit->record('organization.department.created',$row,['after'=>$row->only(['org_node_id','code','name','status'])]);
            return $row;
        });
        return response()->json($row,201);
    }
    public function updateDepartment(Request $request,int $department) {
        $row=$this->find(Department::class,'department',$department); $before=$row->only(['org_node_id','code','name','status']);
        $data=$this->validated($request,[
            'org_node_id'=>['sometimes','nullable','integer'],'code'=>['sometimes','required','string','max:50',Rule::unique('departments','code')->where('tenant_id',$this->tenantId())->ignore($row->id)],
            'name'=>['sometimes','required','string','max:180'],'status'=>['sometimes','required',Rule::in(['active','inactive'])],
        ]);
        return DB::transaction(function() use($row,$data) {
            $this->hierarchy->lockTenantOrganization($this->tenantId());
            $row=$this->find(Department::class,'department',(int)$row->id); $before=$row->only(['org_node_id','code','name','status']);
            if($row->org_node_id===null && !$this->scopes->hasTenantAccess($this->context->membership())) abort(Response::HTTP_FORBIDDEN);
            if(array_key_exists('org_node_id',$data)) { if($data['org_node_id']) $this->requireAllowed('org_node',(int)$data['org_node_id']); else $this->requireTenantScope(); }
            if(($data['status']??null)==='active' && $row->org_node_id!==null && !OrgNode::where('tenant_id',$this->tenantId())->whereKey($row->org_node_id)->where('status','active')->exists()) throw ValidationException::withMessages(['status'=>['Org Node harus aktif sebelum Department diaktifkan.']]);
            $row->fill($data); $row->save(); $this->audit->record('organization.department.updated',$row,['before'=>$before,'after'=>$row->only(array_keys($data))]);
            return response()->json($row->fresh());
        });
    }
    public function deactivateDepartment(int $department) {
        return DB::transaction(function() use($department) {
            $this->hierarchy->lockTenantOrganization($this->tenantId());
            $row=$this->find(Department::class,'department',$department);
            if($row->org_node_id===null) $this->requireTenantScope();
            return $this->deactivateModel($row,'organization.department.deactivated');
        });
    }

    public function indexPositions() { return $this->listing(Position::class, 'position'); }
    public function showPosition(int $position) { return $this->show(Position::class, 'position', $position); }
    public function storePosition(Request $request) {
        $data=$this->validated($request,[
            'code'=>['required','string','max:50',Rule::unique('positions','code')->where('tenant_id',$this->tenantId())],
            'name'=>['required','string','max:180'],'description'=>['nullable','string','max:500'],'reports_to_position_id'=>['nullable','integer'],
        ]);
        $row=DB::transaction(function() use($data) {
            $this->hierarchy->lockTenantOrganization($this->tenantId());
            $this->requireTenantScope();
            $this->hierarchy->validatePositionReporting($this->tenantId(),null,$data['reports_to_position_id']??null);
            $row=Position::create($data+['tenant_id'=>$this->tenantId(),'status'=>'active']);
            $this->audit->record('organization.position.created',$row,['after'=>$row->only(['code','name','description','reports_to_position_id','status'])]);
            return $row;
        });
        return response()->json($row,201);
    }
    public function updatePosition(Request $request,int $position) {
        $data=$this->validated($request,[
            'code'=>['sometimes','required','string','max:50',Rule::unique('positions','code')->where('tenant_id',$this->tenantId())->ignore($position)],
            'name'=>['sometimes','required','string','max:180'],'description'=>['sometimes','nullable','string','max:500'],
            'reports_to_position_id'=>['sometimes','nullable','integer'],'status'=>['sometimes','required',Rule::in(['active','inactive'])],
        ]);
        return DB::transaction(function() use($data,$position) {
            $this->hierarchy->lockTenantOrganization($this->tenantId());
            $this->requireTenantScope();
            $row=$this->find(Position::class,'position',$position); $before=$row->only(['code','name','description','reports_to_position_id','status']);
            if(array_key_exists('reports_to_position_id',$data)) $this->hierarchy->validatePositionReporting($this->tenantId(),(int)$row->id,$data['reports_to_position_id']);
            if(($data['status']??null)==='active') $this->hierarchy->validatePositionReporting($this->tenantId(),(int)$row->id,$data['reports_to_position_id']??$row->reports_to_position_id);
            if(($data['status']??null)==='inactive') $this->hierarchy->validatePositionDeactivation($row);
            $row->fill($data); $row->save(); $this->audit->record('organization.position.updated',$row,['before'=>$before,'after'=>$row->only(array_keys($data))]);
            return response()->json($row->fresh());
        });
    }
    public function deactivatePosition(int $position) {
        return DB::transaction(function() use($position) {
            $this->hierarchy->lockTenantOrganization($this->tenantId());
            $this->requireTenantScope();
            $row=$this->find(Position::class,'position',$position); $this->hierarchy->validatePositionDeactivation($row);
            return $this->deactivateModel($row,'organization.position.deactivated');
        });
    }

    public function listMembershipScopes(int $membership) {
        $m=$this->membership($membership);
        if(!$this->scopes->canManageMembership($this->context->membership(),$m,true)) abort(403,'Membership berada di luar scope akses Anda.');
        $rows=$m->scopeGrants()->orderBy('id')->get()->filter(function(UserScopeGrant $grant) {
            $id=$grant->scope_id??$grant->org_node_id??$grant->client_account_id??$grant->work_location_id??$grant->department_id;
            return $grant->scope_type==='tenant'?$this->scopes->hasTenantAccess($this->context->membership()):$this->scopes->allows($this->context->membership(),$grant->scope_type,$id);
        })->values();
        return response()->json(['data'=>$rows]);
    }
    public function grantMembershipScope(Request $request,int $membership) {
        $m=$this->membership($membership);
        if(!$this->scopes->canManageMembership($this->context->membership(),$m,true)) abort(403,'Membership berada di luar scope akses Anda.');
        $data=$this->validated($request,[
            'scope_type'=>['required',Rule::in(['tenant','legal_entity','org_node','client','work_location','department'])],
            'scope_id'=>['nullable','integer','required_if:scope_type,legal_entity','prohibited_unless:scope_type,legal_entity'],
            'org_node_id'=>['nullable','integer','required_if:scope_type,org_node','prohibited_unless:scope_type,org_node'],
            'client_account_id'=>['nullable','integer','required_if:scope_type,client','prohibited_unless:scope_type,client'],
            'work_location_id'=>['nullable','integer','required_if:scope_type,work_location','prohibited_unless:scope_type,work_location'],
            'department_id'=>['nullable','integer','required_if:scope_type,department','prohibited_unless:scope_type,department'],
            'effective_from'=>['nullable','date'],'effective_to'=>['nullable','date','after_or_equal:effective_from'],
        ]);
        $type=$data['scope_type']; $map=['tenant'=>'scope_id','legal_entity'=>'scope_id','org_node'=>'org_node_id','client'=>'client_account_id','work_location'=>'work_location_id','department'=>'department_id']; $target=$map[$type];
        if($type==='tenant') $this->requireTenantScope(); else $this->requireAllowed(match($type){'legal_entity'=>'legal_entity','org_node'=>'org_node','client'=>'client','work_location'=>'work_location','department'=>'department'},(int)$data[$target]);
        $columns=['scope_id','org_node_id','client_account_id','work_location_id','department_id'];
        foreach($columns as $column) if(!array_key_exists($column,$data)) $data[$column]=null;
        $data['tenant_id']=$this->tenantId(); $data['membership_id']=$m->id; $data['granted_by']=auth()->id(); $data['created_at']=now();
        $grant=DB::transaction(function() use($data,$m) {
            $grant=UserScopeGrant::create($data);
            $this->audit->record('access.scope_granted',$grant,['scope_type'=>$grant->scope_type,'target_id'=>$grant->scope_id??$grant->org_node_id??$grant->client_account_id??$grant->work_location_id??$grant->department_id],['membership_id'=>$m->id]);
            return $grant;
        });
        return response()->json($grant,201);
    }
    public function revokeMembershipScope(int $membership,int $grant) {
        $m=$this->membership($membership);
        if(!$this->scopes->canManageMembership($this->context->membership(),$m)) abort(403,'Membership berada di luar scope akses Anda.');
        $row=UserScopeGrant::where('tenant_id',$this->tenantId())->where('membership_id',$m->id)->whereKey($grant)->firstOrFail();
        if($row->scope_type==='tenant') { if(!$this->scopes->hasTenantAccess($this->context->membership())) abort(403); }
        elseif(!$this->scopes->allows($this->context->membership(),$row->scope_type,$row->scope_id??$row->org_node_id??$row->client_account_id??$row->work_location_id??$row->department_id)) abort(403);
        $otherCurrentScope=$m->scopeGrants()->where('id','<>',$row->id)
            ->where(fn($q)=>$q->whereNull('effective_from')->orWhere('effective_from','<=',now()))
            ->where(fn($q)=>$q->whereNull('effective_to')->orWhere('effective_to','>=',now()))->exists();
        if($m->status==='active' && !$otherCurrentScope) throw ValidationException::withMessages(['scope'=>['Membership aktif harus tetap memiliki minimal satu scope yang berlaku.']]);
        $before=['effective_to'=>$row->effective_to?->toIso8601String()]; $row->effective_to=now(); $row->save();
        $this->audit->record('access.scope_revoked',$row,['before'=>$before,'after'=>['effective_to'=>$row->effective_to->toIso8601String()]],['membership_id'=>$m->id]);
        return response()->json(['message'=>'Scope dicabut.','scope'=>$row]);
    }

    private function listing(string $model,string $resource) {
        $rows=$this->scopes->constrain($model::query(),$this->context->membership(),$resource)->orderBy('id')->paginate(50);
        return response()->json($rows);
    }
    private function show(string $model,string $resource,int $id) { return response()->json($this->find($model,$resource,$id)); }
    private function find(string $model,string $resource,int $id): Model {
        return $this->scopes->constrain($model::query(),$this->context->membership(),$resource)->whereKey($id)->firstOrFail();
    }
    private function updateRow(Model $row,array $data,string $action,?array $before=null) {
        $before??=$row->only(array_keys($data));
        $saved=DB::transaction(function() use($row,$data,$action,$before) {
            $row->fill($data); $row->save();
            $after=$row->only(array_keys($data)); $this->audit->record($action,$row,['before'=>$before,'after'=>$after]);
            return $row->fresh();
        });
        return response()->json($saved);
    }
    private function deactivate(string $model,string $resource,int $id,string $action) { return $this->deactivateModel($this->find($model,$resource,$id),$action); }
    private function deactivateModel(Model $row,string $action) {
        if($row->status==='inactive') return response()->json($row);
        return $this->updateRow($row,['status'=>'inactive'],$action,['status'=>'active']);
    }
    private function validated(Request $request,array $rules): array { return Validator::make($request->all(),$rules)->validate(); }
    private function locationRules(bool $partial=false): array {
        $req=$partial?'sometimes|required':'required';
        return [
            'client_account_id'=>['sometimes','nullable','integer'],'org_node_id'=>['sometimes','nullable','integer'],
            'code'=>[$req,'string','max:50',Rule::unique('work_locations','code')->where('tenant_id',$this->tenantId())->ignore(request()->route('workLocation'))],
            'name'=>[$req,'string','max:180'],'address'=>[$req,'string','max:1000'],
            'latitude'=>['sometimes','nullable','numeric','between:-90,90'],'longitude'=>['sometimes','nullable','numeric','between:-180,180'],
            'attendance_radius_meters'=>[$req,'integer','min:1','max:4294967295'],'status'=>['sometimes','required',Rule::in(['active','inactive'])],
        ];
    }
    private function authorizeNodePlacement(?int $parentId,?int $legalEntityId): void {
        if($parentId!==null) $this->requireAllowed('org_node',$parentId);
        if($legalEntityId!==null) $this->requireAllowed('legal_entity',$legalEntityId);
        if($parentId===null && $legalEntityId===null) $this->requireTenantScope();
    }
    private function authorizeLocationLinks(?int $clientId,?int $nodeId): void {
        if($clientId!==null) $this->requireAllowed('client',$clientId);
        if($nodeId!==null) $this->requireAllowed('org_node',$nodeId);
        if($clientId===null && $nodeId===null) $this->requireTenantScope();
    }
    private function validateLocationPair($latitude,$longitude): void {
        if(($latitude===null)!==($longitude===null)) throw ValidationException::withMessages(['latitude'=>['Latitude dan longitude harus diisi bersama-sama atau keduanya dikosongkan.']]);
    }
    private function validateActiveLocationLinks(?int $clientId,?int $nodeId): void {
        if($clientId!==null && !ClientAccount::where('tenant_id',$this->tenantId())->whereKey($clientId)->where('status','active')->exists()) throw ValidationException::withMessages(['client_account_id'=>['Client harus aktif.']]);
        if($nodeId!==null && !OrgNode::where('tenant_id',$this->tenantId())->whereKey($nodeId)->where('status','active')->exists()) throw ValidationException::withMessages(['org_node_id'=>['Org Node harus aktif.']]);
    }
    private function requireAllowed(string $resource,int $id): void { if(!$this->scopes->allows($this->context->membership(),$resource,$id)) abort(Response::HTTP_FORBIDDEN); }
    private function requireTenantScope(): void { if(!$this->scopes->hasTenantAccess($this->context->membership())) abort(Response::HTTP_FORBIDDEN); }
    private function tenantId(): int { return $this->context->tenantId(); }
    private function membership(int $id): TenantMembership {
        return TenantMembership::where('tenant_id',$this->tenantId())->whereKey($id)->firstOrFail();
    }
}
