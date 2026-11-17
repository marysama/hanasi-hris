<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\EmployeeEmergencyContact;
use App\Models\EmployeeFamilyMember;
use App\Models\EmployeeHistory;
use App\Models\LegalEntity;
use App\Models\OrgNode;
use App\Services\EmployeeHistoryRecorder;
use App\Services\EmployeeManagementService;
use App\Services\EmployeeNumberService;
use App\Services\OrganizationHierarchyService;
use App\Services\TenantContext;
use App\Services\TenantScopeService;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class EmployeeController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantScopeService $scopes,
        private readonly EmployeeManagementService $employees,
        private readonly OrganizationHierarchyService $hierarchy,
        private readonly EmployeeHistoryRecorder $history,
        private readonly EmployeeNumberService $numbers,
    ) {}

    public function index(Request $request)
    {
        $filters=$request->validate(['status'=>['nullable',Rule::in(['active','inactive','resigned','terminated'])],'search'=>['nullable','string','max:100']]);
        $query=$this->employeeQuery()->with(['legalEntity:id,tenant_id,code,legal_name','department:id,tenant_id,code,name','position:id,tenant_id,code,name']);
        if(!empty($filters['status'])) $query->where('status',$filters['status']);
        if(!empty($filters['search'])) {
            $search=trim($filters['search']);
            $query->where(fn($q)=>$q->where('employee_number','like','%'.$search.'%')->orWhere('full_name','like','%'.$search.'%'));
        }
        return response()->json($query->orderBy('employee_number')->paginate(50));
    }

    public function legalEntities()
    {
        $rows = $this->scopes->constrain(LegalEntity::query(), $this->context->membership(), 'legal_entity')
            ->where('tenant_id', $this->tenantId())->where('status', 'active')
            ->orderBy('legal_name')->get(['id', 'code', 'legal_name', 'display_name']);
        return response()->json(['data' => $rows]);
    }

    public function store(Request $request)
    {
        $data=$this->validated($request,$this->employeeRules(false));
        $data['status']=$data['status']??'active'; $data['exit_date']=$data['exit_date']??null;
        $employee=DB::transaction(function() use($data) {
            $this->hierarchy->lockTenantOrganization($this->tenantId());
            $this->validateStatusDates($data);
            $this->employees->validateProfile($this->tenantId(),$data,$this->context->membership());
            $data['employee_number'] ??= $this->numbers->next($this->tenantId());
            $employee=Employee::create($data+['tenant_id'=>$this->tenantId()]);
            $after=$this->snapshot($employee,array_keys($data));
            $this->history->record($employee,'created',$employee,[],$after);
            return $employee->load(['legalEntity:id,tenant_id,code,legal_name','department:id,tenant_id,code,name','position:id,tenant_id,code,name']);
        });
        return response()->json($employee,201);
    }

    public function show(int $employee)
    {
        return response()->json($this->findEmployee($employee)->load(['legalEntity:id,tenant_id,code,legal_name','department:id,tenant_id,code,name','position:id,tenant_id,code,name']));
    }

    public function update(Request $request,int $employee)
    {
        $data=$this->validated($request,$this->employeeRules(true));
        if(!$data) throw ValidationException::withMessages(['employee'=>['Tidak ada perubahan yang dikirim.']]);
        return DB::transaction(function() use($employee,$data) {
            $this->hierarchy->lockTenantOrganization($this->tenantId());
            $row=$this->employeeQuery()->whereKey($employee)->lockForUpdate()->firstOrFail();
            $merged=array_merge($row->getAttributes(),$data);
            $this->validateStatusDates($merged);
            if(array_intersect(array_keys($data),['legal_entity_id','department_id','position_id','user_id']) || (($data['status']??null)==='active')) {
                $this->employees->validateProfile($this->tenantId(),$merged,$this->context->membership());
            }
            $before=$this->snapshot($row,array_keys($data)); $oldStatus=$row->status;
            $row->fill($data); $row->save(); $row=$row->fresh(); $after=$this->snapshot($row,array_keys($data));
            $event=$oldStatus!==$row->status?'status_changed':(array_intersect(array_keys($data),['legal_entity_id','department_id','position_id','user_id'])?'employment_profile_updated':'profile_updated');
            $this->history->record($row,$event,$row,$before,$after);
            return response()->json($row->load(['legalEntity:id,tenant_id,code,legal_name','department:id,tenant_id,code,name','position:id,tenant_id,code,name']));
        });
    }

    public function deactivate(int $employee)
    {
        $row=$this->findEmployee($employee);
        if($row->status!=='active') return response()->json($row);
        return DB::transaction(function() use($row) {
            $row=$this->employeeQuery()->whereKey($row->id)->lockForUpdate()->firstOrFail();
            if($row->status!=='active') return response()->json($row);
            $before=$this->snapshot($row,['status','exit_date']); $row->status='inactive'; $row->exit_date=now()->toDateString(); $row->save(); $row=$row->fresh();
            $this->history->record($row,'status_changed',$row,$before,$this->snapshot($row,['status','exit_date']));
            return response()->json($row);
        });
    }

    public function history(int $employee)
    {
        $row=$this->findEmployee($employee);
        return response()->json(['data'=>$row->history()->orderByDesc('occurred_at')->orderByDesc('id')->paginate(50)]);
    }

    public function emergencyContacts(int $employee)
    {
        $row=$this->findEmployee($employee);
        return response()->json(['data'=>$row->emergencyContacts()->where('status','active')->orderByDesc('is_primary')->orderBy('id')->get()]);
    }

    public function storeEmergencyContact(Request $request,int $employee)
    {
        $row=$this->findEmployee($employee); $data=$this->validated($request,$this->emergencyRules(false));
        return DB::transaction(function() use($row,$data) {
            $this->lockEmployee($row);
            if($data['is_primary']??false) $this->clearPrimaryContacts($row);
            $contact=EmployeeEmergencyContact::create($data+['tenant_id'=>$row->tenant_id,'employee_id'=>$row->id,'status'=>'active']);
            $this->history->record($row,'emergency_contact_created',$contact,[],$this->snapshot($contact,array_keys($data)));
            return response()->json($contact,201);
        });
    }

    public function updateEmergencyContact(Request $request,int $employee,int $contact)
    {
        $row=$this->findEmployee($employee); $item=$row->emergencyContacts()->whereKey($contact)->where('status','active')->firstOrFail();
        $data=$this->validated($request,$this->emergencyRules(true));
        if(!$data) throw ValidationException::withMessages(['contact'=>['Tidak ada perubahan yang dikirim.']]);
        return DB::transaction(function() use($row,$item,$data) {
            $this->lockEmployee($row); $before=$this->snapshot($item,array_keys($data));
            if($data['is_primary']??false) $this->clearPrimaryContacts($row,(int)$item->id);
            $item->fill($data); $item->save(); $item=$item->fresh();
            $this->history->record($row,'emergency_contact_updated',$item,$before,$this->snapshot($item,array_keys($data)));
            return response()->json($item);
        });
    }

    public function deactivateEmergencyContact(int $employee,int $contact)
    {
        $row=$this->findEmployee($employee); $item=$row->emergencyContacts()->whereKey($contact)->where('status','active')->firstOrFail();
        return $this->deactivateChild($row,$item,'emergency_contact_deactivated',['status','is_primary']);
    }

    public function familyMembers(int $employee)
    {
        $row=$this->findEmployee($employee);
        return response()->json(['data'=>$row->familyMembers()->where('status','active')->orderBy('id')->get()]);
    }

    public function storeFamilyMember(Request $request,int $employee)
    {
        $row=$this->findEmployee($employee); $data=$this->validated($request,$this->familyRules(false));
        return DB::transaction(function() use($row,$data) {
            $this->lockEmployee($row); $item=EmployeeFamilyMember::create($data+['tenant_id'=>$row->tenant_id,'employee_id'=>$row->id,'status'=>'active']);
            $this->history->record($row,'family_member_created',$item,[],$this->snapshot($item,array_keys($data)));
            return response()->json($item,201);
        });
    }

    public function updateFamilyMember(Request $request,int $employee,int $member)
    {
        $row=$this->findEmployee($employee); $item=$row->familyMembers()->whereKey($member)->where('status','active')->firstOrFail();
        $data=$this->validated($request,$this->familyRules(true));
        if(!$data) throw ValidationException::withMessages(['family_member'=>['Tidak ada perubahan yang dikirim.']]);
        return DB::transaction(function() use($row,$item,$data) {
            $this->lockEmployee($row); $before=$this->snapshot($item,array_keys($data)); $item->fill($data); $item->save(); $item=$item->fresh();
            $this->history->record($row,'family_member_updated',$item,$before,$this->snapshot($item,array_keys($data)));
            return response()->json($item);
        });
    }

    public function deactivateFamilyMember(int $employee,int $member)
    {
        $row=$this->findEmployee($employee); $item=$row->familyMembers()->whereKey($member)->where('status','active')->firstOrFail();
        return $this->deactivateChild($row,$item,'family_member_deactivated',['status']);
    }

    public function documents(int $employee)
    {
        $row=$this->findEmployee($employee);
        return response()->json(['data'=>$row->documents()->orderByDesc('created_at')->get()]);
    }

    public function storeDocument(Request $request,int $employee)
    {
        $row=$this->findEmployee($employee);
        $data=$this->validated($request,[
            'file'=>['required','file','mimes:pdf,jpg,jpeg,png','max:10240'],
            'document_type'=>['required','string','max:60'],'document_number'=>['nullable','string','max:100'],
            'issued_at'=>['nullable','date'],'expires_at'=>['nullable','date','after_or_equal:issued_at'],
        ]);
        $file=$data['file']; $directory='employee-documents/'.$row->tenant_id.'/'.$row->id; $name=(string)Str::uuid().'.'.$file->extension();
        $path=$file->storeAs($directory,$name,'local');
        if(!$path) abort(500,'Dokumen gagal disimpan ke private storage.');
        try {
            $document=DB::transaction(function() use($row,$data,$file,$path) {
                $this->lockEmployee($row);
                foreach($row->documents()->where('document_type',$data['document_type'])->where('status','active')->lockForUpdate()->get() as $older) {
                    $before=$this->snapshot($older,['status']); $older->status='superseded'; $older->save(); $older=$older->fresh();
                    $this->history->record($row,'document_superseded',$older,$before,$this->snapshot($older,['status']));
                }
                $document=EmployeeDocument::create([
                    'tenant_id'=>$row->tenant_id,'employee_id'=>$row->id,'uploaded_by_user_id'=>auth()->id(),
                    'document_type'=>$data['document_type'],'document_number'=>$data['document_number']??null,'original_filename'=>$this->safeOriginalName($file->getClientOriginalName()),
                    'storage_disk'=>'local','storage_path'=>$path,'mime_type'=>$file->getMimeType()??'application/octet-stream','file_size_bytes'=>$file->getSize(),
                    'sha256'=>hash_file('sha256',$file->getRealPath()),'issued_at'=>$data['issued_at']??null,'expires_at'=>$data['expires_at']??null,'status'=>'active',
                ]);
                $this->history->record($row,'document_uploaded',$document,[],$this->snapshot($document,['document_type','document_number','original_filename','mime_type','file_size_bytes','issued_at','expires_at','status']));
                return $document;
            });
        } catch(Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }
        return response()->json($document,201);
    }

    public function showDocument(int $employee,int $document)
    {
        return response()->json($this->documentForEmployee($employee,$document));
    }

    public function downloadDocument(int $employee,int $document)
    {
        $item=$this->documentForEmployee($employee,$document);
        abort_unless(Storage::disk($item->storage_disk)->exists($item->storage_path),404);
        return Storage::disk($item->storage_disk)->download($item->storage_path,$item->original_filename);
    }

    public function updateDocument(Request $request,int $employee,int $document)
    {
        $item=$this->documentForEmployee($employee,$document);
        $data=$this->validated($request,[
            'document_type'=>['sometimes','required','string','max:60'],'document_number'=>['sometimes','nullable','string','max:100'],
            'issued_at'=>['sometimes','nullable','date'],'expires_at'=>['sometimes','nullable','date','after_or_equal:issued_at'],
        ]);
        if(!$data) throw ValidationException::withMessages(['document'=>['Tidak ada perubahan yang dikirim.']]);
        return DB::transaction(function() use($item,$data) {
            $employee=$item->employee; $this->lockEmployee($employee);
            $item=EmployeeDocument::where('tenant_id',$employee->tenant_id)->where('employee_id',$employee->id)->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $before=$this->snapshot($item,array_keys($data)); $item->fill($data); $item->save(); $item=$item->fresh();
            $this->history->record($item->employee,'document_metadata_updated',$item,$before,$this->snapshot($item,array_keys($data)));
            return response()->json($item);
        });
    }

    public function revokeDocument(int $employee,int $document)
    {
        $item=$this->documentForEmployee($employee,$document);
        if($item->status==='revoked') return response()->json($item);
        return DB::transaction(function() use($item) {
            $employee=$item->employee; $this->lockEmployee($employee);
            $item=EmployeeDocument::where('tenant_id',$employee->tenant_id)->where('employee_id',$employee->id)->whereKey($item->id)->lockForUpdate()->firstOrFail();
            if($item->status==='revoked') return response()->json($item);
            $before=$this->snapshot($item,['status']); $item->status='revoked'; $item->save(); $item=$item->fresh();
            $this->history->record($item->employee,'document_revoked',$item,$before,$this->snapshot($item,['status']));
            return response()->json($item);
        });
    }

    private function employeeQuery() { return $this->scopes->constrain(Employee::query(),$this->context->membership(),'employee')->where('tenant_id',$this->tenantId()); }
    private function findEmployee(int $id): Employee { return $this->employeeQuery()->whereKey($id)->firstOrFail(); }
    private function documentForEmployee(int $employee,int $document): EmployeeDocument { $row=$this->findEmployee($employee); return $row->documents()->whereKey($document)->firstOrFail(); }
    private function tenantId(): int { return $this->context->tenantId(); }
    private function validated(Request $request,array $rules): array { return Validator::make($request->all(),$rules)->validate(); }

    private function employeeRules(bool $partial): array
    {
        $required=$partial?'sometimes|required':'required'; $tenant=$this->tenantId();
        return [
            'tenant_id'=>['prohibited'],'employee_number'=>$partial?['prohibited']:['sometimes','required','string','regex:/^[1-9][0-9]{0,17}$/',Rule::unique('employees','employee_number')->where('tenant_id',$tenant)],
            'user_id'=>['sometimes','nullable','integer','exists:users,id'],
            'legal_entity_id'=>[$required,'integer',Rule::exists('legal_entities','id')->where('tenant_id',$tenant)],
            'department_id'=>['sometimes','nullable','integer',Rule::exists('departments','id')->where('tenant_id',$tenant)],
            'position_id'=>['sometimes','nullable','integer',Rule::exists('positions','id')->where('tenant_id',$tenant)],
            'full_name'=>[$required,'string','max:180'],'gender'=>['sometimes','nullable','string','max:20'],'birth_place'=>['sometimes','nullable','string','max:150'],
            'birth_date'=>['sometimes','nullable','date','before_or_equal:today'],'address'=>['sometimes','nullable','string','max:10000'],
            'phone'=>['sometimes','nullable','string','max:30'],'email'=>['sometimes','nullable','email','max:254'],
            'status'=>['sometimes','required',Rule::in(['active','inactive','resigned','terminated'])],
            'hire_date'=>[$required,'date','before_or_equal:today'],'exit_date'=>['sometimes','nullable','date'],
        ];
    }

    private function validateStatusDates(array $data): void
    {
        $status=$data['status']??'active'; $exit=$data['exit_date']??null; $hire=$data['hire_date']??null;
        if($status==='active' && $exit!==null) throw ValidationException::withMessages(['exit_date'=>['Karyawan aktif tidak boleh memiliki tanggal keluar.']]);
        if($status!=='active' && $exit===null) throw ValidationException::withMessages(['exit_date'=>['Tanggal keluar wajib diisi untuk status nonaktif.']]);
        if($exit!==null && $hire!==null && (string)$exit<(string)$hire) throw ValidationException::withMessages(['exit_date'=>['Tanggal keluar tidak boleh sebelum tanggal masuk.']]);
    }

    private function emergencyRules(bool $partial): array
    {
        $required=$partial?'sometimes|required':'required';
        return ['full_name'=>[$required,'string','max:180'],'relationship'=>[$required,'string','max:80'],'phone'=>[$required,'string','max:30'],
            'email'=>['sometimes','nullable','email','max:254'],'is_primary'=>['sometimes','boolean'],'notes'=>['sometimes','nullable','string','max:2000']];
    }
    private function familyRules(bool $partial): array
    {
        $required=$partial?'sometimes|required':'required';
        return ['full_name'=>[$required,'string','max:180'],'relationship'=>[$required,'string','max:80'],'birth_date'=>['sometimes','nullable','date','before_or_equal:today'],
            'is_dependent'=>['sometimes','boolean'],'phone'=>['sometimes','nullable','string','max:30'],'notes'=>['sometimes','nullable','string','max:2000']];
    }

    private function clearPrimaryContacts(Employee $employee,?int $exceptId=null): void
    {
        $query=$employee->emergencyContacts()->where('status','active')->where('is_primary',true)->when($exceptId,fn($q)=>$q->where('id','<>',$exceptId));
        foreach($query->lockForUpdate()->get() as $old) {
            $before=$this->snapshot($old,['is_primary']); $old->is_primary=false; $old->save();
            $this->history->record($employee,'emergency_contact_primary_changed',$old,$before,$this->snapshot($old,['is_primary']));
        }
    }

    private function deactivateChild(Employee $employee,Model $item,string $event,array $fields)
    {
        return DB::transaction(function() use($employee,$item,$event,$fields) {
            $this->lockEmployee($employee); $before=$this->snapshot($item,$fields); $item->status='inactive';
            if($item instanceof EmployeeEmergencyContact) $item->is_primary=false;
            $item->save(); $item=$item->fresh(); $this->history->record($employee,$event,$item,$before,$this->snapshot($item,$fields));
            return response()->json($item);
        });
    }

    private function lockEmployee(Employee $employee): void
    {
        Employee::where('tenant_id',$employee->tenant_id)->whereKey($employee->id)->lockForUpdate()->firstOrFail();
    }

    private function safeOriginalName(string $filename): string
    {
        $name=basename(str_replace('\\','/',$filename));
        $name=preg_replace('/[\x00-\x1F\x7F"\/]+/u','_',$name)?:'document';
        return mb_substr($name,0,255);
    }

    private function snapshot(Model $model,array $fields): array
    {
        $values=[];
        foreach($fields as $field) {
            $value=$model->getAttribute($field);
            if($value instanceof DateTimeInterface) $value=$value->format('Y-m-d H:i:s');
            $values[$field]=$value;
        }
        return $values;
    }
}
