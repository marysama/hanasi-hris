<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PayrollPeriod;
use App\Services\LeaveService;
use App\Services\TenantContext;
use App\Services\TenantScopeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class LeaveController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantScopeService $scopes,
        private readonly LeaveService $leave,
    ) {}

    public function selfTypes()
    {
        return response()->json(['data' => LeaveType::query()->where('tenant_id', $this->tenantId())->where('status', 'active')
            ->where(fn ($query) => $query->whereNull('attendance_status')->orWhereIn('attendance_status', ['S', 'I']))
            ->orderBy('name')->get(['id', 'code', 'name', 'attendance_status', 'requires_document'])]);
    }

    public function selfRequests()
    {
        $employee = $this->leave->selfEmployee($this->context->membership());
        return response()->json(['data' => LeaveRequest::query()->where('tenant_id', $this->tenantId())->where('employee_id', $employee->id)
            ->with(['type:id,tenant_id,code,name,attendance_status', 'decisions'])
            ->orderByDesc('starts_on')->orderByDesc('id')->paginate(50)]);
    }

    public function submit(Request $request)
    {
        $data = $request->validate([
            'leave_type_id' => ['required', 'integer'], 'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'], 'reason' => ['required', 'string', 'max:2000'],
            'document' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);
        $employee = $this->leave->selfEmployee($this->context->membership());
        $type = LeaveType::query()->where('tenant_id', $this->tenantId())->whereKey($data['leave_type_id'])->firstOrFail();
        return response()->json($this->leave->submit($this->context->membership(), $employee, $type, $data, $data['document'] ?? null), 201);
    }

    public function cancelOwn(int $requestId)
    {
        $employee = $this->leave->selfEmployee($this->context->membership());
        $row = LeaveRequest::query()->where('tenant_id', $this->tenantId())->whereKey($requestId)->firstOrFail();
        return response()->json($this->leave->cancelOwn($this->context->membership(), $employee, $row));
    }

    public function managementTypes()
    {
        $this->requireTenantScope();
        return response()->json(['data' => LeaveType::query()->where('tenant_id', $this->tenantId())->orderBy('name')->get()]);
    }

    public function storeType(Request $request)
    {
        $this->requireTenantScope();
        $data = $request->validate([
            'code' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('leave_types', 'code')->where('tenant_id', $this->tenantId())],
            'name' => ['required', 'string', 'max:120'], 'attendance_status' => ['nullable', Rule::in(['S', 'I', 'A'])],
            'requires_document' => ['required', 'boolean'],
        ]);
        $type = LeaveType::create($data + ['tenant_id' => $this->tenantId(), 'is_system' => false, 'status' => 'active']);
        app(\App\Services\AuditLogger::class)->record('leave.type.created', $type, ['code' => $type->code, 'attendance_status' => $type->attendance_status], [], $this->tenantId());
        return response()->json($type, 201);
    }

    public function managementRequests(Request $request)
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['pending_approval', 'approved', 'rejected', 'cancelled'])],
            'attendance_status' => ['nullable', Rule::in(['S', 'I', 'A'])], 'employee_id' => ['nullable', 'integer'],
        ]);
        $employeeIds = $this->visibleEmployeeIds();
        $query = LeaveRequest::query()->where('tenant_id', $this->tenantId())->whereIn('employee_id', $employeeIds)
            ->with(['employee:id,tenant_id,employee_number,full_name', 'type:id,tenant_id,code,name,attendance_status,requires_document', 'decisions.actorMembership.user']);
        if (!empty($filters['status'])) $query->where('status', $filters['status']);
        if (!empty($filters['employee_id'])) $query->where('employee_id', $filters['employee_id']);
        if (!empty($filters['attendance_status'])) $query->whereHas('type', fn ($type) => $type->where('attendance_status', $filters['attendance_status']));
        return response()->json(['data' => $query->orderByRaw("CASE WHEN status='pending_approval' THEN 0 ELSE 1 END")
            ->orderByDesc('starts_on')->paginate(100)]);
    }

    public function review(Request $request, int $requestId)
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['approve', 'reject'])], 'comment' => ['nullable', 'string', 'max:1000']]);
        $row = LeaveRequest::query()->where('tenant_id', $this->tenantId())->whereKey($requestId)->with('employee')->firstOrFail();
        $this->assertEmployeeVisible((int) $row->employee_id);
        return response()->json($this->leave->decide($this->context->membership(), $row, $data['decision'], $data['comment'] ?? null));
    }

    public function recordAbsence(Request $request)
    {
        $data = $request->validate([
            'employee_id' => ['required', 'integer'], 'leave_type_id' => ['required', 'integer'],
            'starts_on' => ['required', 'date'], 'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);
        $this->assertEmployeeVisible((int) $data['employee_id']);
        $employee = Employee::query()->where('tenant_id', $this->tenantId())->whereKey($data['employee_id'])->where('status', 'active')->firstOrFail();
        $type = LeaveType::query()->where('tenant_id', $this->tenantId())->whereKey($data['leave_type_id'])->where('attendance_status', 'A')->firstOrFail();
        return response()->json($this->leave->recordAbsence($this->context->membership(), $employee, $type, $data), 201);
    }

    public function createPayrollAdjustment(Request $request, int $leaveRequest)
    {
        $data = $request->validate(['payroll_period_id' => ['required', 'integer']]);
        $row = LeaveRequest::query()->where('tenant_id', $this->tenantId())->whereKey($leaveRequest)->with('type')->firstOrFail();
        $this->assertEmployeeVisible((int) $row->employee_id);
        PayrollPeriod::query()->where('tenant_id', $this->tenantId())->whereKey($data['payroll_period_id'])->firstOrFail();
        return response()->json($this->leave->createPayrollAdjustment($this->context->membership(), $row, (int) $data['payroll_period_id']), 201);
    }

    public function selfDocument(int $requestId)
    {
        $employee = $this->leave->selfEmployee($this->context->membership());
        $row = LeaveRequest::query()->where('tenant_id', $this->tenantId())->where('employee_id', $employee->id)->whereKey($requestId)->firstOrFail();
        return $this->documentResponse($row);
    }

    public function managementDocument(int $requestId)
    {
        $row = LeaveRequest::query()->where('tenant_id', $this->tenantId())->whereKey($requestId)->firstOrFail();
        $this->assertEmployeeVisible((int) $row->employee_id);
        return $this->documentResponse($row);
    }

    private function documentResponse(LeaveRequest $row)
    {
        abort_unless($row->supporting_storage_path && Storage::disk('local')->exists($row->supporting_storage_path), 404);
        app(\App\Services\AuditLogger::class)->record('leave.document.viewed', $row, [], ['employee_id' => $row->employee_id], $this->tenantId());
        return Storage::disk('local')->response($row->supporting_storage_path, $row->supporting_original_filename, [
            'Cache-Control' => 'private, no-store, max-age=0', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function visibleEmployeeIds(): array
    {
        return $this->scopes->constrain(Employee::query(), $this->context->membership(), 'employee')
            ->where('tenant_id', $this->tenantId())->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function assertEmployeeVisible(int $id): void
    {
        abort_unless($this->scopes->allows($this->context->membership(), 'employee', $id), 403);
    }

    private function requireTenantScope(): void
    {
        abort_unless($this->scopes->hasTenantAccess($this->context->membership()), 403);
    }

    private function tenantId(): int { return $this->context->tenantId(); }
}
