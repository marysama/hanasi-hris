<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceGpsExemption;
use App\Models\AttendanceLocationPolicy;
use App\Models\AttendancePhoto;
use App\Models\AttendanceRecord;
use App\Models\AttendanceShift;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\EmployeeShiftAssignment;
use App\Models\LeaveRequest;
use App\Models\WorkLocation;
use App\Services\AttendanceService;
use App\Services\TenantContext;
use App\Services\TenantScopeService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class AttendanceController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantScopeService $scopes,
        private readonly AttendanceService $attendance,
    ) {}

    public function employeeProfile()
    {
        $employee = $this->attendance->selfEmployee($this->context->membership());
        $now = CarbonImmutable::now(config('app.timezone'));
        $assignments = $this->attendance->eligibleAssignments($employee, $now);
        $today = AttendanceRecord::query()->where('tenant_id', $this->tenantId())->where('employee_id', $employee->id)
            ->where(fn ($query) => $query->whereDate('attendance_date', $now->toDateString())->orWhereNull('check_out_at'))
            ->with('photos')->orderByDesc('attendance_date')->first();
        return response()->json([
            'employee' => $employee->only(['id', 'employee_number', 'full_name', 'status']),
            'department' => $employee->department?->only(['id', 'code', 'name']),
            'position' => $employee->position?->only(['id', 'code', 'name']),
            'assignments' => $assignments,
            'attendance_today' => $today,
        ]);
    }

    public function selfAssignments()
    {
        $employee = $this->attendance->selfEmployee($this->context->membership());
        $at = CarbonImmutable::now(config('app.timezone'));
        return response()->json(['data' => $this->attendance->eligibleAssignments($employee, $at)]);
    }

    public function myAttendance(Request $request)
    {
        $employee = $this->attendance->selfEmployee($this->context->membership());
        $filters = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $query = AttendanceRecord::query()->where('tenant_id', $this->tenantId())->where('employee_id', $employee->id)
            ->with(['photos:id,tenant_id,attendance_record_id,event_type,original_filename,mime_type,file_size_bytes', 'shift:id,tenant_id,name']);
        if (!empty($filters['from'])) $query->whereDate('attendance_date', '>=', $filters['from']);
        if (!empty($filters['to'])) $query->whereDate('attendance_date', '<=', $filters['to']);
        return response()->json(['data' => $query->orderByDesc('attendance_date')->paginate(50)]);
    }

    public function checkIn(Request $request)
    {
        $data = $request->validate([
            'assignment_id' => ['required', 'integer'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'accuracy_meters' => ['nullable', 'numeric', 'gt:0', 'max:100000', 'required_with:latitude'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $employee = $this->attendance->selfEmployee($this->context->membership());
        $assignment = $this->visibleAssignment((int) $data['assignment_id'], $employee);
        $result = $this->attendance->checkIn($this->context->membership(), $employee, (int) $assignment->id,
            array_intersect_key($data, array_flip(['latitude', 'longitude', 'accuracy_meters'])), $data['photo'] ?? null, $data['note'] ?? null);
        return response()->json($result, 201);
    }

    public function checkOut(Request $request, int $record)
    {
        $data = $request->validate([
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'accuracy_meters' => ['nullable', 'numeric', 'gt:0', 'max:100000', 'required_with:latitude'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $employee = $this->attendance->selfEmployee($this->context->membership());
        $attendance = AttendanceRecord::query()->where('tenant_id', $this->tenantId())->where('employee_id', $employee->id)->whereKey($record)->firstOrFail();
        $result = $this->attendance->checkOut($this->context->membership(), $employee, $attendance,
            array_intersect_key($data, array_flip(['latitude', 'longitude', 'accuracy_meters'])), $data['photo'] ?? null, $data['note'] ?? null);
        return response()->json($result);
    }

    public function managementIndex(Request $request)
    {
        $filters = $request->validate([
            'date' => ['nullable', 'date'], 'employee_id' => ['nullable', 'integer'],
            'work_location_id' => ['nullable', 'integer'], 'status' => ['nullable', Rule::in(['present', 'late', 'left_early', 'late_and_left_early'])],
        ]);
        $employeeIds = $this->visibleEmployeeIds();
        $query = AttendanceRecord::query()->where('tenant_id', $this->tenantId())->whereIn('employee_id', $employeeIds)
            ->with(['employee:id,tenant_id,employee_number,full_name', 'photos:id,tenant_id,attendance_record_id,event_type,original_filename,mime_type', 'shift:id,tenant_id,name']);
        $date = $filters['date'] ?? CarbonImmutable::now(config('app.timezone'))->toDateString();
        $query->whereDate('attendance_date', $date);
        if (!empty($filters['employee_id'])) $query->where('employee_id', $filters['employee_id']);
        if (!empty($filters['work_location_id'])) $query->where('work_location_id', $filters['work_location_id']);
        if (!empty($filters['status'])) $query->where('attendance_status', $filters['status']);
        $absenceQuery = LeaveRequest::query()->where('tenant_id', $this->tenantId())->whereIn('employee_id', $employeeIds)
            ->where('status', 'approved')->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date)
            ->whereHas('type', fn ($type) => $type->whereIn('attendance_status', ['S', 'I', 'A']))
            ->with(['employee:id,tenant_id,employee_number,full_name', 'type:id,tenant_id,code,name,attendance_status']);
        if (!empty($filters['employee_id'])) $absenceQuery->where('employee_id', $filters['employee_id']);
        return response()->json([
            'data' => $query->orderBy('check_in_at')->paginate(100),
            'approved_absences' => $absenceQuery->orderBy('employee_id')->orderBy('starts_on')->get(),
            'date' => $date,
        ]);
    }

    public function summary()
    {
        $employeeIds = $this->visibleEmployeeIds();
        $today = CarbonImmutable::now(config('app.timezone'))->toDateString();
        $records = AttendanceRecord::query()->where('tenant_id', $this->tenantId())->whereIn('employee_id', $employeeIds)->whereDate('attendance_date', $today);
        $absenceCount = fn (string $code): int => LeaveRequest::query()->where('tenant_id', $this->tenantId())->whereIn('employee_id', $employeeIds)
            ->where('status', 'approved')->whereDate('starts_on', '<=', $today)->whereDate('ends_on', '>=', $today)
            ->whereHas('type', fn ($type) => $type->where('attendance_status', $code))->count();
        $now = CarbonImmutable::now(config('app.timezone'));
        return response()->json([
            'date' => $today,
            'checked_in' => (clone $records)->count(),
            'late' => (clone $records)->whereIn('attendance_status', ['late', 'late_and_left_early'])->count(),
            'checked_out' => (clone $records)->whereNotNull('check_out_at')->count(),
            'open' => (clone $records)->whereNull('check_out_at')->count(),
            'approved_absences_by_status' => ['S' => $absenceCount('S'), 'I' => $absenceCount('I'), 'A' => $absenceCount('A')],
            'not_checked_in' => $this->missingCheckins($employeeIds, $now),
        ]);
    }

    private function missingCheckins(array $employeeIds, CarbonImmutable $now): array
    {
        if ($employeeIds === []) return [];

        $assignments = EmployeeAssignment::query()->where('tenant_id', $this->tenantId())->whereIn('employee_id', $employeeIds)
            ->where('status', 'confirmed')->where('effective_from', '<=', $now)
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>', $now))
            ->whereHas('employee', fn ($employee) => $employee->where('status', 'active'))
            ->whereHas('workLocation', fn ($location) => $location->where('status', 'active'))
            ->with([
                'employee:id,tenant_id,employee_number,full_name',
                'workLocation:id,tenant_id,name',
                'shiftAssignments' => fn ($query) => $query->where('effective_from', '<=', $now)
                    ->where(fn ($active) => $active->whereNull('effective_until')->orWhere('effective_until', '>', $now))
                    ->with('shift'),
            ])->orderByRaw("CASE WHEN assignment_type='primary' THEN 0 ELSE 1 END")->get();

        $missing = [];
        foreach ($assignments as $assignment) {
            $schedule = $assignment->shiftAssignments->first();
            $shift = $schedule?->shift;
            if (!$shift || $shift->status !== 'active') continue;

            $shiftStart = (string) $shift->starts_at;
            $shiftEnd = (string) $shift->ends_at;
            $overnight = $shiftEnd <= $shiftStart;
            $workDate = $now->toDateString();
            if ($overnight && $now->format('H:i:s') <= $shiftEnd) $workDate = $now->subDay()->toDateString();
            if (!in_array(CarbonImmutable::parse($workDate, config('app.timezone'))->isoWeekday(), $shift->work_days ?? [], true)) continue;

            $scheduledStart = CarbonImmutable::parse($workDate . ' ' . $shiftStart, config('app.timezone'));
            if ($now->lessThan($scheduledStart->addMinutes((int) $shift->late_tolerance_minutes))) continue;

            $employeeId = (int) $assignment->employee_id;
            if (isset($missing[$employeeId])) continue;
            if (AttendanceRecord::query()->where('tenant_id', $this->tenantId())->where('employee_id', $employeeId)->whereDate('attendance_date', $workDate)->exists()) continue;
            if (LeaveRequest::query()->where('tenant_id', $this->tenantId())->where('employee_id', $employeeId)->where('status', 'approved')
                ->whereDate('starts_on', '<=', $workDate)->whereDate('ends_on', '>=', $workDate)->exists()) continue;

            $missing[$employeeId] = [
                'employee' => $assignment->employee?->only(['id', 'employee_number', 'full_name']),
                'assignment_id' => $assignment->id,
                'assignment_type' => $assignment->assignment_type,
                'work_location' => $assignment->workLocation?->only(['id', 'name']),
                'shift' => $shift->only(['id', 'name', 'starts_at', 'ends_at']),
                'work_date' => $workDate,
                'scheduled_start_at' => $scheduledStart->toIso8601String(),
            ];
        }
        return array_values($missing);
    }

    public function shifts()
    {
        $this->requireTenantScope();
        return response()->json(['data' => AttendanceShift::query()->where('tenant_id', $this->tenantId())->orderBy('name')->get()]);
    }

    public function storeShift(Request $request)
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:50', Rule::unique('attendance_shifts', 'code')->where('tenant_id', $this->tenantId())],
            'name' => ['required', 'string', 'max:120'], 'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i', 'different:starts_at'],
            'late_tolerance_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'work_days' => ['required', 'array', 'min:1'], 'work_days.*' => ['required', 'integer', 'between:1,7', 'distinct'],
        ]);
        $this->requireTenantScope();
        return response()->json($this->attendance->createShift($this->context->membership(), $data), 201);
    }

    public function assignShift(Request $request, int $assignment)
    {
        $data = $request->validate([
            'shift_id' => ['required', 'integer'], 'effective_from' => ['required', 'date'],
            'effective_until' => ['nullable', 'date', 'after:effective_from'],
        ]);
        $row = EmployeeAssignment::query()->where('tenant_id', $this->tenantId())->whereKey($assignment)->firstOrFail();
        $this->assertEmployeeVisible((int) $row->employee_id);
        if ($row->work_location_id) $this->assertLocationVisible(WorkLocation::query()->where('tenant_id', $this->tenantId())->whereKey($row->work_location_id)->firstOrFail());
        $shift = AttendanceShift::query()->where('tenant_id', $this->tenantId())->whereKey($data['shift_id'])->firstOrFail();
        return response()->json($this->attendance->assignShift($this->context->membership(), $row, $shift, $data), 201);
    }

    public function locationPolicy(int $workLocation)
    {
        $location = $this->visibleLocation($workLocation);
        $policy = AttendanceLocationPolicy::query()->where('tenant_id', $this->tenantId())->where('work_location_id', $location->id)->first();
        return response()->json(['work_location' => $location, 'policy' => $policy]);
    }

    public function saveLocationPolicy(Request $request, int $workLocation)
    {
        $data = $request->validate([
            'gps_mode' => ['required', Rule::in(['required', 'optional'])],
            'gps_accuracy_limit_meters' => ['nullable', 'integer', 'gt:0', 'max:100000'],
            'selfie_required_check_in' => ['required', 'boolean'], 'selfie_required_check_out' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
        ]);
        $location = $this->visibleLocation($workLocation);
        if ($data['gps_mode'] === 'required' && ($location->latitude === null || $location->longitude === null)) {
            abort(422, 'GPS Required perlu koordinat Work Location yang sudah dikonfigurasi.');
        }
        if ($data['gps_mode'] === 'required' && (int) $location->attendance_radius_meters < 1) abort(422, 'GPS Required perlu radius absensi positif.');
        return response()->json($this->attendance->saveLocationPolicy($this->context->membership(), $location, $data));
    }

    public function requestExemption(Request $request)
    {
        $data = $request->validate([
            'assignment_id' => ['required', 'integer'], 'work_location_id' => ['required', 'integer'],
            'reason' => ['required', 'string', 'max:1000'], 'effective_from' => ['required', 'date'],
            'effective_until' => ['required', 'date', 'after:effective_from'],
        ]);
        $employee = $this->attendance->selfEmployee($this->context->membership());
        $assignment = $this->visibleAssignment((int) $data['assignment_id'], $employee);
        $location = WorkLocation::query()->where('tenant_id', $this->tenantId())->whereKey($data['work_location_id'])->where('status', 'active')->firstOrFail();
        abort_unless((int) $assignment->work_location_id === (int) $location->id, 422);
        $policy = AttendanceLocationPolicy::query()->where('tenant_id', $this->tenantId())->where('work_location_id', $location->id)->where('is_active', true)->first();
        abort_unless($policy && $policy->gps_mode === 'required', 422, 'GPS exemption hanya berlaku pada lokasi dengan GPS Required.');
        return response()->json($this->attendance->requestGpsExemption($this->context->membership(), $employee, $assignment, $location,
            $data['reason'], CarbonImmutable::parse($data['effective_from'], config('app.timezone')),
            CarbonImmutable::parse($data['effective_until'], config('app.timezone'))), 201);
    }

    public function exemptions()
    {
        $employeeIds = $this->visibleEmployeeIds();
        return response()->json(['data' => AttendanceGpsExemption::query()->where('tenant_id', $this->tenantId())->whereIn('employee_id', $employeeIds)
            ->with(['employee:id,tenant_id,employee_number,full_name', 'workLocation:id,tenant_id,name', 'assignment:id,tenant_id,assignment_type'])
            ->orderByDesc('created_at')->paginate(50)]);
    }

    public function decideExemption(Request $request, int $exemption)
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['approve', 'reject'])], 'comment' => ['nullable', 'string', 'max:1000']]);
        $row = AttendanceGpsExemption::query()->where('tenant_id', $this->tenantId())->whereKey($exemption)->firstOrFail();
        $this->assertEmployeeVisible((int) $row->employee_id);
        $this->assertLocationVisible(WorkLocation::query()->where('tenant_id', $this->tenantId())->whereKey($row->work_location_id)->firstOrFail());
        return response()->json($this->attendance->decideGpsExemption($this->context->membership(), $row, $data['decision'], $data['comment'] ?? null));
    }

    public function ownPhoto(int $record, string $event)
    {
        abort_unless(in_array($event, ['check_in', 'check_out'], true), 404);
        $employee = $this->attendance->selfEmployee($this->context->membership());
        $photo = AttendancePhoto::query()->where('tenant_id', $this->tenantId())->where('event_type', $event)
            ->whereHas('attendance', fn ($query) => $query->where('employee_id', $employee->id))->where('attendance_record_id', $record)->firstOrFail();
        return $this->privatePhoto($photo);
    }

    public function managementPhoto(int $record, int $photo)
    {
        $attendance = AttendanceRecord::query()->where('tenant_id', $this->tenantId())->whereKey($record)->firstOrFail();
        $this->assertEmployeeVisible((int) $attendance->employee_id);
        $item = AttendancePhoto::query()->where('tenant_id', $this->tenantId())->where('attendance_record_id', $record)->whereKey($photo)->firstOrFail();
        return $this->privatePhoto($item);
    }

    private function privatePhoto(AttendancePhoto $photo)
    {
        $this->auditPhotoView($photo);
        abort_unless(Storage::disk($photo->storage_disk)->exists($photo->storage_path), 404);
        return Storage::disk($photo->storage_disk)->response($photo->storage_path, $photo->original_filename, [
            'Cache-Control' => 'private, no-store, max-age=0', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function auditPhotoView(AttendancePhoto $photo): void
    {
        app(\App\Services\AuditLogger::class)->record('attendance.photo.viewed', $photo, [], [
            'attendance_record_id' => $photo->attendance_record_id, 'event_type' => $photo->event_type,
        ], $this->tenantId());
    }

    private function visibleAssignment(int $id, Employee $employee): EmployeeAssignment
    {
        $row = EmployeeAssignment::query()->where('tenant_id', $this->tenantId())->where('employee_id', $employee->id)->whereKey($id)->firstOrFail();
        abort_unless($row->status === 'confirmed' && $row->effective_from <= now(config('app.timezone')) && (!$row->effective_until || $row->effective_until > now(config('app.timezone'))), 422);
        return $row->load(['workLocation', 'department', 'position']);
    }

    private function visibleLocation(int $id): WorkLocation
    {
        $location = WorkLocation::query()->where('tenant_id', $this->tenantId())->whereKey($id)->firstOrFail();
        $this->assertLocationVisible($location);
        return $location;
    }

    private function assertLocationVisible(WorkLocation $location): void
    {
        abort_unless($this->scopes->allows($this->context->membership(), 'work_location', (int) $location->id), 403);
    }

    private function assertEmployeeVisible(int $id): void
    {
        abort_unless($this->scopes->allows($this->context->membership(), 'employee', $id), 403);
    }

    private function visibleEmployeeIds(): array
    {
        return $this->scopes->constrain(Employee::query(), $this->context->membership(), 'employee')
            ->where('tenant_id', $this->tenantId())->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function requireTenantScope(): void
    {
        abort_unless($this->scopes->hasTenantAccess($this->context->membership()), 403);
    }

    private function tenantId(): int { return $this->context->tenantId(); }
}
