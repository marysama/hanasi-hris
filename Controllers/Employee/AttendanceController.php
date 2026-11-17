<?php
namespace App\Controllers\Employee;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\AttendanceRepository;
use App\Repositories\EmployeeRepository;
use App\Repositories\AreaRepository;
use App\Repositories\ScheduleRepository;
use App\Core\Database;
use PDO;

class AttendanceController
{
    private $employee;
    private $area;

    public function __construct()
    {
        $user = Session::get('user');
        $empRepo = new EmployeeRepository();
        $this->employee = $empRepo->getProfileByUserId((int)$user['id']);

        if ($this->employee && $this->employee['area_id']) {
            $areaRepo = new AreaRepository();
            $this->area = $areaRepo->getById((int)$this->employee['area_id']);
        }
    }

    private function hitungJarak($lat1, $lon1, $lat2, $lon2) {
        $theta = $lon1 - $lon2;
        $dist = sin(deg2rad($lat1)) * sin(deg2rad($lat2)) +  cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos(deg2rad($theta));
        $dist = acos($dist);
        $dist = rad2deg($dist);
        $miles = $dist * 60 * 1.1515;
        return $miles * 1609.344;
    }

    private function simpanFoto($base64_string) {
        if (preg_match('/^data:image\/(\w+);base64,/', $base64_string, $type)) {
            $data = substr($base64_string, strpos($base64_string, ',') + 1);
            $type = strtolower($type[1]);
            if (!in_array($type, [ 'jpg', 'jpeg', 'gif', 'png' ])) return null;
            $data = base64_decode($data);
            if ($data === false) return null;

            $fileName = 'absen_' . time() . '_' . rand(1000,9999) . '.' . $type;
            $uploadDir = __DIR__ . '/../../../public/uploads/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            file_put_contents($uploadDir . $fileName, $data);
            return $fileName;
        }
        return null;
    }

    private function validateRequirements(): ?string {
        if ($this->employee['is_shift_required']) {
            $schRepo = new ScheduleRepository();
            $shift = $schRepo->getTodayShift((int)$this->employee['id']);
            if (!$shift) {
                return 'Absensi ditolak: Anda belum memiliki jadwal shift untuk hari ini.';
            }
        }
        if (!$this->employee['is_mobile'] && $this->employee['area_id']) {
            if (!$this->area) {
                return 'Absensi ditolak: Area kerja Anda tidak valid atau sudah dihapus.';
            }
        }
        return null;
    }

    private function validateLocation($lat, $lng): ?string {
        if ($this->employee['is_mobile'] || !$this->employee['area_id']) {
            return null;
        }
        $jarak = $this->hitungJarak($this->area['latitude'], $this->area['longitude'], $lat, $lng);
        if ($jarak > $this->area['radius_m']) {
            return 'Absensi ditolak: Anda berada di luar radius area ' . $this->area['name'] . ' (' . round($jarak) . ' meter).';
        }
        return null;
    }

    public function checkIn(): void
    {
        $user = Session::get('user');
        $repo = new AttendanceRepository();
        
        if ($repo->getTodayAttendance((int)$user['id'])) {
            Session::flash('error', 'Anda sudah Check In hari ini.');
            Response::redirect('?portal=karyawan&page=dashboard');
        }

        $reqError = $this->validateRequirements();
        if ($reqError) {
            Session::flash('error', $reqError);
            Response::redirect('?portal=karyawan&page=dashboard');
        }

        $lat = (float)Request::post('latitude');
        $lng = (float)Request::post('longitude');
        $photoBase64 = Request::post('photo');

        $locError = $this->validateLocation($lat, $lng);
        if ($locError) {
            Session::flash('error', $locError);
            Response::redirect('?portal=karyawan&page=dashboard');
        }

        if (empty($photoBase64)) {
            Session::flash('error', 'Check In gagal: Foto selfie wajib diambil.');
            Response::redirect('?portal=karyawan&page=dashboard');
        }

        $fileName = $this->simpanFoto($photoBase64);
        $repo->checkIn((int)$user['id'], ['lat' => $lat, 'lng' => $lng, 'photo' => $fileName]);

        Session::flash('success', 'Check In berhasil dicatat!');
        Response::redirect('?portal=karyawan&page=dashboard');
    }

    public function checkOut(): void
    {
        $user = Session::get('user');
        $repo = new AttendanceRepository();
        
        $today = $repo->getTodayAttendance((int)$user['id']);
        if (!$today || $today['check_out_at'] !== null) {
            Session::flash('error', 'Check Out tidak valid.');
            Response::redirect('?portal=karyawan&page=dashboard');
        }

        $lat = (float)Request::post('latitude');
        $lng = (float)Request::post('longitude');
        $photoBase64 = Request::post('photo');

        $locError = $this->validateLocation($lat, $lng);
        if ($locError) {
            Session::flash('error', str_replace('Check In', 'Check Out', $locError));
            Response::redirect('?portal=karyawan&page=dashboard');
        }

        if (empty($photoBase64)) {
            Session::flash('error', 'Check Out gagal: Foto selfie wajib diambil.');
            Response::redirect('?portal=karyawan&page=dashboard');
        }

        $fileName = $this->simpanFoto($photoBase64);
        $repo->checkOut((int)$user['id'], ['lat' => $lat, 'lng' => $lng, 'photo' => $fileName]);

        Session::flash('success', 'Check Out berhasil dicatat!');
        Response::redirect('?portal=karyawan&page=dashboard');
    }

    public function reportWrong(): void
    {
        $user = Session::get('user');
        
        // 1. Cari Atasan Langsung dari Karyawan ini
        $stmt = Database::pdo()->prepare("
            SELECT u.id 
            FROM employees e 
            JOIN user_employee_links l ON e.supervisor_id = l.employee_id 
            JOIN users u ON l.user_id = u.id
            WHERE e.id = (SELECT employee_id FROM user_employee_links WHERE user_id = :uid LIMIT 1)
        ");
        $stmt->execute(['uid' => $user['id']]);
        $supervisorUserId = $stmt->fetchColumn();

        if ($supervisorUserId) {
            \App\Services\Notifier::send((int)$supervisorUserId, 'Lapor Salah Absen', "Karyawan " . $user['full_name'] . " melaporkan salah absen hari ini. Mohon cek dan reset absensinya jika perlu.");
        }

        // 2. Kirim juga ke Admin/Head (Level >= 5)
        $stmtAdmin = Database::pdo()->query("
            SELECT u.id FROM users u
            JOIN user_roles ur ON u.id = ur.user_id
            JOIN roles r ON ur.role_id = r.id
            WHERE r.level >= 5
        ");
        $admins = $stmtAdmin->fetchAll(PDO::FETCH_COLUMN);
        foreach ($admins as $adminId) {
            \App\Services\Notifier::send((int)$adminId, 'Lapor Salah Absen', "Karyawan " . $user['full_name'] . " melaporkan salah absen hari ini. Silakan login ke Manajemen untuk mereset data.");
        }

        Session::flash('success', 'Laporan terkirim. Atasan/HR akan segera memproses.');
        Response::redirect('?portal=karyawan&page=dashboard');
    }
}