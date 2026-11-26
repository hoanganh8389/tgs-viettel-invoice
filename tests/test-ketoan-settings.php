<?php
/**
 * S-HD-4 — cài đặt hoá đơn điện tử kéo từ kế toán (THUE03) đè ô thường của cụm / cấu hình website; mật khẩu giữ ở website.
 * HTTP giả (TGS_Viettel_Invoice_Ketoan_Settings::$fetch) — không gọi kế toán, không gọi Viettel, không phát hành hoá đơn.
 * Chạy trên máy dev: php tests/test-ketoan-settings.php (blog TGS_TEST_BLOG, mặc định 2). Bản đệm của blog được trả lại như cũ cuối bài.
 */
// [2026-11-27 12:10 AM Võ Hoàng] S-HD-4 — test kéo cài đặt từ kế toán
if (php_sapi_name() !== 'cli') exit;
$_SERVER['HTTP_HOST'] = getenv('WP_HOST') ?: 'thegioisua.local';
$_SERVER['REQUEST_URI'] = '/';
require dirname(__DIR__, 4) . '/wp-load.php';
$pass = 0; $fail = 0;
function check($name, $ok, $extra = '') { global $pass, $fail; if ($ok) { $pass++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name $extra\n"; } }

$K = 'TGS_Viettel_Invoice_Ketoan_Settings';
check('lớp đã nạp', class_exists($K));
if (!class_exists($K)) { echo "\n$pass PASS, $fail FAIL\n"; exit(1); }

$blog = (int) (getenv('TGS_TEST_BLOG') ?: 2);
$opt = $K::OPTION_PREFIX . $blog;
$before = get_site_option($opt, null);
$calls = [];
$row = [
    'branchCode' => 'T048', 'trangThai' => 'dang-dung', 'active' => true,
    'mst' => '0100109106', 'tenPhapLy' => 'Công ty thử S-HD-4', 'diaChi' => 'Phú Thọ', 'dienThoai' => '0210000000',
    'apiBaseUrl' => 'https://vinvoice.test/services/einvoiceapplication/api', 'authMode' => 'basic', 'username' => 'shd4-user', 'sslVerify' => false,
    'templateCode' => '1/002', 'invoiceSeries' => 'C25TLT', 'templateFrom' => 'chi-nhanh', 'seriesFrom' => 'chi-nhanh',
    'paymentMethod' => 'TM/CK', 'autoEnabled' => false, 'autoMode' => 'draft',
    'portalEnabled' => true, 'portalAccount' => '0100109106-001_QT', 'portalSupplierId' => '102668',
];
$reply = ['data' => ['configured' => true, 'configVersion' => '3.1', 'secretNames' => [], 'chiNhanh' => [$row]]];
$K::$branch = function ($b) { return 'T048'; };
$K::$fetch = function ($b, $branch) use (&$calls, &$reply) { $calls[] = [$b, $branch]; return $reply; };

$website = [
    'company_name' => 'Tên cũ website', 'supplier_tax_code' => '0999999999', 'api_base_url' => 'https://cu.example/api',
    'username' => 'website-user', 'password' => 'MK-WEBSITE', 'access_token' => 'TOKEN-WEBSITE', 'portal_password' => 'MK-PORTAL-WEBSITE',
    'default_template_code' => '1/770', 'default_invoice_series' => 'K23TXM', 'verify_ssl' => 1, 'auto_enabled' => 1, 'auto_mode' => 'issue',
    '_cluster' => ['assigned' => true, 'id' => 9],
];

try {
    delete_site_option($opt);
    $o = $K::overlay($blog, $website);
    check('chưa kéo lần nào: giữ nguyên website', $o['company_name'] === 'Tên cũ website' && empty($o['_ketoan']['applied']));

    $r = $K::pull($blog);
    check('kéo thành công, đúng chi nhánh của blog', !empty($r['ok']) && $calls && $calls[0] === [$blog, 'T048'], wp_json_encode($r, JSON_UNESCAPED_UNICODE));
    $o = $K::overlay($blog, $website);
    check('ô thường lấy theo kế toán', $o['company_name'] === 'Công ty thử S-HD-4' && $o['supplier_tax_code'] === '0100109106'
        && $o['api_base_url'] === $row['apiBaseUrl'] && $o['username'] === 'shd4-user' && $o['default_template_code'] === '1/002'
        && $o['default_invoice_series'] === 'C25TLT' && $o['auto_mode'] === 'draft', wp_json_encode($o, JSON_UNESCAPED_UNICODE));
    check('cờ đúng kiểu 0/1', $o['verify_ssl'] === 0 && $o['auto_enabled'] === 0 && $o['portal_enabled'] === 1);
    check('portal: tài khoản + supplier từ kế toán', $o['portal_username'] === '0100109106-001_QT' && $o['portal_supplier_id'] === '102668');
    check('mật khẩu / token / mật khẩu portal GIỮ của website', $o['password'] === 'MK-WEBSITE' && $o['access_token'] === 'TOKEN-WEBSITE' && $o['portal_password'] === 'MK-PORTAL-WEBSITE');
    check('giữ _cluster, ghi _ketoan', !empty($o['_cluster']['assigned']) && !empty($o['_ketoan']['applied']) && $o['_ketoan']['branch'] === 'T048' && $o['_ketoan']['config_version'] === '3.1');
    check('bản đệm không chứa mật khẩu website', strpos(wp_json_encode(get_site_option($opt)), 'MK-WEBSITE') === false);

    // Kế toán lỗi mạng / 403: giữ bản đã kéo (P-0), ghi lỗi
    $reply = new WP_Error('FORBIDDEN', 'Không có quyền', ['status' => 403]);
    $r = $K::pull($blog);
    check('lỗi 403: báo thiếu phạm vi reports:read', empty($r['ok']) && strpos($r['message'], 'reports:read') !== false, $r['message'] ?? '');
    $o = $K::overlay($blog, $website);
    check('lỗi: vẫn dùng bản đã kéo, kèm lỗi gần nhất', $o['company_name'] === 'Công ty thử S-HD-4' && $o['_ketoan']['last_error'] !== '');

    // Sổ chưa "đang dùng" / chi nhánh ngừng: không đè
    $reply = ['data' => ['configured' => true, 'configVersion' => '4.1', 'chiNhanh' => [array_merge($row, ['trangThai' => 'nhap'])]]];
    $K::pull($blog);
    $o = $K::overlay($blog, $website);
    check('sổ ở kế toán còn nháp: dùng website', $o['company_name'] === 'Tên cũ website' && empty($o['_ketoan']['applied']) && strpos($o['_ketoan']['reason'], 'Đang dùng') !== false);
    $reply = ['data' => ['configured' => true, 'configVersion' => '4.2', 'chiNhanh' => [array_merge($row, ['active' => false])]]];
    $K::pull($blog);
    check('chi nhánh ngừng dùng ở kế toán: dùng website', empty($K::overlay($blog, $website)['_ketoan']['applied']));
    $reply = ['data' => ['configured' => false, 'configVersion' => '0.0', 'chiNhanh' => [$row]]];
    $K::pull($blog);
    check('kế toán chưa khai THUE03: dùng website', empty($K::overlay($blog, $website)['_ketoan']['applied']));

    // Ô kế toán để trống: giữ website
    $reply = ['data' => ['configured' => true, 'configVersion' => '5.1', 'chiNhanh' => [array_merge($row, ['dienThoai' => '', 'portalAccount' => ''])]]];
    $K::pull($blog);
    $o = $K::overlay($blog, array_merge($website, ['company_phone' => '0911', 'portal_username' => 'portal-website']));
    check('ô kế toán trống: giữ giá trị website', $o['company_phone'] === '0911' && $o['portal_username'] === 'portal-website');

    // Blog chưa có mã chi nhánh
    $K::$branch = function ($b) { return ''; };
    $r = $K::pull($blog);
    check('blog chưa có mã chi nhánh: báo rõ, không gọi kế toán', empty($r['ok']) && strpos($r['message'], 'mã chi nhánh') !== false);

    // Đường thật qua resolver của plugin (cụm / legacy → đè kế toán)
    $K::$branch = function ($b) { return 'T048'; };
    $reply = ['data' => ['configured' => true, 'configVersion' => '6.1', 'chiNhanh' => [$row]]];
    $K::pull($blog);
    $s = TGS_Viettel_Invoice_Plugin::get_settings($blog);
    check('get_settings() của plugin trả cài đặt kế toán', ($s['supplier_tax_code'] ?? '') === '0100109106' && !empty($s['_ketoan']['applied']), wp_json_encode(array_intersect_key($s, ['supplier_tax_code' => 1, '_ketoan' => 1]), JSON_UNESCAPED_UNICODE));
} finally {
    $K::$fetch = null;
    $K::$branch = null;
    if ($before === null) { delete_site_option($opt); } else { update_site_option($opt, $before); }
}
echo "\n$pass PASS, $fail FAIL\n";
exit($fail ? 1 : 0);
