<?php
namespace App\Controllers\Management;

use App\Core\View;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\NewsRepository;
use App\Services\AuditLogger;

class NewsController
{
    public function index(): void
    {
        $repo = new NewsRepository();
        $news = $repo->getAll();
        View::render('management/news', ['news' => $news], 'management');
    }

    public function store(): void
    {
        $title = trim(Request::post('title'));
        $content = trim(Request::post('content'));

        if ($title && $content) {
            $repo = new NewsRepository();
            $repo->create($title, $content);
            AuditLogger::log('CREATE_NEWS', "Membuat berita: $title");
            Session::flash('success', 'Berita berhasil diterbitkan.');
        } else {
            Session::flash('error', 'Judul dan isi berita wajib diisi.');
        }

        Response::redirect('?portal=manajemen&page=news');
    }
}