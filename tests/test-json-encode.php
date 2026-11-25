<?php
/**
 * [2026-11-25 Võ Hoàng] Kiểm TGS_Viettel_Invoice_Json::encode — thân yêu cầu gửi Viettel không phụ thuộc serialize_precision (đóng góp).
 *
 * Lỗi thật (Viettel DEMO): php.ini `serialize_precision = 17` (LocalWP mặc định) → đơn giá round(…, 2) = 300925.93 bị in
 * 300925.92999999999 → HTTP 400 INVALID_DECIMAL_POINT_PRICE. Không cần WordPress, không gọi mạng.
 *
 * Chạy: php tests/test-json-encode.php
 * Kiểm cả luồng phát hành / gửi lại / điều chỉnh với HTTP giả: tgs_pos/tests/test-viettel-dich-vu.php.
 */
if (php_sapi_name() !== 'cli') exit;
if (!defined('ABSPATH')) define('ABSPATH', __DIR__ . '/');
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $options = 0, $depth = 512) { return json_encode($data, $options, $depth); }
}
require_once dirname(__DIR__) . '/includes/class-tgs-viettel-invoice-json.php';

$pass = 0; $fail = 0;
function check($name, $ok, $extra = '') { global $pass, $fail; if ($ok) { $pass++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name $extra\n"; } }

$payload = [
    'itemInfo' => [['itemName' => 'Biomil Plus 1 400g', 'unitPrice' => round(300925.93, 2), 'quantity' => 1, 'taxPercentage' => 8]],
    'summarizeInfo' => ['totalAmountWithoutTax' => 300926, 'totalTaxAmount' => 24074],
];

foreach (['17', '-1', '14'] as $p) {
    ini_set('serialize_precision', $p);
    $out = TGS_Viettel_Invoice_Json::encode($payload);
    check("serialize_precision=$p: unitPrice ra \"300925.93\"", strpos($out, '"unitPrice":300925.93,') !== false, $out);
    check("serialize_precision=$p: trả lại giá trị cũ", ini_get('serialize_precision') === $p, ini_get('serialize_precision'));
}

ini_set('serialize_precision', '17');
check('serialize_precision=17: json_encode thường in số dài (lỗi tái hiện)', strpos(json_encode($payload), '300925.92999') !== false, json_encode($payload));
check('giữ tiếng Việt không escape (JSON_UNESCAPED_UNICODE mặc định)', TGS_Viettel_Invoice_Json::encode(['n' => 'Sữa']) === '{"n":"Sữa"}');
check('cờ truyền vào được dùng (0 → escape Unicode)', TGS_Viettel_Invoice_Json::encode(['n' => 'Sữa'], 0) === json_encode(['n' => 'Sữa']) && strpos(TGS_Viettel_Invoice_Json::encode(['n' => 'Sữa'], 0), 'Sữa') === false);
check('đọc lại ra đúng số', json_decode(TGS_Viettel_Invoice_Json::encode($payload), true)['itemInfo'][0]['unitPrice'] === 300925.93);

echo "\n$pass PASS, $fail FAIL\n";
exit($fail ? 1 : 0);
