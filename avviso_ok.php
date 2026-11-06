<?php
require __DIR__ . '/includes/bootstrap.php';

$user = require_login();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = post_str('id');
    $a = $id !== '' ? find_one_by('avvisi', id_filter($id)) : null;
    if ($a && (string)$a['user_id'] === (string)$user['id']) {
        col('avvisi')->updateOne(id_filter($a['id']), ['$set' => ['letto' => true, 'letto_at' => time()]]);
    }
}
$back = post_str('back');
redirect(preg_match('/^[a-z_]+\.php$/', $back) && is_file(__DIR__ . '/' . $back) && $back !== 'avviso_ok.php' ? $back : 'dashboard.php');
