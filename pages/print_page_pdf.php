<?php
/**
 * 🖨️📧 (2026-11-20 «البطاقة السنوية: وقت بطبعها دغري على البرنتر ممتازة، بس أبعتها إيميل أو أحفظها شوف كيف بتطلع»)
 * ورقة الطباعة نفسها ⇒ PDF حقيقي: المتصفّح (pdf-save.js printPagePdf) يبعث الصفحة جاهزة للطباعة، ومحرّك Chromium
 * (MsaChromePdf::printPagePdf) يرسمها بوسط الطباعة — نفس ما يطلع على البرنتر. تُستعمل لـ«احفظ عالكمبيوتر» والإيميل والواتساب بالبطاقة السنوية.
 * لا حساب ولا قراءة داتا هنا: ما يصل هو ما على الشاشة. أي فشل ⇒ رمز خطأ والمتصفّح يرجع للطريقة القديمة (تصوير).
 *   POST: csrf · html (ملف أو نصّ)
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrf($_POST['csrf'] ?? '')) { http_response_code(403); die('forbidden'); }

$html = '';
if (!empty($_FILES['html']['tmp_name']) && is_uploaded_file($_FILES['html']['tmp_name'])) {
    if ((int)$_FILES['html']['size'] > 25 * 1048576) { http_response_code(413); die('too large'); }
    $html = (string)file_get_contents($_FILES['html']['tmp_name']);
} else {
    $html = (string)($_POST['html'] ?? '');
}
if (trim($html) === '' || strpos($html, 'salary-slip') === false) { http_response_code(400); die('no page'); }

session_write_close();   // لا نحجز الجلسة أثناء الرسم (ثوانٍ)
require_once __DIR__ . '/../includes/pdf_chromium.php';
try {
    if (!MsaChromePdf::available()) throw new RuntimeException('pdf service not available');
    @set_time_limit(180);
    $pdf = MsaChromePdf::printPagePdf($html);
} catch (Throwable $e) {
    error_log('print_page_pdf: ' . $e->getMessage());
    http_response_code(503); die('pdf service failed');
}
while (ob_get_level()) ob_end_clean();
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="releve_annuel.pdf"');
header('Content-Length: ' . strlen($pdf));
header('Cache-Control: no-store');
echo $pdf;
