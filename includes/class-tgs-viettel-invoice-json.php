<?php
// [2026-11-25 Võ Hoàng] Mã hoá JSON thân yêu cầu gửi Viettel, không phụ thuộc serialize_precision của php.ini (đóng góp)
/**
 * Máy có php.ini `serialize_precision = 17` (LocalWP mặc định, một số máy chủ cũ) in số thực đủ 17 chữ số:
 * đơn giá đã round(…, 2) = 300925.93 thành 300925.92999999999 → Viettel trả HTTP 400 INVALID_DECIMAL_POINT_PRICE
 * ("Đơn giá … phần thập phân tối đa 2 ký tự"). Giá trị -1 (mặc định PHP ≥ 7.1) in số ngắn nhất mà đọc lại vẫn đúng.
 *
 * Mọi thân yêu cầu JSON gửi Viettel (phát hành, gửi CQT, điều chỉnh, thay thế, PDF, email, portal) đi qua encode():
 * tạm đặt serialize_precision = -1, mã hoá, rồi trả lại giá trị cũ (try/finally).
 */

if (!defined('ABSPATH')) {
    exit;
}

final class TGS_Viettel_Invoice_Json
{
    /**
     * @param mixed $data
     * @param int   $flags Mặc định JSON_UNESCAPED_UNICODE như các chỗ gửi Viettel trước đây.
     * @return string|false Như wp_json_encode().
     */
    public static function encode($data, $flags = JSON_UNESCAPED_UNICODE)
    {
        $old = ini_get('serialize_precision');
        ini_set('serialize_precision', '-1');
        try {
            return wp_json_encode($data, $flags);
        } finally {
            if ($old !== false) {
                ini_set('serialize_precision', $old);
            }
        }
    }
}
