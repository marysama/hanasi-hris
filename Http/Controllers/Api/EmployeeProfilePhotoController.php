<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeProfilePhoto;
use App\Services\AuditLogger;
use App\Services\TenantContext;
use App\Services\TenantScopeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class EmployeeProfilePhotoController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantScopeService $scopes,
        private readonly AuditLogger $audit,
    ) {}

    public function show(int $employee)
    {
        $row = $this->findEmployee($employee);
        return $this->serveCurrentPhoto($row);
    }

    public function update(Request $request, int $employee)
    {
        $row = $this->findEmployee($employee);
        return $this->store($request, $row);
    }

    public function showSelf()
    {
        return $this->serveCurrentPhoto($this->selfEmployee());
    }

    public function updateSelf(Request $request)
    {
        return $this->store($request, $this->selfEmployee());
    }

    private function store(Request $request, Employee $employee)
    {
        $data = $request->validate([
            'photo' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);
        $file = $data['photo'];
        $extension = strtolower($file->guessExtension() ?: $file->extension() ?: 'jpg');
        $path = $file->storeAs(
            'employee-profile-photos/' . $employee->tenant_id . '/' . $employee->id,
            Str::uuid() . '.' . $extension,
            'local',
        );
        if (!$path) abort(500, 'Foto gagal disimpan ke private storage.');

        try {
            $photo = DB::transaction(function () use ($employee, $file, $path): EmployeeProfilePhoto {
                $locked = Employee::query()->where('tenant_id', $employee->tenant_id)->whereKey($employee->id)->lockForUpdate()->firstOrFail();
                $current = EmployeeProfilePhoto::query()->where('tenant_id', $employee->tenant_id)->where('employee_id', $employee->id)
                    ->where('status', 'current')->lockForUpdate()->first();
                $version = (int) EmployeeProfilePhoto::query()->where('tenant_id', $employee->tenant_id)->where('employee_id', $employee->id)->max('version_number') + 1;

                if ($current) {
                    $current->status = 'superseded';
                    $current->save();
                }

                $photo = EmployeeProfilePhoto::create([
                    'tenant_id' => $employee->tenant_id,
                    'employee_id' => $employee->id,
                    'uploaded_by_user_id' => $this->context->membership()->user_id,
                    'version_number' => $version,
                    'original_filename' => $this->safeOriginalName($file->getClientOriginalName()),
                    'storage_disk' => 'local',
                    'storage_path' => $path,
                    'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                    'file_size_bytes' => $file->getSize(),
                    'sha256' => hash_file('sha256', $file->getRealPath()),
                    'status' => 'current',
                    'created_at' => now(),
                ]);

                $this->audit->record('employee.profile_photo.' . ($current ? 'replaced' : 'uploaded'), $photo, [
                    'photo_version' => $version,
                    'replaced_version' => $current?->version_number,
                    'mime_type' => $photo->mime_type,
                    'file_size_bytes' => $photo->file_size_bytes,
                ], ['employee_id' => $employee->id], (int) $employee->tenant_id);
                return $photo;
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        return response()->json([
            'employee_id' => $employee->id,
            'version' => $photo->version_number,
            'mime_type' => $photo->mime_type,
            'file_size_bytes' => $photo->file_size_bytes,
            'status' => $photo->status,
        ], 201);
    }

    private function serveCurrentPhoto(Employee $employee)
    {
        $photo = EmployeeProfilePhoto::query()->where('tenant_id', $employee->tenant_id)->where('employee_id', $employee->id)
            ->where('status', 'current')->firstOrFail();
        abort_unless(Storage::disk($photo->storage_disk)->exists($photo->storage_path), 404);
        $this->audit->record('employee.profile_photo.viewed', $photo, [], ['employee_id' => $employee->id], (int) $employee->tenant_id);
        return Storage::disk($photo->storage_disk)->response($photo->storage_path, 'profile-photo.' . $this->extensionForMime($photo->mime_type), [
            'Content-Type' => $photo->mime_type,
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function findEmployee(int $id): Employee
    {
        return $this->scopes->constrain(Employee::query(), $this->context->membership(), 'employee')
            ->where('tenant_id', $this->context->tenantId())->whereKey($id)->firstOrFail();
    }

    private function selfEmployee(): Employee
    {
        return Employee::query()->where('tenant_id', $this->context->tenantId())
            ->where('user_id', $this->context->membership()->user_id)->where('status', 'active')->firstOrFail();
    }

    private function safeOriginalName(string $filename): string
    {
        $name = basename(str_replace('\\', '/', $filename));
        $name = preg_replace('/[\x00-\x1F\x7F"\/]+/u', '_', $name) ?: 'profile-photo';
        return mb_substr($name, 0, 255);
    }

    private function extensionForMime(string $mime): string
    {
        return match ($mime) {
            'image/png' => 'png', 'image/webp' => 'webp', default => 'jpg',
        };
    }
}
