<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeCompensationComponent;
use App\Models\LegalEntity;
use App\Models\PayrollAdjustment;
use App\Models\PayrollAttendanceRule;
use App\Models\PayrollComponentDefinition;
use App\Models\PayrollEarningRequest;
use App\Models\PayrollItem;
use App\Models\PayrollPeriod;
use App\Models\PayrollRun;
use App\Services\AuditLogger;
use App\Services\PayrollService;
use App\Services\TenantContext;
use App\Services\TenantScopeService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PayrollController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantScopeService $scopes,
        private readonly PayrollService $payroll,
        private readonly AuditLogger $audit,
    ) {}

    public function components()
    {
        return response()->json(['data' => PayrollComponentDefinition::query()->where('tenant_id', $this->tenantId())
            ->orderBy('category')->orderBy('name')->get()]);
    }

    public function storeComponent(Request $request)
    {
        $tenant = $this->tenantId();
        $data = $request->validate([
            'code' => ['required', 'string', 'max:60', 'regex:/^[a-z][a-z0-9_]*$/', Rule::unique('payroll_component_definitions', 'code')->where('tenant_id', $tenant)],
            'name' => ['required', 'string', 'max:120'],
            'category' => ['required', Rule::in(['earning', 'deduction'])],
            'calculation_type' => ['required', Rule::in(['fixed_amount', 'percentage_of_base_salary', 'per_day', 'per_hour'])],
        ]);
        return response()->json($this->payroll->createComponent($this->context->membership(), $data), 201);
    }

    public function compensation(int $employee)
    {
        $row = $this->findEmployee($employee);
        $components = EmployeeCompensationComponent::query()->where('tenant_id', $this->tenantId())->where('employee_id', $row->id)
            ->with('definition')->orderByDesc('effective_from')->orderByDesc('id')->paginate(50);
        $this->audit->record('payroll.compensation.viewed', $row, [], ['employee_id' => $row->id], $this->tenantId());
        return response()->json($components);
    }

    public function storeCompensation(Request $request, int $employee)
    {
        $row = $this->findEmployee($employee);
        $data = $request->validate([
            'component_definition_id' => ['required', 'integer', Rule::exists('payroll_component_definitions', 'id')->where('tenant_id', $this->tenantId())->where('is_active', true)],
            'amount' => ['required', 'numeric', 'min:0', 'max:9999999999999.99'],
            'quantity' => ['sometimes', 'numeric', 'gt:0', 'max:99999999.99'],
            'currency_code' => ['sometimes', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'effective_from' => ['required', 'date'],
            'effective_until' => ['sometimes', 'nullable', 'date', 'after_or_equal:effective_from'],
        ]);
        return response()->json($this->payroll->createCompensation($row, $this->context->membership(), $data), 201);
    }

    public function attendanceRules()
    {
        return response()->json(['data' => PayrollAttendanceRule::query()->where('tenant_id', $this->tenantId())
            ->orderBy('attendance_status')->orderByDesc('effective_from')->get()]);
    }

    public function storeAttendanceRule(Request $request)
    {
        $data = $request->validate([
            'attendance_status' => ['required', Rule::in(['S', 'I', 'A'])],
            'deduction_enabled' => ['required', 'boolean'],
            'calculation_type' => ['required', Rule::in(['fixed_amount', 'per_day'])],
            'amount' => ['required', 'numeric', 'min:0', 'max:9999999999999.99'],
            'effective_from' => ['required', 'date'],
        ]);
        return response()->json($this->payroll->createAttendanceRule($this->context->membership(), $data), 201);
    }

    public function periods()
    {
        $entityIds = $this->visibleEntityIds();
        return response()->json(['data' => PayrollPeriod::query()->where('tenant_id', $this->tenantId())->whereIn('legal_entity_id', $entityIds)
            ->with('legalEntity:id,tenant_id,code,legal_name,display_name')->withCount('runs')->orderByDesc('period_start')->paginate(50)]);
    }

    public function storePeriod(Request $request)
    {
        $data = $request->validate([
            'legal_entity_id' => ['required', 'integer', Rule::exists('legal_entities', 'id')->where('tenant_id', $this->tenantId())->where('status', 'active')],
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9._-]+$/'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
        ]);
        return response()->json($this->payroll->createPeriod($this->context->membership(), $data), 201);
    }

    public function adjustments()
    {
        $employeeIds = $this->visibleEmployeeIds();
        return response()->json(['data' => PayrollAdjustment::query()->where('tenant_id', $this->tenantId())->whereIn('employee_id', $employeeIds)
            ->with(['employee:id,tenant_id,employee_number,full_name', 'period:id,tenant_id,code,period_start,period_end', 'component:id,tenant_id,code,name,category'])
            ->orderByDesc('created_at')->paginate(50)]);
    }

    public function storeAdjustment(Request $request)
    {
        $data = $request->validate([
            'payroll_period_id' => ['required', 'integer'],
            'employee_id' => ['required', 'integer'],
            'component_definition_id' => ['required', 'integer'],
            'source_type' => ['required', Rule::in(['manual', 'sickness', 'permission', 'absence', 'loan', 'bpjs', 'tax', 'other'])],
            'attendance_status' => ['required_if:source_type,sickness,permission,absence', 'nullable', Rule::in(['S', 'I', 'A'])],
            'event_date' => ['required_if:source_type,sickness,permission,absence', 'nullable', 'date'],
            'source_reference' => ['required_if:source_type,sickness,permission,absence', 'nullable', 'string', 'max:100', Rule::unique('payroll_adjustments', 'source_reference')->where('tenant_id', $this->tenantId())->where('source_type', $request->input('source_type'))],
            'quantity' => ['sometimes', 'numeric', 'gt:0', 'max:99999999.99'],
            'amount' => ['required_unless:source_type,sickness,permission,absence', 'nullable', 'numeric', 'min:0', 'max:9999999999999.99'],
            'description' => ['required', 'string', 'max:1000'],
        ]);
        $row = $this->payroll->createAdjustment($this->context->membership(), $data);
        return response()->json($row, 201);
    }

    public function decideAdjustment(Request $request, int $adjustment)
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['approve', 'reject'])], 'comment' => ['nullable', 'string', 'max:1000']]);
        if ($data['decision'] === 'reject' && empty($data['comment'])) throw ValidationException::withMessages(['comment' => ['Alasan penolakan wajib diisi.']]);
        $row = PayrollAdjustment::query()->where('tenant_id', $this->tenantId())->whereKey($adjustment)->firstOrFail();
        return response()->json($this->payroll->reviewAdjustment($this->context->membership(), $row, $data['decision'], $data['comment'] ?? null));
    }

    public function earningRequests()
    {
        $employeeIds = $this->visibleEmployeeIds();
        return response()->json(['data' => PayrollEarningRequest::query()->where('tenant_id', $this->tenantId())->whereIn('employee_id', $employeeIds)
            ->with(['employee:id,tenant_id,employee_number,full_name', 'period:id,tenant_id,code,period_start,period_end', 'assignment:id,tenant_id,work_location_id', 'backupAssignment:id,tenant_id,work_location_id', 'component:id,tenant_id,code,name'])
            ->orderByDesc('created_at')->paginate(50)]);
    }

    public function storeEarningRequest(Request $request)
    {
        $data = $request->validate([
            'payroll_period_id' => ['required', 'integer'],
            'employee_id' => ['required', 'integer'],
            'assignment_id' => ['required', 'integer'],
            'source_type' => ['required', Rule::in(['backup', 'overtime'])],
            'backup_assignment_id' => ['required_if:source_type,backup', 'nullable', 'integer'],
            'overtime_band' => ['required_if:source_type,overtime', 'nullable', Rule::in(['ordinary', 'night'])],
            'starts_at' => ['required', 'date_format:Y-m-d\\TH:i:sP'],
            'ends_at' => ['required', 'date_format:Y-m-d\\TH:i:sP', 'after:starts_at'],
            'break_minutes' => ['sometimes', 'integer', 'min:0', 'max:1440'],
            'hourly_rate' => ['required', 'numeric', 'gt:0', 'max:9999999999999.99'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);
        return response()->json($this->payroll->createEarningRequest($this->context->membership(), $data), 201);
    }

    public function decideEarningRequest(Request $request, int $earning)
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['approve', 'reject'])], 'comment' => ['nullable', 'string', 'max:1000']]);
        if ($data['decision'] === 'reject' && empty($data['comment'])) throw ValidationException::withMessages(['comment' => ['Alasan penolakan wajib diisi.']]);
        $row = PayrollEarningRequest::query()->where('tenant_id', $this->tenantId())->whereKey($earning)->firstOrFail();
        return response()->json($this->payroll->reviewEarningRequest($this->context->membership(), $row, $data['decision'], $data['comment'] ?? null));
    }

    public function runs()
    {
        $entityIds = $this->visibleEntityIds();
        return response()->json(['data' => PayrollRun::query()->where('tenant_id', $this->tenantId())
            ->whereHas('period', fn ($query) => $query->whereIn('legal_entity_id', $entityIds))
            ->with('period:id,tenant_id,legal_entity_id,code,period_start,period_end')->withCount('items')->orderByDesc('created_at')->paginate(50)]);
    }

    public function createRun(int $period)
    {
        $row = PayrollPeriod::query()->where('tenant_id', $this->tenantId())->whereKey($period)->firstOrFail();
        return response()->json($this->payroll->createRun($this->context->membership(), $row), 201);
    }

    public function showRun(int $run)
    {
        $row = PayrollRun::query()->where('tenant_id', $this->tenantId())->whereKey($run)
            ->with(['period.legalEntity', 'items.lines', 'decisions.actorMembership.user'])->firstOrFail();
        $this->assertRunVisible($row);
        return response()->json($row);
    }

    public function createRevision(Request $request, int $run)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $row = PayrollRun::query()->where('tenant_id', $this->tenantId())->whereKey($run)->firstOrFail();
        return response()->json($this->payroll->createRevision($this->context->membership(), $row, $data['reason']), 201);
    }

    public function transitionRun(Request $request, int $run, string $action)
    {
        $data = $request->validate(['comment' => ['nullable', 'string', 'max:1000']]);
        if ($action === 'reject' && empty($data['comment'])) throw ValidationException::withMessages(['comment' => ['Alasan penolakan wajib diisi.']]);
        $row = PayrollRun::query()->where('tenant_id', $this->tenantId())->whereKey($run)->firstOrFail();
        return response()->json($this->payroll->transitionRun($this->context->membership(), $row, $action, $data['comment'] ?? null));
    }

    public function managementPayslips()
    {
        return response()->json(['data' => $this->visiblePayslips()->with('run.period:id,tenant_id,code,period_start,period_end')->orderByDesc('payslip_generated_at')->paginate(50)]);
    }

    public function managementPayslip(int $item)
    {
        return response()->json($this->findVisiblePayslip($item));
    }

    public function downloadManagementPayslip(int $item)
    {
        return $this->download($this->findVisiblePayslip($item));
    }

    public function myPayslips()
    {
        $employee = $this->selfEmployee();
        return response()->json(['data' => PayrollItem::query()->where('tenant_id', $this->tenantId())->where('employee_id', $employee->id)
            ->whereNotNull('payslip_generated_at')->whereHas('run', fn ($query) => $query->whereIn('status', ['finalized', 'superseded']))
            ->with('run.period:id,tenant_id,code,period_start,period_end')->orderByDesc('payslip_generated_at')->paginate(50)]);
    }

    public function myPayslip(int $item)
    {
        return response()->json($this->findOwnPayslip($item));
    }

    public function downloadMyPayslip(int $item)
    {
        return $this->download($this->findOwnPayslip($item));
    }

    private function download(PayrollItem $item)
    {
        abort_unless($item->payslip_snapshot !== null, 404);
        $snapshot = $item->payslip_snapshot;
        $this->audit->record('payroll.payslip.downloaded', $item, [], ['employee_id' => $item->employee_id, 'payroll_run_id' => $item->payroll_run_id], $this->tenantId());
        $filename = 'payslip-' . $item->employee_number_snapshot . '-' . ($snapshot['payroll']['period_code'] ?? 'period') . '.html';
        return response()->view('payslips.download', ['payslip' => $snapshot], 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function findVisiblePayslip(int $id): PayrollItem
    {
        $item = $this->visiblePayslips()->whereKey($id)->with('run.period')->firstOrFail();
        $this->audit->record('payroll.payslip.viewed', $item, [], ['employee_id' => $item->employee_id, 'payroll_run_id' => $item->payroll_run_id], $this->tenantId());
        return $item;
    }

    private function findOwnPayslip(int $id): PayrollItem
    {
        $employee = $this->selfEmployee();
        $item = PayrollItem::query()->where('tenant_id', $this->tenantId())->where('employee_id', $employee->id)->whereKey($id)
            ->whereNotNull('payslip_generated_at')->whereHas('run', fn ($query) => $query->whereIn('status', ['finalized', 'superseded']))
            ->with('run.period')->firstOrFail();
        $this->audit->record('payroll.payslip.viewed', $item, [], ['employee_id' => $employee->id, 'payroll_run_id' => $item->payroll_run_id], $this->tenantId());
        return $item;
    }

    private function visiblePayslips()
    {
        return PayrollItem::query()->where('tenant_id', $this->tenantId())->whereIn('employee_id', $this->visibleEmployeeIds())
            ->whereNotNull('payslip_generated_at')->whereHas('run', fn ($query) => $query->whereIn('status', ['finalized', 'superseded']));
    }

    private function visibleEmployeeIds(): array
    {
        return $this->scopes->constrain(Employee::query(), $this->context->membership(), 'employee')->where('tenant_id', $this->tenantId())->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function visibleEntityIds(): array
    {
        return $this->scopes->constrain(LegalEntity::query(), $this->context->membership(), 'legal_entity')->where('tenant_id', $this->tenantId())->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function assertRunVisible(PayrollRun $run): void
    {
        foreach ($run->items as $item) if (!$this->scopes->allows($this->context->membership(), 'employee', (int) $item->employee_id)) abort(403);
    }

    private function findEmployee(int $id): Employee
    {
        return $this->scopes->constrain(Employee::query(), $this->context->membership(), 'employee')
            ->where('tenant_id', $this->tenantId())->whereKey($id)->firstOrFail();
    }

    private function selfEmployee(): Employee
    {
        return Employee::query()->where('tenant_id', $this->tenantId())->where('user_id', $this->context->membership()->user_id)->firstOrFail();
    }

    private function tenantId(): int { return $this->context->tenantId(); }
}
