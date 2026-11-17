<?php
namespace App\Controllers\Employee;

use App\Core\View;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\EmployeeRepository;
use App\Services\AiAssistant;
use App\Services\Notifier;
use App\Core\Database;
use PDO;

class ProfileController
{
    public function index(): void
    {
        $user = Session::get('user');
        $repo = new EmployeeRepository();
        $profile = $repo->getProfileByUserId((int)$user['id']);
        
        $stmt = Database::pdo()->prepare("SELECT * FROM master_documents WHERE doc_type = 'PKWT' LIMIT 1");
        $stmt->execute();
        $masterDoc = $stmt->fetch(PDO::FETCH_ASSOC);

        $pkwtDoc = $repo->getPkwtStatus((int)$profile['id']);

        View::render('employee/account', [
            'profile' => $profile, 
            'masterDoc' => $masterDoc,
            'pkwtDoc' => $pkwtDoc
        ], 'employee');
    }

    public function update(): void
    {
        $user = Session::get('user');
        $repo = new EmployeeRepository();
        $profile = $repo->getProfileByUserId((int)$user['id']);
        
        if ($profile) {
            $photoName = $profile['photo_path'];
            if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = __DIR__ . '/../../../public/uploads/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                $ext = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
                $photoName = 'profile_' . $profile['id'] . '_' . time() . '.' . $ext;
                move_uploaded_file($_FILES['photo']['tmp_name'], $uploadDir . $photoName);
            }

            $repo->updateProfile((int)$profile['id'], [
                'phone' => trim(Request::post('phone')),
                'address' => trim(Request::post('address')),
                'photo' => $photoName,
                'birth_date' => Request::post('birth_date')
            ]);
            Session::flash('success', 'Profil berhasil diperbarui.');
        }
        Response::redirect('?portal=karyawan&page=account');
    }

    public function uploadPkwt(): void
    {
        $user = Session::get('user');
        $repo = new EmployeeRepository();
        $profile = $repo->getProfileByUserId((int)$user['id']);

        if ($profile && isset($_FILES['pkwt_file']) && $_FILES['pkwt_file']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = __DIR__ . '/../../../storage/private/documents/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $ext = pathinfo($_FILES['pkwt_file']['name'], PATHINFO_EXTENSION);
            $filename = 'pkwt_signed_' . $profile['id'] . '_' . time() . '.' . $ext;
            
            if (move_uploaded_file($_FILES['pkwt_file']['tmp_name'], $uploadDir . $filename)) {
                $repo->uploadPkwt((int)$profile['id'], $filename);
                Session::flash('success', 'Dokumen PKWT berhasil diunggah.');
            }
        } else {
            Session::flash('error', 'Gagal mengunggah dokumen PKWT.');
        }
        Response::redirect('?portal=karyawan&page=account');
    }

    public function changePassword(): void
    {
        $user = Session::get('user');
        $current = Request::post('current_password');
        $new = Request::post('new_password');
        $confirm = Request::post('confirm_password');

        if ($new !== $confirm) {
            Session::flash('error', 'Password baru dan konfirmasi tidak cocok.');
            Response::redirect('?portal=karyawan&page=account');
            return;
        }

        $stmt = Database::pdo()->prepare("SELECT password_hash FROM users WHERE id = :uid");
        $stmt->execute(['uid' => $user['id']]);
        $hash = $stmt->fetchColumn();

        if (!password_verify($current, $hash)) {
            Session::flash('error', 'Password saat ini salah.');
            Response::redirect('?portal=karyawan&page=account');
            return;
        }

        $newHash = password_hash($new, PASSWORD_DEFAULT);
        $stmt = Database::pdo()->prepare("UPDATE users SET password_hash = :hash WHERE id = :uid");
        $stmt->execute(['hash' => $newHash, 'uid' => $user['id']]);

        Session::flash('success', 'Password berhasil diubah.');
        Response::redirect('?portal=karyawan&page=account');
    }

    public function inbox(): void
    {
        $user = Session::get('user');
        $notifications = Notifier::getByUser((int)$user['id']);
        View::render('employee/inbox', ['notifications' => $notifications], 'employee');
    }

    public function readNotification(): void
    {
        $user = Session::get('user');
        $id = (int)Request::get('id');
        if ($id) { Notifier::markAsRead($id, (int)$user['id']); }
        Response::redirect('?portal=karyawan&page=inbox');
    }

    public function readAllNotifications(): void
    {
        $user = Session::get('user');
        Notifier::markAllAsRead((int)$user['id']);
        Session::flash('success', 'Semua pesan telah ditandai dibaca.');
        Response::redirect('?portal=karyawan&page=inbox');
    }

    public function news(): void
    {
        $repo = new \App\Repositories\NewsRepository();
        $news = $repo->getAll();
        View::render('employee/news', ['news' => $news], 'employee');
    }

    public function ai(): void
    {
        // Buka akses untuk semua user
        View::render('employee/ai', [], 'employee');
    }

    public function askAi(): void
    {
        $user = Session::get('user');
        $question = trim(Request::post('question', ''));
        
        if ($question) {
            $answer = AiAssistant::ask((int)$user['id'], $question);
            Session::set('ai_answer', $answer);
            Session::set('ai_question', $question);
        }
        Response::redirect('?portal=karyawan&page=ai');
    }
}