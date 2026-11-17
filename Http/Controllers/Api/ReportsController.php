<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\LegalEntity;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Services\AuditLogger;
use App\Services\TenantContext;
use App\Services\TenantScopeService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ReportsController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantScopeService $scopes,
        private readonly AuditLogger $audit,
    ) {}

    public function employeeDirectory(Request $request) { return $this->run($request, 'employees'); }
    public function exportEmployeeDirectory(Request $request) { return $this->run($request, 'employees', true); }
    public function attendanceSummary(Request $request) { return $this->run($request, 'attendance-summary'); }
    public function exportAttendanceSummary(Request $request) { return $this->run($request, 'attendance-summary', true); }
    public function attendanceDetail(Request $request) { return $this->run($request, 'attendance-detail'); }
    public function exportAttendanceDetail(Request $request) { return $this->run($request, 'attendance-detail', true); }
    public function sia(Request $request) { return $this->run($request, 'sia'); }
    public function exportSia(Request $request) { return $this->run($request, 'sia', true); }
    public function leaveRequests(Request $request) { return $this->run($request, 'leave-requests'); }
    public function exportLeaveRequests(Request $request) { return $this->run($request, 'leave-requests', true); }
    public function payrollSummary(Request $request) { return $this->run($request, 'payroll-summary'); }
    public function exportPayrollSummary(Request $request) { return $this->run($request, 'payroll-summary', true); }
    public function payrollDetail(Request $request) { return $this->run($request, 'payroll-detail'); }
    public function exportPayrollDetail(Request $request) { return $this->run($request, 'payroll-detail', true); }

    private function run(Request $request, string $report, bool $export = false)
    {
        $filters = $this->validatedFilters($request, $report);
        $this->assertVisibleFilters($filters);
        $this->audit->record(
            $export ? 'reports.exported' : 'reports.viewed',
            Tenant::query()->findOrFail($this->tenantId()),
            [],
            ['report' => $report, 'format' => $export ? 'csv' : 'json', 'filters' => $filters],
            $this->tenantId(),
        );

        if ($report === 'attendance-summary') return $this->attendanceSummaryResponse($filters, $export);

        [$query, $headers, $filename] = match ($report) {
            'employees' => [$this->employeeDirectoryQuery($filters), ['employee_number','full_name','status','hire_date','legal_entity','department','position','work_location'], 'employee-directory.csv'],
            'attendance-detail' => [$this->attendanceDetailQuery($filters), ['attendance_date','employee_number','employee_name','status','work_location','department','scheduled_start_at','scheduled_end_at','check_in_at','check_out_at','check_in_gps_status','check_out_gps_status'], 'attendance-detail.csv'],
            'sia' => [$this->leaveQuery($filters, true, true), ['employee_number','employee_name','type_code','type_name','attendance_status','starts_on','ends_on','status','request_source'], 'attendance-sia.csv'],
            'leave-requests' => [$this->leaveQuery($filters, false, false), ['employee_number','employee_name','type_code','type_name','starts_on','ends_on','status','request_source','reviewed_at'], 'leave-requests.csv'],
            'payroll-summary' => [$this->payrollSummaryQuery($filters), ['payroll_run_id','period_code','period_start','period_end','revision_number','status','employee_count','gross_total','deduction_total','net_total'], 'payroll-summary.csv'],
            'payroll-detail' => [$this->payrollDetailQuery($filters), ['payroll_run_id','period_code','period_start','period_end','revision_number','run_status','employee_number','employee_name','department','gross_total','deduction_total','net_pay','payslip_generated_at'], 'payroll-detail.csv'],
        };

        $query->orderBy($this->sortColumn($report));
        if ($export) return $this->csvResponse($headers, fn () => $query->cursor(), $filename);

        return response()->json(['data' => $query->paginate(100)->withQueryString()])
            ->header('Cache-Control', 'private, no-store, max-age=0');
    }

    private function validatedFilters(Request $request, string $report): array
    {
        $statusValues = match ($report) {
            'employees' => ['active','inactive','resigned','terminated'],
            'attendance-summary','attendance-detail' => ['present','late','left_early','late_and_left_early'],
            'sia' => ['approved'],
            'leave-requests' => ['pending_approval','approved','rejected','cancelled'],
            'payroll-summary','payroll-detail' => ['draft','pending_approval','approved','finalized','superseded','rejected'],
            default => [],
        };
        $tenantId = $this->tenantId();
        $filters = $request->validate([
            'from_date' => ['nullable','date'],
            'to_date' => ['nullable','date'],
            'employee_id' => ['nullable','integer',Rule::exists('employees','id')->where('tenant_id',$tenantId)],
            'department_id' => ['nullable','integer',Rule::exists('departments','id')->where('tenant_id',$tenantId)],
            'work_location_id' => ['nullable','integer',Rule::exists('work_locations','id')->where('tenant_id',$tenantId)],
            'period_id' => ['nullable','integer',Rule::exists('payroll_periods','id')->where('tenant_id',$tenantId)],
            'status' => $statusValues === [] ? ['prohibited'] : ['nullable',Rule::in($statusValues)],
        ]);
        if (!empty($filters['from_date']) && !empty($filters['to_date']) && $filters['to_date'] < $filters['from_date']) {
            abort(422, 'to_date harus sama dengan atau setelah from_date.');
        }
        return $filters;
    }

    private function assertVisibleFilters(array $filters): void
    {
        $membership = $this->context->membership();
        if (isset($filters['employee_id']) && !$this->scopes->allows($membership, 'employee', (int) $filters['employee_id'])) abort(404);
        if (isset($filters['department_id'])) {
            $departmentVisible = $this->scopes->allows($membership,'department',(int)$filters['department_id'])
                || $this->visibleAssignmentQuery()->where('employee_assignments.department_id',$filters['department_id'])->exists()
                || $this->visibleEmployeeQuery()->where('employees.department_id',$filters['department_id'])->exists();
            if (!$departmentVisible) abort(404);
        }
        if (isset($filters['work_location_id'])) {
            $locationVisible = $this->scopes->allows($membership,'work_location',(int)$filters['work_location_id'])
                || $this->visibleAssignmentQuery()->where('employee_assignments.work_location_id',$filters['work_location_id'])->exists();
            if (!$locationVisible) abort(404);
        }
    }

    private function employeeDirectoryQuery(array $filters): Builder
    {
        $now = CarbonImmutable::now(config('app.timezone'))->format('Y-m-d H:i:s');
        $primary = DB::table('employee_assignments')
            ->select('employee_id')->selectRaw('MIN(id) AS assignment_id')
            ->where('tenant_id',$this->tenantId())->where('assignment_type','primary')->where('status','confirmed')
            ->where('effective_from','<=',$now)->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until','>',$now))
            ->groupBy('employee_id');

        $query = DB::table('employees as e')
            ->leftJoin('legal_entities as le',fn ($join) => $join->on('le.id','=','e.legal_entity_id')->on('le.tenant_id','=','e.tenant_id'))
            ->leftJoinSub($primary,'current_primary',fn ($join) => $join->on('current_primary.employee_id','=','e.id'))
            ->leftJoin('employee_assignments as a',fn ($join) => $join->on('a.id','=','current_primary.assignment_id')->on('a.tenant_id','=','e.tenant_id'))
            ->leftJoin('departments as profile_department',fn ($join) => $join->on('profile_department.id','=','e.department_id')->on('profile_department.tenant_id','=','e.tenant_id'))
            ->leftJoin('departments as assignment_department',fn ($join) => $join->on('assignment_department.id','=','a.department_id')->on('assignment_department.tenant_id','=','e.tenant_id'))
            ->leftJoin('positions as profile_position',fn ($join) => $join->on('profile_position.id','=','e.position_id')->on('profile_position.tenant_id','=','e.tenant_id'))
            ->leftJoin('positions as assignment_position',fn ($join) => $join->on('assignment_position.id','=','a.position_id')->on('assignment_position.tenant_id','=','e.tenant_id'))
            ->leftJoin('work_locations as wl',fn ($join) => $join->on('wl.id','=','a.work_location_id')->on('wl.tenant_id','=','e.tenant_id'))
            ->where('e.tenant_id',$this->tenantId())->whereIn('e.id',$this->visibleEmployeeQuery())
            ->select([
                'e.id','e.employee_number','e.full_name','e.status','e.hire_date',
                'le.display_name as legal_entity',
                DB::raw('COALESCE(assignment_department.id, e.department_id) AS department_id'),
                DB::raw('COALESCE(assignment_department.name, profile_department.name) AS department'),
                DB::raw('COALESCE(assignment_position.name, profile_position.name) AS position'),
                'a.work_location_id','wl.name as work_location',
            ]);

        if (isset($filters['employee_id'])) $query->where('e.id',$filters['employee_id']);
        if (isset($filters['status'])) $query->where('e.status',$filters['status']);
        if (isset($filters['department_id'])) $query->whereRaw('COALESCE(assignment_department.id, e.department_id) = ?',[$filters['department_id']]);
        if (isset($filters['work_location_id'])) $query->where('a.work_location_id',$filters['work_location_id']);
        return $query;
    }

    private function attendanceDetailQuery(array $filters): Builder
    {
        $query = DB::table('attendance_records as ar')
            ->join('employees as e',fn ($join) => $join->on('e.id','=','ar.employee_id')->on('e.tenant_id','=','ar.tenant_id'))
            ->where('ar.tenant_id',$this->tenantId())->whereIn('ar.employee_id',$this->visibleEmployeeQuery())
            ->whereIn('ar.assignment_id',$this->visibleAssignmentQuery())
            ->select([
                'ar.attendance_date','e.employee_number','e.full_name as employee_name','ar.attendance_status as status',
                'ar.work_location_name_snapshot as work_location','ar.department_name_snapshot as department',
                'ar.scheduled_start_at','ar.scheduled_end_at','ar.check_in_at','ar.check_out_at',
                'ar.check_in_gps_status','ar.check_out_gps_status',
            ]);
        $this->applyPeriod($query,'ar.attendance_date',$filters,true);
        if (isset($filters['employee_id'])) $query->where('ar.employee_id',$filters['employee_id']);
        if (isset($filters['department_id'])) $query->where('ar.department_id',$filters['department_id']);
        if (isset($filters['work_location_id'])) $query->where('ar.work_location_id',$filters['work_location_id']);
        if (isset($filters['status'])) $query->where('ar.attendance_status',$filters['status']);
        return $query;
    }

    private function leaveQuery(array $filters, bool $approvedOnly, bool $siaOnly): Builder
    {
        $query = DB::table('leave_requests as lr')
            ->join('employees as e',fn ($join) => $join->on('e.id','=','lr.employee_id')->on('e.tenant_id','=','lr.tenant_id'))
            ->join('leave_types as lt',fn ($join) => $join->on('lt.id','=','lr.leave_type_id')->on('lt.tenant_id','=','lr.tenant_id'))
            ->where('lr.tenant_id',$this->tenantId())->whereIn('lr.employee_id',$this->visibleEmployeeQuery())
            ->select([
                'e.employee_number','e.full_name as employee_name','lt.code as type_code','lt.name as type_name',
                'lt.attendance_status',DB::raw('DATE(lr.starts_on) as starts_on'),DB::raw('DATE(lr.ends_on) as ends_on'),
                'lr.status','lr.request_source','lr.reviewed_at',
            ]);
        if ($approvedOnly) $query->where('lr.status','approved');
        if ($siaOnly) $query->whereIn('lt.attendance_status',['S','I','A']);
        elseif (isset($filters['status'])) $query->where('lr.status',$filters['status']);
        if (!empty($filters['from_date'])) $query->whereDate('lr.ends_on','>=',$filters['from_date']);
        if (!empty($filters['to_date'])) $query->whereDate('lr.starts_on','<=',$filters['to_date']);
        if (isset($filters['employee_id'])) $query->where('lr.employee_id',$filters['employee_id']);
        if (!$this->scopes->hasTenantAccess($this->context->membership()) || isset($filters['department_id']) || isset($filters['work_location_id'])) {
            $assignmentScope = $this->visibleAssignmentQuery();
            $query->whereExists(function ($exists) use ($filters,$assignmentScope) {
                $exists->selectRaw('1')->from('employee_assignments as ea')
                    ->whereColumn('ea.employee_id','lr.employee_id')->where('ea.tenant_id',$this->tenantId())
                    ->where('ea.status','confirmed')->whereRaw('DATE(ea.effective_from) <= DATE(lr.ends_on)')
                    ->where(fn ($active) => $active->whereNull('ea.effective_until')->orWhereRaw('DATE(ea.effective_until) >= DATE(lr.starts_on)'))
                    ->whereIn('ea.id',$assignmentScope);
                if (isset($filters['department_id'])) $exists->where('ea.department_id',$filters['department_id']);
                if (isset($filters['work_location_id'])) $exists->where('ea.work_location_id',$filters['work_location_id']);
            });
        }
        return $query;
    }

    private function payrollDetailQuery(array $filters): Builder
    {
        $query = DB::table('payroll_items as pi')
            ->join('payroll_runs as pr',fn ($join) => $join->on('pr.id','=','pi.payroll_run_id')->on('pr.tenant_id','=','pi.tenant_id'))
            ->join('payroll_periods as pp',fn ($join) => $join->on('pp.id','=','pr.payroll_period_id')->on('pp.tenant_id','=','pr.tenant_id'))
            ->where('pi.tenant_id',$this->tenantId())->whereIn('pi.employee_id',$this->visibleEmployeeQuery())
            ->whereIn('pi.legal_entity_id',$this->visibleLegalEntityQuery())
            ->select([
                'pr.id as payroll_run_id','pp.code as period_code','pp.period_start','pp.period_end','pr.revision_number',
                'pr.status as run_status','pi.employee_number_snapshot as employee_number','pi.employee_name_snapshot as employee_name',
                'pi.department_name_snapshot as department','pi.gross_total','pi.deduction_total','pi.net_pay','pi.payslip_generated_at',
            ]);
        $this->applyPayrollFilters($query,$filters);
        return $query;
    }

    private function payrollSummaryQuery(array $filters): Builder
    {
        $query = DB::table('payroll_items as pi')
            ->join('payroll_runs as pr',fn ($join) => $join->on('pr.id','=','pi.payroll_run_id')->on('pr.tenant_id','=','pi.tenant_id'))
            ->join('payroll_periods as pp',fn ($join) => $join->on('pp.id','=','pr.payroll_period_id')->on('pp.tenant_id','=','pr.tenant_id'))
            ->where('pi.tenant_id',$this->tenantId())->whereIn('pi.employee_id',$this->visibleEmployeeQuery())
            ->whereIn('pi.legal_entity_id',$this->visibleLegalEntityQuery());
        $this->applyPayrollFilters($query,$filters);
        return $query->select([
            'pr.id as payroll_run_id','pp.code as period_code','pp.period_start','pp.period_end','pr.revision_number','pr.status',
        ])->selectRaw('COUNT(DISTINCT pi.employee_id) as employee_count, SUM(pi.gross_total) as gross_total, SUM(pi.deduction_total) as deduction_total, SUM(pi.net_pay) as net_total')
            ->groupBy('pr.id','pp.code','pp.period_start','pp.period_end','pr.revision_number','pr.status');
    }

    private function applyPayrollFilters(Builder $query, array $filters): void
    {
        if (isset($filters['from_date'])) $query->where('pp.period_end','>=',$filters['from_date']);
        if (isset($filters['to_date'])) $query->where('pp.period_start','<=',$filters['to_date']);
        if (isset($filters['period_id'])) $query->where('pp.id',$filters['period_id']);
        if (isset($filters['employee_id'])) $query->where('pi.employee_id',$filters['employee_id']);
        if (isset($filters['department_id'])) $query->where('pi.department_id',$filters['department_id']);
        if (isset($filters['status'])) $query->where('pr.status',$filters['status']);
        if (isset($filters['work_location_id'])) {
            $assignmentScope = $this->visibleAssignmentQuery();
            $query->whereExists(function ($exists) use ($filters,$assignmentScope) {
                $exists->selectRaw('1')->from('employee_assignments as ea')
                    ->whereColumn('ea.employee_id','pi.employee_id')->where('ea.tenant_id',$this->tenantId())
                    ->where('ea.status','confirmed')->whereRaw('DATE(ea.effective_from) <= DATE(pp.period_end)')
                    ->where(fn ($active) => $active->whereNull('ea.effective_until')->orWhereRaw('DATE(ea.effective_until) >= DATE(pp.period_end)'))
                    ->where('ea.work_location_id',$filters['work_location_id'])->whereIn('ea.id',$assignmentScope);
            });
        }
    }

    private function attendanceSummaryResponse(array $filters, bool $export)
    {
        $from = $filters['from_date'] ?? $filters['to_date'] ?? CarbonImmutable::now(config('app.timezone'))->toDateString();
        $to = $filters['to_date'] ?? $filters['from_date'] ?? $from;
        $records = DB::table('attendance_records as ar')
            ->where('ar.tenant_id',$this->tenantId())->whereIn('ar.employee_id',$this->visibleEmployeeQuery())
            ->whereIn('ar.assignment_id',$this->visibleAssignmentQuery())->whereBetween('ar.attendance_date',[$from,$to]);
        if (isset($filters['employee_id'])) $records->where('ar.employee_id',$filters['employee_id']);
        if (isset($filters['department_id'])) $records->where('ar.department_id',$filters['department_id']);
        if (isset($filters['work_location_id'])) $records->where('ar.work_location_id',$filters['work_location_id']);
        if (isset($filters['status'])) $records->where('ar.attendance_status',$filters['status']);
        $counts = $records->selectRaw("COUNT(*) as total, SUM(CASE WHEN attendance_status='present' THEN 1 ELSE 0 END) as present, SUM(CASE WHEN attendance_status='late' THEN 1 ELSE 0 END) as late, SUM(CASE WHEN attendance_status='left_early' THEN 1 ELSE 0 END) as left_early, SUM(CASE WHEN attendance_status='late_and_left_early' THEN 1 ELSE 0 END) as late_and_left_early")->first();

        $sia = $this->leaveQuery(['from_date'=>$from,'to_date'=>$to] + $filters,true,true)
            ->select('lt.attendance_status')->selectRaw('COUNT(*) as request_count')->groupBy('lt.attendance_status')->pluck('request_count','attendance_status');
        $summary = [
            'from_date'=>$from,'to_date'=>$to,
            'attendance_records'=>(int)($counts->total ?? 0),'present'=>(int)($counts->present ?? 0),
            'late'=>(int)($counts->late ?? 0),'left_early'=>(int)($counts->left_early ?? 0),
            'late_and_left_early'=>(int)($counts->late_and_left_early ?? 0),
            'approved_sia_requests'=>['S'=>(int)($sia['S'] ?? 0),'I'=>(int)($sia['I'] ?? 0),'A'=>(int)($sia['A'] ?? 0)],
        ];
        if ($export) {
            $row = [
                'from_date'=>$from,'to_date'=>$to,'attendance_records'=>$summary['attendance_records'],
                'present'=>$summary['present'],'late'=>$summary['late'],'left_early'=>$summary['left_early'],
                'late_and_left_early'=>$summary['late_and_left_early'],
                'approved_sia_requests_S'=>$summary['approved_sia_requests']['S'],
                'approved_sia_requests_I'=>$summary['approved_sia_requests']['I'],
                'approved_sia_requests_A'=>$summary['approved_sia_requests']['A'],
            ];
            return $this->csvResponse(array_keys($row),fn () => [(object) $row], 'attendance-summary.csv');
        }
        return response()->json(['summary'=>$summary])->header('Cache-Control','private, no-store, max-age=0');
    }

    private function applyPeriod(Builder $query, string $column, array $filters, bool $defaultToday): void
    {
        $from = $filters['from_date'] ?? $filters['to_date'] ?? ($defaultToday ? CarbonImmutable::now(config('app.timezone'))->toDateString() : null);
        $to = $filters['to_date'] ?? $filters['from_date'] ?? ($defaultToday ? $from : null);
        if ($from && $to) $query->whereBetween($column,[$from,$to]);
        elseif ($from) $query->where($column,'>=',$from);
        elseif ($to) $query->where($column,'<=',$to);
    }

    private function visibleEmployeeQuery(): EloquentBuilder
    {
        return $this->scopes->constrain(Employee::query(),$this->context->membership(),'employee')
            ->where('employees.tenant_id',$this->tenantId())->select('employees.id');
    }

    private function visibleLegalEntityQuery(): EloquentBuilder
    {
        return $this->scopes->constrain(LegalEntity::query(),$this->context->membership(),'legal_entity')
            ->where('legal_entities.tenant_id',$this->tenantId())->select('legal_entities.id');
    }

    private function visibleAssignmentQuery(): EloquentBuilder
    {
        return $this->scopes->constrainAssignments(EmployeeAssignment::query()
            ->whereIn('employee_assignments.employee_id',$this->visibleEmployeeQuery()),$this->context->membership())
            ->select('employee_assignments.id');
    }

    private function sortColumn(string $report): string
    {
        return match ($report) {
            'employees'=>'e.employee_number','attendance-detail'=>'ar.attendance_date','sia','leave-requests'=>'lr.starts_on',
            'payroll-summary','payroll-detail'=>'pp.period_start',default=>'id',
        };
    }

    private function csvResponse(array $headers, callable $rows, string $filename)
    {
        return response()->streamDownload(function () use ($headers,$rows): void {
            $output = fopen('php://output','w');
            fwrite($output,"\xEF\xBB\xBF");
            fputcsv($output,$headers);
            foreach ($rows() as $row) {
                $values = (array) $row;
                $line = [];
                foreach ($headers as $header) {
                    $value = $values[$header] ?? '';
                    if (is_string($value) && preg_match('/^[\\s]*[=+@\\-]/u',$value)) $value = "'".$value;
                    $line[] = $value;
                }
                fputcsv($output,$line);
            }
            fclose($output);
        },$filename,[
            'Content-Type'=>'text/csv; charset=UTF-8',
            'Cache-Control'=>'private, no-store, max-age=0',
            'X-Content-Type-Options'=>'nosniff',
        ]);
    }

    private function tenantId(): int { return $this->context->tenantId(); }
}
