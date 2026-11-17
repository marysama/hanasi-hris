<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Services\EmployeeAssignmentService;
use App\Services\TenantContext;
use App\Services\TenantScopeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EmployeeAssignmentController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantScopeService $scopes,
        private readonly EmployeeAssignmentService $assignments,
    ) {}

    public function index(Request $request, int $employee)
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['scheduled', 'active', 'ended', 'cancelled'])],
            'assignment_type' => ['nullable', Rule::in(['primary', 'temporary_backup'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $employeeRow = $this->findEmployee($employee);
        $rows = $this->assignments->visibleAssignments($employeeRow, $this->context->membership());
        if (!empty($filters['assignment_type'])) $rows = $rows->where('assignment_type', $filters['assignment_type'])->values();
        if (!empty($filters['status'])) $rows = $rows->filter(fn (EmployeeAssignment $row) => $row->effectiveStatus() === $filters['status'])->values();

        $page = (int) ($filters['page'] ?? 1);
        $perPage = 50;
        return response()->json([
            'data' => $rows->slice(($page - 1) * $perPage, $perPage)->values()->map(fn (EmployeeAssignment $row) => $this->present($row)),
            'meta' => ['current_page' => $page, 'per_page' => $perPage, 'total' => $rows->count(), 'last_page' => max(1, (int) ceil($rows->count() / $perPage))],
        ]);
    }

    public function store(Request $request, int $employee)
    {
        $data = $this->validate($request, false);
        $row = DB::transaction(function () use ($employee, $data) {
            $employeeRow = $this->lockEmployee($employee);
            return $this->assignments->create($employeeRow, $data, $this->context->membership());
        });
        return response()->json($this->present($row), 201);
    }

    public function show(int $employee, int $assignment)
    {
        return response()->json($this->present($this->findAssignment($employee, $assignment)));
    }

    public function update(Request $request, int $employee, int $assignment)
    {
        $data = $this->validate($request, true);
        if (!$data) throw ValidationException::withMessages(['assignment' => ['Kirim setidaknya satu perubahan.']]);
        $row = DB::transaction(function () use ($employee, $assignment, $data) {
            $employeeRow = $this->lockEmployee($employee);
            $row = $this->findAssignmentRow($employeeRow, $assignment, true);
            return $this->assignments->update($row, $employeeRow, $data, $this->context->membership());
        });
        return response()->json($this->present($row));
    }

    public function end(Request $request, int $employee, int $assignment)
    {
        $data = $request->validate(['effective_until' => ['required', 'date_format:Y-m-d\\TH:i:sP']]);
        $row = DB::transaction(function () use ($employee, $assignment, $data) {
            $employeeRow = $this->lockEmployee($employee);
            $row = $this->findAssignmentRow($employeeRow, $assignment, true);
            return $this->assignments->end($row, $employeeRow, $data['effective_until'], $this->context->membership());
        });
        return response()->json($this->present($row));
    }

    public function cancel(int $employee, int $assignment)
    {
        $row = DB::transaction(function () use ($employee, $assignment) {
            $employeeRow = $this->lockEmployee($employee);
            $row = $this->findAssignmentRow($employeeRow, $assignment, true);
            return $this->assignments->cancel($row, $employeeRow, $this->context->membership());
        });
        return response()->json($this->present($row));
    }

    private function validate(Request $request, bool $partial): array
    {
        $required = $partial ? 'sometimes' : 'required';
        return $request->validate([
            'assignment_type' => [$required, Rule::in(['primary', 'temporary_backup'])],
            'legal_entity_id' => ['sometimes', 'integer'],
            'client_account_id' => ['sometimes', 'nullable', 'integer'],
            'work_location_id' => ['sometimes', 'nullable', 'integer'],
            'department_id' => ['sometimes', 'nullable', 'integer'],
            'org_node_id' => ['sometimes', 'nullable', 'integer'],
            'position_id' => ['sometimes', 'nullable', 'integer'],
            'supervisor_employee_id' => ['sometimes', 'nullable', 'integer'],
            'effective_from' => [$required, 'date_format:Y-m-d\\TH:i:sP'],
            'effective_until' => ['sometimes', 'nullable', 'date_format:Y-m-d\\TH:i:sP'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);
    }

    private function findEmployee(int $id): Employee
    {
        return $this->scopes->constrain(Employee::query(), $this->context->membership(), 'employee')->whereKey($id)->firstOrFail();
    }

    private function lockEmployee(int $id): Employee
    {
        $row = $this->scopes->constrain(Employee::query(), $this->context->membership(), 'employee')->whereKey($id)->lockForUpdate()->firstOrFail();
        return $row;
    }

    private function findAssignment(int $employeeId, int $assignmentId): EmployeeAssignment
    {
        $employee = $this->findEmployee($employeeId);
        return $this->findAssignmentRow($employee, $assignmentId);
    }

    private function findAssignmentRow(Employee $employee, int $assignmentId, bool $lock = false): EmployeeAssignment
    {
        $query = EmployeeAssignment::query()->where('tenant_id', $this->context->tenantId())
            ->where('employee_id', $employee->id)->whereKey($assignmentId);
        if ($lock) $query->lockForUpdate();
        $row = $query->with(['legalEntity', 'clientAccount', 'workLocation', 'department', 'orgNode', 'position', 'supervisor'])->firstOrFail();
        if (!$this->assignments->canRead($this->context->membership(), $row)) abort(404);
        return $row;
    }

    private function present(EmployeeAssignment $row): array
    {
        $row->loadMissing(['legalEntity', 'clientAccount', 'workLocation', 'department', 'orgNode', 'position', 'supervisor']);
        return [
            'id' => $row->id,
            'employee_id' => $row->employee_id,
            'assignment_type' => $row->assignment_type,
            'status' => $row->effectiveStatus(),
            'lifecycle_status' => $row->status,
            'legal_entity' => $row->legalEntity ? ['id' => $row->legalEntity->id, 'code' => $row->legalEntity->code, 'name' => $row->legalEntity->legal_name] : null,
            'client' => $row->clientAccount ? ['id' => $row->clientAccount->id, 'code' => $row->clientAccount->code, 'name' => $row->clientAccount->display_name ?: $row->clientAccount->legal_name] : null,
            'work_location' => $row->workLocation ? ['id' => $row->workLocation->id, 'code' => $row->workLocation->code, 'name' => $row->workLocation->name] : null,
            'department' => $row->department ? ['id' => $row->department->id, 'code' => $row->department->code, 'name' => $row->department->name] : null,
            'org_node' => $row->orgNode ? ['id' => $row->orgNode->id, 'type' => $row->orgNode->node_type, 'code' => $row->orgNode->code, 'name' => $row->orgNode->name] : null,
            'position' => $row->position ? ['id' => $row->position->id, 'code' => $row->position->code, 'name' => $row->position->name] : null,
            'supervisor' => $row->supervisor ? ['id' => $row->supervisor->id, 'employee_number' => $row->supervisor->employee_number, 'name' => $row->supervisor->full_name] : null,
            'effective_from' => $row->effective_from->toIso8601String(),
            'effective_until' => $row->effective_until?->toIso8601String(),
            'end_scheduled_at' => $row->end_scheduled_at?->toIso8601String(),
            'cancelled_at' => $row->cancelled_at?->toIso8601String(),
            'reason' => $row->reason,
            'created_at' => $row->created_at?->toIso8601String(),
            'updated_at' => $row->updated_at?->toIso8601String(),
        ];
    }
}
