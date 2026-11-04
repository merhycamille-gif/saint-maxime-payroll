<?php
/**
 * 📬 حالة وصول الإيميل (2026-11-04 «أوقات بيوصل أوقات ما بيوصل… بدّي الإيميل يوصل بسرعة»):
 *  ?id=<رقم الصفّ بسجلّ الإيميلات>  ⇒ يسأل خدمة الإرسال عن هذه الرسالة الآن ويرجع JSON {ok, status, label, final, to} — تستعمله نافذة الإرسال لتقول «✅ وصل»
 *  ?auto=1                         ⇒ الجولة التلقائية للمعلّقة (كل 10 دقائق، من النبض الخلفي بـfooter.php) — يرجع {refreshed}
 *  محميّ بتسجيل الدخول. الجلسة تُقفَل للقراءة أوّلاً حتى لا يعلّق تنقّل المستخدم أثناء سؤال الخدمة.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';
requireLogin();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
session_write_close();
@set_time_limit(60);
$id = preg_replace('/[^0-9a-f]/', '', (string)($_GET['id'] ?? ''));
if ($id !== '') { echo json_encode(msaMailLogCheckOne($id), JSON_UNESCAPED_UNICODE); exit; }
if (isset($_GET['auto'])) { echo json_encode(['ok' => true, 'refreshed' => msaMailLogAutoRefresh()]); exit; }
echo json_encode(['ok' => false, 'label' => 'طلب غير صالح'], JSON_UNESCAPED_UNICODE);
