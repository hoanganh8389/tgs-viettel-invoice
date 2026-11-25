<?php
/**
 * "Cờ dòng" (hàng < 24 tháng nguy hiểm) ở màn Gửi thuế: lưu được khi gửi JSON đã slash như form (trước đây luôn "Không có cờ nào"),
 * nhận cả mảng, và chỉ sửa dòng thuộc chính đơn. Chạy trên máy dev, blog 2, cần một đơn có dòng hàng: TGS_TEST_SALE_ID (mặc định 455).
 * Mọi thay đổi trong giao dịch, ROLLBACK cuối bài.
 */
if (php_sapi_name() !== 'cli') exit;
$_SERVER['HTTP_HOST'] = getenv('WP_HOST') ?: 'thegioisua.local';
$_SERVER['REQUEST_URI'] = '/';
require dirname(__DIR__, 4) . '/wp-load.php';
global $wpdb;
$pass = 0; $fail = 0;
function check($name, $ok, $extra = '') { global $pass, $fail; if ($ok) { $pass++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name $extra\n"; } }

switch_to_blog(2);
wp_set_current_user(1);
$sale = (int) (getenv('TGS_TEST_SALE_ID') ?: 455);
$own = json_decode((string) $wpdb->get_var($wpdb->prepare('SELECT local_ledger_item_id FROM ' . TGS_TABLE_LOCAL_LEDGER . ' WHERE local_ledger_id = %d', $sale)), true);
$own = is_array($own) ? array_map('intval', $own) : [];
check('đơn thử có dòng hàng', !empty($own), "sale $sale");
$item = $own[0] ?? 0;
$other = (int) $wpdb->get_var($wpdb->prepare('SELECT local_ledger_item_id FROM ' . TGS_TABLE_LOCAL_LEDGER_ITEM . ' WHERE local_ledger_item_id NOT IN (' . implode(',', $own ?: [0]) . ') AND (is_deleted = 0 OR is_deleted IS NULL) ORDER BY local_ledger_item_id DESC LIMIT %d', 1));
$col = 'local_ledger_item_is_under24_promo_danger';
$get = fn ($id) => (int) $wpdb->get_var($wpdb->prepare("SELECT {$col} FROM " . TGS_TABLE_LOCAL_LEDGER_ITEM . ' WHERE local_ledger_item_id = %d', $id));
$plugin = class_exists('TGS_Viettel_Invoice_Plugin') ? TGS_Viettel_Invoice_Plugin::instance() : null;

$wpdb->query('START TRANSACTION');
try {
    $other_before = $other ? $get($other) : 0;
    // Như form AJAX: JSON đã slash (wp_magic_quotes).
    $in = wp_slash(['sale_ledger_id' => (string) $sale, 'item_flags' => wp_json_encode([['item_id' => $item, 'danger_flag' => 1], ['item_id' => $other, 'danger_flag' => 1]])]);
    $r = TGS_Viettel_Invoice_Pos_Service::update_danger_flags($sale, [['item_id' => $item, 'danger_flag' => 1], ['item_id' => $other, 'danger_flag' => 1]]);
    $data = is_wp_error($r) ? ['err' => $r->get_error_message()] : $r;
    check('dịch vụ (mảng): cập nhật 1 dòng của đơn, bỏ qua 1 dòng đơn khác', ($data['updated'] ?? null) === 1 && ($data['skipped'] ?? null) === 1, wp_json_encode($data, JSON_UNESCAPED_UNICODE));
    check('dòng của đơn đã bật cờ', $get($item) === 1);
    check('dòng của đơn khác không bị đụng', $other === 0 || $get($other) === $other_before);
    if ($plugin && method_exists($plugin, 'core_pos_update_danger_flags')) {
        $res = $plugin->core_pos_update_danger_flags(wp_slash(['sale_ledger_id' => (string) $sale, 'item_flags' => wp_json_encode([['item_id' => $item, 'danger_flag' => 0]])]));
        check('lõi AJAX (JSON đã slash): lưu được, không còn "Không có cờ nào"', ($res['data']['updated'] ?? null) === 1, wp_json_encode($res, JSON_UNESCAPED_UNICODE));
        check('dòng đã tắt cờ', $get($item) === 0);
    } else {
        echo "BỎ QUA phần lõi AJAX: không tìm thấy đối tượng plugin\n";
    }
} finally {
    $wpdb->query('ROLLBACK');
}
restore_current_blog();
echo "\n$pass PASS, $fail FAIL\n";
exit($fail ? 1 : 0);
